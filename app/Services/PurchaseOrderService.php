<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\PurchaseRepository;
use App\Security\Auth;

/**
 * Achats (cahier des charges § 6 et § 5.1) : comparaison des devis, commande référencée à l'EDB approuvé,
 * réception avec contrôle quantité / qualité. Seule une réception justifiée fait avancer l'exécution de l'EDB.
 */
final class PurchaseOrderService
{
    public const ENTITY = 'purchase_order';

    public function __construct(
        private readonly Database $db,
        private readonly PurchaseRepository $purchases,
        private readonly PurchaseRequestService $requests,
        private readonly StockService $stock,
        private readonly NumberingService $numbering,
        private readonly AttachmentService $attachments,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    public function addQuote(int $requestId, array $input): int
    {
        $data = Validator::validate($input, [
            'supplier_id' => 'required|int',
            'reference' => 'string|max:60',
            'amount' => 'required|numeric|min:0.01|max:100000000000',
            'delivery_days' => 'int|min:0|max:3650',
            'note' => 'string|max:255',
        ]);

        return $this->db->transaction(function () use ($requestId, $data): int {
            $request = $this->approved($requestId);
            $this->supplier($data['supplier_id']);
            $id = $this->purchases->addQuote($data + ['request_id' => $requestId, 'created_by' => $this->auth->user()?->id]);
            $this->logger->log('quote_add', PurchaseRequestService::ENTITY, $requestId, (int) $request['site_id'], null, $data, $request['reference']);

            return $id;
        });
    }

    /** Retient un devis. Si ce n'est pas le moins cher, le choix doit être motivé. */
    public function selectQuote(int $requestId, array $input): void
    {
        $data = Validator::validate($input, ['quote_id' => 'required|int', 'selection_reason' => 'string|max:255']);
        $this->db->transaction(function () use ($requestId, $data): void {
            $request = $this->approved($requestId);
            $quotes = $this->purchases->quotes($requestId);
            $chosen = null;
            foreach ($quotes as $quote) {
                if ((int) $quote['id'] === $data['quote_id']) {
                    $chosen = $quote;
                }
            }
            if ($chosen === null) {
                throw Validator::fail('quote_id', 'exists');
            }
            $cheapest = min(array_map(static fn (array $q): float => (float) $q['amount'], $quotes));
            if ((float) $chosen['amount'] > $cheapest + 0.005 && $data['selection_reason'] === null) {
                throw Validator::fail('selection_reason', 'quote_reason');
            }
            $this->purchases->selectQuote($requestId, $data['quote_id'], $data['selection_reason']);
            $this->logger->log('quote_select', PurchaseRequestService::ENTITY, $requestId, (int) $request['site_id'], null,
                ['supplier' => $chosen['supplier'], 'amount' => $chosen['amount'], 'reason' => $data['selection_reason']], $request['reference']);
        });
    }

    /**
     * Commande au fournisseur du devis retenu. Lignes : lines[<id ligne EDB>] = ['quantity' => …, 'unit_price' => …].
     * On ne commande pas plus que ce qui reste à sourcer (ni servi, ni en transfert, ni déjà en commande).
     */
    public function createOrder(int $requestId, array $input): int
    {
        $data = Validator::validate($input, ['expected_on' => 'date', 'note' => 'string|max:255', 'lines' => 'required|array']);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');

        return $this->db->transaction(function () use ($requestId, $data, $user): int {
            $request = $this->approved($requestId);
            $quote = null;
            foreach ($this->purchases->quotes($requestId) as $candidate) {
                if ((int) $candidate['is_selected'] === 1) {
                    $quote = $candidate;
                }
            }
            if ($quote === null) {
                throw Validator::fail('lines', 'order_needs_quote');
            }
            $byId = array_column($this->purchases->lines($requestId), null, 'id');
            $lines = [];
            $total = 0.0;
            foreach ($data['lines'] as $lineId => $row) {
                $qty = self::number(is_array($row) ? ($row['quantity'] ?? null) : null);
                if ($qty === null || $qty <= 0) {
                    continue;
                }
                $line = $byId[(int) $lineId] ?? throw Validator::fail('lines', 'exists');
                $price = self::number($row['unit_price'] ?? null);
                if ($price === null || $price < 0) {
                    throw Validator::fail('lines', 'order_price', ['line' => $line['description']]);
                }
                if ($qty > (float) $line['qty_to_source'] + 0.0001) {
                    throw Validator::fail('lines', 'order_over_need', ['line' => $line['description'], 'max' => self::plain((float) $line['qty_to_source'])]);
                }
                $lines[] = ['request_line_id' => (int) $lineId, 'quantity' => round($qty, 2), 'unit_price' => round($price, 4)];
                $total += $qty * $price;
            }
            if ($lines === []) {
                throw Validator::fail('lines', 'required');
            }
            $reference = $this->numbering->next('BC');
            $id = $this->purchases->createOrder([
                'reference' => $reference,
                'request_id' => $requestId,
                'site_id' => (int) $request['site_id'],
                'supplier_id' => (int) $quote['supplier_id'],
                'quote_id' => (int) $quote['id'],
                'total_amount' => round($total, 2),
                'expected_on' => $data['expected_on'],
                'note' => $data['note'],
                'ordered_by' => $user->id,
            ], $lines);
            $this->logger->log('create', self::ENTITY, $id, (int) $request['site_id'], null,
                ['reference' => $reference, 'request' => $request['reference'], 'supplier' => $quote['supplier'], 'total_amount' => round($total, 2)], $reference);

            return $id;
        });
    }

    /** Annulation motivée d'une commande dont rien n'a été reçu : le besoin redevient à sourcer. */
    public function cancelOrder(int $orderId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw Validator::fail('cancel_reason', 'required');
        }
        $this->db->transaction(function () use ($orderId, $reason): void {
            $order = $this->purchases->findOrder($orderId, true) ?? throw HttpException::notFound();
            if ($order['status'] !== 'issued') {
                throw Validator::fail('status', 'order_not_cancellable');
            }
            $this->purchases->setOrderStatus($orderId, 'cancelled', mb_substr($reason, 0, 255));
            $this->logger->log('transition', self::ENTITY, $orderId, (int) $order['site_id'], ['status' => 'issued'], ['status' => 'cancelled', 'reason' => $reason], $order['reference']);
        });
    }

    /**
     * Bon de réception. accepted[<id ligne commande>] / rejected[…] / reject_reason[…] ; bon de livraison obligatoire.
     * Les articles acceptés entrent en stock au prix de la commande ; l'EDB avance d'autant. Un refus qualité
     * ne satisfait rien : la quantité reste due par le fournisseur.
     *
     * @param array|null $file justificatif numérisé (bon de livraison) — entrée de $_FILES
     * @return int identifiant du bon de réception
     */
    public function receive(int $orderId, array $input, ?array $file = null): int
    {
        $data = Validator::validate($input, [
            'delivery_note' => 'required|string|max:60',
            'carrier_id' => 'int',
            'vehicle_plate' => 'string|max:30',
            'driver_name' => 'string|max:120',
            'note' => 'string|max:255',
            'accepted' => 'array',
            'rejected' => 'array',
            'reject_reason' => 'array',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');

        return $this->db->transaction(function () use ($orderId, $data, $user, $file): int {
            $order = $this->purchases->findOrder($orderId, true) ?? throw HttpException::notFound();
            $siteId = (int) $order['site_id'];
            if (!$user->canAt('stock.move', $siteId)) {
                throw HttpException::forbidden();
            }
            if (!in_array($order['status'], ['issued', 'partially_received'], true)) {
                throw Validator::fail('status', 'order_closed');
            }
            // Séparation des fonctions : celui qui a passé la commande ne la réceptionne pas.
            if ((int) ($order['ordered_by'] ?? 0) === $user->id) {
                throw Validator::fail('status', 'receipt_same_actor');
            }
            if ($data['carrier_id'] !== null && !$this->db->fetchValue('SELECT 1 FROM carriers WHERE id = :id', ['id' => $data['carrier_id']])) {
                throw Validator::fail('carrier_id', 'exists');
            }
            $lines = [];
            $outstanding = 0.0;
            foreach ($this->purchases->orderLines($orderId) as $line) {
                $id = (int) $line['id'];
                $accepted = self::number($data['accepted'][$id] ?? null) ?? 0.0;
                $rejected = self::number($data['rejected'][$id] ?? null) ?? 0.0;
                $reason = trim((string) (is_scalar($data['reject_reason'][$id] ?? null) ? $data['reject_reason'][$id] : ''));
                if ($accepted < 0 || $rejected < 0 || $accepted > (float) $line['qty_outstanding'] + 0.0001) {
                    throw Validator::fail('accepted', 'receipt_over_ordered', ['line' => $line['description'], 'max' => self::plain((float) $line['qty_outstanding'])]);
                }
                if ($rejected > 0 && $reason === '') {
                    throw Validator::fail('reject_reason', 'receipt_reject_reason', ['line' => $line['description']]);
                }
                $outstanding += (float) $line['qty_outstanding'] - $accepted;
                if ($accepted > 0 || $rejected > 0) {
                    $lines[] = ['order_line_id' => $id, 'accepted' => round($accepted, 2), 'rejected' => round($rejected, 2),
                        'reject_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null, 'line' => $line];
                }
            }
            if ($lines === []) {
                throw Validator::fail('accepted', 'receipt_empty');
            }
            $reference = $this->numbering->next('BR');
            $receiptId = $this->purchases->addReceipt([
                'reference' => $reference,
                'order_id' => $orderId,
                'site_id' => $siteId,
                'delivery_note' => $data['delivery_note'],
                'carrier_id' => $data['carrier_id'],
                'vehicle_plate' => $data['vehicle_plate'],
                'driver_name' => $data['driver_name'],
                'note' => $data['note'],
                'received_by' => $user->id,
            ], $lines);
            if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->attachments->add('goods_receipt', $receiptId, $siteId, $file, 'delivery_note');
            }
            foreach ($lines as $entry) {
                if ($entry['accepted'] <= 0) {
                    continue;
                }
                $line = $entry['line'];
                $price = (float) $line['unit_price'];
                if ($line['part_id'] !== null) {
                    $this->stock->move((int) $line['part_id'], $siteId, 'receipt', $entry['accepted'], $order['reference'] . ' / BL ' . $data['delivery_note'], null, null,
                        ['unit_cost' => $price, 'document_ref' => $reference, 'supplier_id' => (int) $order['supplier_id']]);
                }
                $this->requests->fulfil((int) $order['request_id'], (int) $line['request_line_id'], 'purchase', $entry['accepted'], round($entry['accepted'] * $price, 2), $reference);
            }
            $this->purchases->setOrderStatus($orderId, $outstanding <= 0.0001 ? 'received' : 'partially_received');
            $this->logger->log('goods_receipt', self::ENTITY, $orderId, $siteId, ['status' => $order['status']],
                ['status' => $outstanding <= 0.0001 ? 'received' : 'partially_received', 'receipt' => $reference, 'delivery_note' => $data['delivery_note'],
                    'outstanding' => $outstanding], $order['reference']);

            return $receiptId;
        });
    }

    private function approved(int $requestId): array
    {
        $request = $this->purchases->find($requestId, true) ?? throw HttpException::notFound();
        if ($request['status'] !== 'approved') {
            throw Validator::fail('status', 'edb_not_approved');
        }

        return $request;
    }

    private function supplier(int $id): void
    {
        if (!$this->db->fetchValue('SELECT 1 FROM suppliers WHERE id = :id AND is_active = 1', ['id' => $id])) {
            throw Validator::fail('supplier_id', 'exists');
        }
    }

    private static function number(mixed $value): ?float
    {
        $text = is_scalar($value) ? trim(str_replace(',', '.', (string) $value)) : '';

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
