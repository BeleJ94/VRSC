<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\PartRepository;
use App\Repositories\StockDocumentRepository;
use App\Security\Auth;

/**
 * Transfert intermagasins (cahier des charges § 5) :
 * demande du bénéficiaire → contrôle de disponibilité → autorisation Logistique → bon numéroté → sortie
 * (stock « en transit ») → réception contradictoire → rapprochement des écarts.
 *
 * La quantité expédiée quitte le magasin d'origine à la sortie et n'entre dans le magasin de destination
 * qu'à la réception : entre les deux elle n'est comptée qu'une fois, en transit.
 */
final class StockTransferService
{
    public const ENTITY = 'stock_transfer';

    public function __construct(
        private readonly Database $db,
        private readonly StockDocumentRepository $documents,
        private readonly PartRepository $parts,
        private readonly StockService $stock,
        private readonly ApprovalService $approvals,
        private readonly NumberingService $numbering,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Demande de transfert. Lignes : [['part_id' => …, 'quantity' => …], …].
     *
     * @return int identifiant du transfert
     */
    public function request(array $input): int
    {
        $data = Validator::validate($input, [
            'from_site_id' => 'required|int',
            'to_site_id' => 'required|int',
            'reason' => 'required|string|max:255',
            'carrier_id' => 'int',
            'lines' => 'required|array',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        if ($data['from_site_id'] === $data['to_site_id']) {
            throw Validator::fail('to_site_id', 'transfer_same_site');
        }
        foreach (['from_site_id', 'to_site_id'] as $field) {
            if (!$this->db->fetchValue('SELECT 1 FROM sites WHERE id = :id AND is_active = 1', ['id' => $data[$field]])) {
                throw Validator::fail($field, 'exists');
            }
        }
        // La demande émane du magasin d'origine ou du bénéficiaire ; elle n'autorise pas la sortie.
        $approvalSite = $user->canAt('stock.transfer', $data['to_site_id']) ? $data['to_site_id']
            : ($user->canAt('stock.transfer', $data['from_site_id']) ? $data['from_site_id'] : throw HttpException::forbidden());
        if ($data['carrier_id'] !== null && !$this->db->fetchValue('SELECT 1 FROM carriers WHERE id = :id', ['id' => $data['carrier_id']])) {
            throw Validator::fail('carrier_id', 'exists');
        }
        $lines = $this->lines($data['lines'], $data['from_site_id']);

        return $this->db->transaction(function () use ($data, $lines, $user, $approvalSite): int {
            $reference = $this->numbering->next('BT');
            $id = $this->documents->createTransfer([
                'reference' => $reference,
                'from_site_id' => $data['from_site_id'],
                'to_site_id' => $data['to_site_id'],
                'reason' => $data['reason'],
                'carrier_id' => $data['carrier_id'],
                'requested_by' => $user->id,
            ], $lines);
            $this->logger->log('create', self::ENTITY, $id, $data['to_site_id'], null,
                ['reference' => $reference, 'from_site_id' => $data['from_site_id'], 'to_site_id' => $data['to_site_id'], 'lines' => $lines], $reference);
            $approval = $this->approvals->start('stock_transfer', self::ENTITY, $id, $approvalSite, null, $reference);
            if ($approval['status'] === 'approved') {
                $this->onApproval($id, 'approved');
            }

            return $id;
        });
    }

    /** Issue du circuit d'approbation : autorisé (quantités réservées à l'origine), refusé ou annulé. */
    public function onApproval(int $id, string $status): void
    {
        $transfer = $this->db->fetchOne('SELECT * FROM stock_transfers WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($transfer === null || $transfer['status'] !== 'requested') {
            return;
        }
        if ($status === 'approved') {
            foreach ($this->documents->transferLines($id) as $line) {
                $this->stock->reserve((int) $line['part_id'], (int) $transfer['from_site_id'], (float) $line['qty_requested'], self::ENTITY, $id);
            }
            $this->documents->updateTransfer($id, ['status' => 'authorized', 'authorized_at' => date('Y-m-d H:i:s')]);
        } elseif (in_array($status, ['rejected', 'cancelled'], true)) {
            $this->documents->updateTransfer($id, ['status' => $status]);
        }
        $this->logger->log('transition', self::ENTITY, $id, (int) $transfer['to_site_id'], ['status' => 'requested'],
            ['status' => $status === 'approved' ? 'authorized' : $status], $transfer['reference']);
    }

    /** Sortie du magasin d'origine : la marchandise passe en transit. Quantités : [part_id => quantité expédiée]. */
    public function ship(int $id, array $input): void
    {
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $input, $user): void {
            $transfer = $this->documents->findTransfer($id, true) ?? throw HttpException::notFound();
            $from = (int) $transfer['from_site_id'];
            if (!$user->canAt('stock.move', $from)) {
                throw HttpException::forbidden();
            }
            if ($transfer['status'] !== 'authorized') {
                // Une demande, même approuvée ailleurs, ne vaut pas bon de sortie tant qu'elle n'est pas autorisée.
                throw Validator::fail('status', 'transfer_not_authorized');
            }
            $quantities = is_array($input['shipped'] ?? null) ? $input['shipped'] : [];
            $this->stock->releaseReservations(self::ENTITY, $id, true);
            $total = 0.0;
            foreach ($this->documents->transferLines($id) as $line) {
                $partId = (int) $line['part_id'];
                $qty = self::quantity($quantities[$partId] ?? $line['qty_requested']);
                if ($qty < 0 || $qty > (float) $line['qty_requested']) {
                    throw Validator::fail('quantity', 'transfer_over_requested', ['max' => self::plain((float) $line['qty_requested'])]);
                }
                $cost = $this->stock->costOf($partId, $from);
                if ($qty > 0) {
                    $this->stock->move($partId, $from, 'transfer_out', $qty, $transfer['reference'] . ' → ' . $transfer['to_site'], null, null,
                        ['document_ref' => $transfer['reference'], 'transfer_id' => $id]);
                    // La ligne de stock de destination existe dès l'expédition : le transit y est rattaché.
                    $this->db->execute('INSERT IGNORE INTO part_stocks (part_id, site_id, quantity) VALUES (:p, :s, 0)', ['p' => $partId, 's' => $transfer['to_site_id']]);
                }
                $this->documents->updateTransferLine((int) $line['id'], ['qty_shipped' => $qty, 'unit_cost' => $cost]);
                $total += $qty;
            }
            if ($total <= 0) {
                throw Validator::fail('quantity', 'positive');
            }
            $carrier = isset($input['carrier_id']) && is_numeric($input['carrier_id']) ? (int) $input['carrier_id'] : $transfer['carrier_id'];
            $this->documents->updateTransfer($id, ['status' => 'shipped', 'shipped_by' => $user->id, 'shipped_at' => date('Y-m-d H:i:s'), 'carrier_id' => $carrier]);
            $this->logger->log('transition', self::ENTITY, $id, $from, ['status' => 'authorized'], ['status' => 'shipped'], $transfer['reference']);
        });
    }

    /**
     * Réception contradictoire au magasin de destination. Quantités : [part_id => quantité reçue].
     * Un écart avec la quantité expédiée doit être expliqué ; il reste tracé sur le transfert.
     */
    public function receive(int $id, array $input): void
    {
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $note = trim((string) ($input['discrepancy_note'] ?? ''));
        $this->db->transaction(function () use ($id, $input, $user, $note): void {
            $transfer = $this->documents->findTransfer($id, true) ?? throw HttpException::notFound();
            $to = (int) $transfer['to_site_id'];
            if (!$user->canAt('stock.move', $to)) {
                throw HttpException::forbidden();
            }
            if ($transfer['status'] !== 'shipped') {
                throw Validator::fail('status', 'transfer_not_shipped');
            }
            // Séparation des fonctions : celui qui a expédié ne réceptionne pas.
            if ($transfer['shipped_by'] !== null && (int) $transfer['shipped_by'] === $user->id) {
                throw Validator::fail('status', 'transfer_same_actor');
            }
            $quantities = is_array($input['received'] ?? null) ? $input['received'] : [];
            $discrepancy = false;
            foreach ($this->documents->transferLines($id) as $line) {
                $partId = (int) $line['part_id'];
                $shipped = (float) $line['qty_shipped'];
                $qty = self::quantity($quantities[$partId] ?? $shipped);
                if ($qty < 0 || $qty > $shipped) {
                    throw Validator::fail('quantity', 'transfer_over_shipped', ['max' => self::plain($shipped)]);
                }
                if ($qty > 0) {
                    $this->stock->move($partId, $to, 'transfer_in', $qty, $transfer['reference'] . ' ← ' . $transfer['from_site'], null, null, [
                        'document_ref' => $transfer['reference'], 'transfer_id' => $id,
                        'unit_cost' => $line['unit_cost'] !== null ? (float) $line['unit_cost'] : null,
                    ]);
                }
                $this->documents->updateTransferLine((int) $line['id'], ['qty_received' => $qty]);
                $discrepancy = $discrepancy || abs($qty - $shipped) > 0.0001;
            }
            if ($discrepancy && $note === '') {
                throw Validator::fail('discrepancy_note', 'transfer_discrepancy_note');
            }
            $this->documents->updateTransfer($id, [
                'status' => 'received', 'received_by' => $user->id, 'received_at' => date('Y-m-d H:i:s'),
                'has_discrepancy' => $discrepancy ? 1 : 0, 'discrepancy_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            ]);
            $this->logger->log('transition', self::ENTITY, $id, $to, ['status' => 'shipped'],
                ['status' => 'received', 'discrepancy' => $discrepancy ? $note : null], $transfer['reference']);
        });
    }

    /** Annulation motivée avant expédition : la demande d'approbation est close et les réservations levées. */
    public function cancel(int $id, string $reason): void
    {
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $reason = trim($reason);
        if ($reason === '') {
            throw Validator::fail('comment', 'required');
        }
        $this->db->transaction(function () use ($id, $reason, $user): void {
            $transfer = $this->documents->findTransfer($id, true) ?? throw HttpException::notFound();
            $own = $transfer['requested_by'] !== null && (int) $transfer['requested_by'] === $user->id;
            if (!$own && !$user->canAt('approvals.override', (int) $transfer['to_site_id']) && !$user->canAt('approvals.override', (int) $transfer['from_site_id'])) {
                throw HttpException::forbidden();
            }
            if ($transfer['status'] === 'requested') {
                $request = $this->approvals->forEntity(self::ENTITY, $id);
                if ($request !== null && $request['status'] === 'pending') {
                    $this->approvals->cancel((int) $request['id'], $reason);
                }
            } elseif ($transfer['status'] === 'authorized') {
                $this->stock->releaseReservations(self::ENTITY, $id, false);
            } else {
                throw Validator::fail('status', 'transfer_not_cancellable');
            }
            $this->documents->updateTransfer($id, ['status' => 'cancelled']);
            $this->logger->log('transition', self::ENTITY, $id, (int) $transfer['to_site_id'], ['status' => $transfer['status']],
                ['status' => 'cancelled', 'reason' => mb_substr($reason, 0, 255)], $transfer['reference']);
        });
    }

    /**
     * Lignes valides : article actif, quantité positive, disponible au magasin d'origine (stock moins réservé).
     *
     * @return list<array{part_id: int, quantity: float}>
     */
    private function lines(array $rows, int $fromSiteId): array
    {
        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['part_id'] ?? null)) {
                continue;
            }
            $qty = self::quantity($row['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $partId = (int) $row['part_id'];
            $part = $this->parts->find($partId);
            if ($part === null || !(int) $part['is_active']) {
                throw Validator::fail('lines', 'exists');
            }
            $stock = $this->db->fetchOne('SELECT quantity, reserved FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $partId, 's' => $fromSiteId]);
            $available = $stock !== null ? (float) $stock['quantity'] - (float) $stock['reserved'] : 0.0;
            $lines[$partId] = ['part_id' => $partId, 'quantity' => ($lines[$partId]['quantity'] ?? 0) + $qty];
            if ($lines[$partId]['quantity'] > $available + 0.0001) {
                throw Validator::fail('lines', 'transfer_unavailable', ['part' => $part['reference'], 'available' => self::plain(max(0, $available))]);
            }
        }
        if ($lines === []) {
            throw Validator::fail('lines', 'required');
        }

        return array_values($lines);
    }

    private static function quantity(mixed $value): float
    {
        return is_scalar($value) && is_numeric(str_replace(',', '.', (string) $value)) ? round((float) str_replace(',', '.', (string) $value), 2) : -1.0;
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
