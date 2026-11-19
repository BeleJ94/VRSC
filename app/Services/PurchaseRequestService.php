<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\PurchaseRepository;
use App\Security\Auth;

/**
 * État de besoins (EDB) — cahier des charges § 6.
 *
 * Statuts VRSC : en attente → approuvé non exécuté (sous-statut « exécution partielle ») → approuvé exécuté ; annulé.
 * L'exécution n'est acquise que lorsque l'intégralité du besoin approuvé est satisfaite par des écritures justifiées
 * (sortie de stock, réception de transfert, bon de réception) : ni une commande ni un paiement ne clôturent un EDB.
 */
final class PurchaseRequestService
{
    public const ENTITY = 'purchase_request';

    public function __construct(
        private readonly Database $db,
        private readonly PurchaseRepository $purchases,
        private readonly StockService $stock,
        private readonly StockTransferService $transfers,
        private readonly ApprovalService $approvals,
        private readonly NumberingService $numbering,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** Lignes : [['part_id' => …] ou ['description' => …], 'quantity', 'unit', 'est_unit_cost']. */
    public function create(array $input): int
    {
        $data = Validator::validate($input, [
            'site_id' => 'required|int',
            'department_id' => 'int',
            'project_id' => 'int',
            'cost_center_id' => 'int',
            'equipment_id' => 'int',
            'category' => 'required|in:' . implode(',', PurchaseRepository::CATEGORIES),
            'urgency' => 'required|in:' . implode(',', PurchaseRepository::URGENCIES),
            'subject' => 'required|string|max:150',
            'reason' => 'required|string|max:500',
            'lines' => 'required|array',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        if (!$user->canAt('edb.create', $data['site_id'])) {
            throw Validator::fail('site_id', 'exists');
        }
        foreach (['department_id' => ['departments', false], 'cost_center_id' => ['cost_centers', false], 'project_id' => ['projects', true], 'equipment_id' => ['equipment', true]] as $field => [$table, $siteScoped]) {
            if ($data[$field] !== null && !$this->db->fetchValue(
                "SELECT 1 FROM $table WHERE id = :id" . ($siteScoped ? ' AND site_id = :s' : ''),
                ['id' => $data[$field]] + ($siteScoped ? ['s' => $data['site_id']] : [])
            )) {
                throw Validator::fail($field, 'exists');
            }
        }
        $lines = $this->lines($data['lines']);
        // Estimation : somme des lignes chiffrées ; inconnue si une ligne n'est pas chiffrée (jamais zéro par défaut).
        $estimate = 0.0;
        foreach ($lines as $line) {
            if ($line['est_unit_cost'] === null) {
                $estimate = null;
                break;
            }
            $estimate += $line['quantity'] * $line['est_unit_cost'];
        }

        return $this->db->transaction(function () use ($data, $lines, $estimate, $user): int {
            $reference = $this->numbering->next('EDB');
            unset($data['lines']);
            $id = $this->purchases->create($data + [
                'reference' => $reference,
                'estimated_amount' => $estimate !== null ? round($estimate, 2) : null,
                'requested_by' => $user->id,
            ], $lines);
            $this->logger->log('create', self::ENTITY, $id, $data['site_id'], null, $data + ['reference' => $reference, 'estimated_amount' => $estimate, 'lines' => count($lines)], $reference . ' – ' . $data['subject']);
            $approval = $this->approvals->start('purchase_request', self::ENTITY, $id, $data['site_id'], $estimate, $reference);
            if ($approval['status'] === 'approved') {
                $this->onApproval($id, 'approved');
            }

            return $id;
        });
    }

    /** Issue du circuit d'approbation. Un refus vaut annulation motivée (motif = commentaire de l'approbateur). */
    public function onApproval(int $id, string $status): void
    {
        $request = $this->db->fetchOne('SELECT * FROM purchase_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($request === null || $request['status'] !== 'pending') {
            return;
        }
        $now = date('Y-m-d H:i:s');
        if ($status === 'approved') {
            $this->purchases->setStatus($id, 'approved', ['approved_at' => $now]);
            $new = 'approved';
        } elseif ($status === 'rejected' || $status === 'cancelled') {
            $reason = null;
            if ($status === 'rejected') {
                $reason = (string) $this->db->fetchValue(
                    "SELECT a.comment FROM approval_actions a JOIN approval_requests ar ON ar.id = a.request_id
                      WHERE ar.entity_type = :t AND ar.entity_id = :e AND a.decision = 'unfavorable' ORDER BY a.id DESC LIMIT 1",
                    ['t' => self::ENTITY, 'e' => $id]
                );
            }
            $this->purchases->setStatus($id, 'cancelled', ['cancelled_at' => $now, 'is_rejected' => $status === 'rejected' ? 1 : 0]
                + ($reason !== null && $reason !== '' ? ['cancel_reason' => mb_substr($reason, 0, 500)] : []));
            $new = 'cancelled';
        } else {
            return;
        }
        $this->logger->log('transition', self::ENTITY, $id, (int) $request['site_id'], ['status' => 'pending'], ['status' => $new], $request['reference']);
    }

    /** Annulation motivée : avant approbation, ou après tant qu'aucune commande ou transfert n'est en cours. */
    public function cancel(int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw Validator::fail('cancel_reason', 'required');
        }
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $reason, $user): void {
            $request = $this->purchases->find($id, true) ?? throw HttpException::notFound();
            $own = (int) ($request['requested_by'] ?? 0) === $user->id;
            if (!$own && !$user->canAt('approvals.override', (int) $request['site_id'])) {
                throw HttpException::forbidden();
            }
            if (!in_array($request['status'], ['pending', 'approved'], true)) {
                throw Validator::fail('status', 'edb_closed');
            }
            foreach ($this->purchases->lines($id) as $line) {
                if ((float) $line['qty_on_order'] > 0 || (float) $line['qty_in_transfer'] > 0) {
                    throw Validator::fail('status', 'edb_in_progress');
                }
            }
            if ($request['status'] === 'pending') {
                $approval = $this->approvals->forEntity(self::ENTITY, $id);
                if ($approval !== null && $approval['status'] === 'pending') {
                    $this->approvals->cancel((int) $approval['id'], $reason);
                }
            }
            $this->purchases->setStatus($id, 'cancelled', ['cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr($reason, 0, 500)]);
            $this->logger->log('transition', self::ENTITY, $id, (int) $request['site_id'], ['status' => $request['status']],
                ['status' => 'cancelled', 'reason' => mb_substr($reason, 0, 255)], $request['reference']);
        });
    }

    /** Sert une ligne depuis le stock du site de l'EDB : sortie imputée au bénéficiaire de l'EDB. */
    public function issueFromStock(int $requestId, array $input): void
    {
        $data = Validator::validate($input, ['line_id' => 'required|int', 'quantity' => 'required|numeric|min:0.01|max:100000000']);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($requestId, $data, $user): void {
            [$request, $line] = $this->approvedLine($requestId, $data['line_id'], $data['quantity']);
            $siteId = (int) $request['site_id'];
            if (!$user->canAt('stock.move', $siteId)) {
                throw HttpException::forbidden();
            }
            $bon = $this->numbering->next('BS');
            $cost = $this->stock->costOf((int) $line['part_id'], $siteId);
            $this->stock->move((int) $line['part_id'], $siteId, 'out', $data['quantity'], $request['reference'] . ' – ' . $request['subject'], null,
                $request['equipment_id'] !== null ? (int) $request['equipment_id'] : null, [
                    'document_ref' => $bon,
                    'project_id' => $request['project_id'] !== null ? (int) $request['project_id'] : null,
                    'department_id' => $request['department_id'] !== null ? (int) $request['department_id'] : null,
                ]);
            $this->fulfil($requestId, (int) $line['id'], 'stock', $data['quantity'], $cost !== null ? round($cost * $data['quantity'], 2) : null, $bon);
        });
    }

    /** Demande un transfert depuis un autre magasin pour une ligne : il suit son propre circuit d'autorisation. */
    public function requestTransfer(int $requestId, array $input): int
    {
        $data = Validator::validate($input, ['line_id' => 'required|int', 'from_site_id' => 'required|int', 'quantity' => 'required|numeric|min:0.01|max:100000000']);

        return $this->db->transaction(function () use ($requestId, $data): int {
            [$request, $line] = $this->approvedLine($requestId, $data['line_id'], $data['quantity']);
            $transferId = $this->transfers->request([
                'from_site_id' => $data['from_site_id'],
                'to_site_id' => (int) $request['site_id'],
                'reason' => $request['reference'] . ' – ' . $request['subject'],
                'lines' => [['part_id' => (int) $line['part_id'], 'quantity' => $data['quantity']]],
            ]);
            $this->db->execute(
                'UPDATE stock_transfer_lines SET request_line_id = :l WHERE transfer_id = :t AND part_id = :p',
                ['l' => $line['id'], 't' => $transferId, 'p' => $line['part_id']]
            );
            $this->logger->log('edb_transfer', self::ENTITY, $requestId, (int) $request['site_id'], null,
                ['line' => $line['description'], 'quantity' => $data['quantity'], 'transfer_id' => $transferId], $request['reference']);

            return $transferId;
        });
    }

    /**
     * Enregistre une satisfaction datée et justifiée d'une ligne, puis recalcule le statut :
     * « approuvé exécuté » uniquement quand plus rien ne reste à satisfaire.
     */
    public function fulfil(int $requestId, int $lineId, string $kind, float $quantity, ?float $value, ?string $documentRef): void
    {
        $this->db->transaction(function () use ($requestId, $lineId, $kind, $quantity, $value, $documentRef): void {
            $request = $this->db->fetchOne('SELECT * FROM purchase_requests WHERE id = :id FOR UPDATE', ['id' => $requestId]) ?? throw HttpException::notFound();
            $this->purchases->addFulfilment($lineId, $kind, $quantity, $value, $documentRef, $this->auth->user()?->id);
            $remaining = $this->purchases->remaining($requestId);
            if ($request['status'] === 'approved' && $remaining <= 0.0001) {
                $this->purchases->setStatus($requestId, 'executed', ['executed_at' => date('Y-m-d H:i:s')]);
            }
            $this->logger->log('edb_fulfil', self::ENTITY, $requestId, (int) $request['site_id'], null,
                ['kind' => $kind, 'quantity' => $quantity, 'value' => $value, 'document_ref' => $documentRef, 'remaining' => $remaining], $request['reference']);
        });
    }

    /**
     * EDB approuvé et sa ligne d'article, verrouillés, avec le reste à sourcer contrôlé par l'appelant.
     *
     * @return array{0: array, 1: array}
     */
    private function approvedLine(int $requestId, int $lineId, float $quantity): array
    {
        $request = $this->purchases->find($requestId, true) ?? throw HttpException::notFound();
        if ($request['status'] !== 'approved') {
            // Un EDB en attente n'autorise ni sortie, ni transfert, ni achat.
            throw Validator::fail('status', 'edb_not_approved');
        }
        $line = null;
        foreach ($this->purchases->lines($requestId) as $candidate) {
            if ((int) $candidate['id'] === $lineId) {
                $line = $candidate;
            }
        }
        if ($line === null || $line['part_id'] === null) {
            throw Validator::fail('line_id', 'exists');
        }
        // On ne sert pas plus que ce qui reste à sourcer (ni déjà servi, ni en transfert, ni en commande).
        if ($quantity > (float) $line['qty_to_source'] + 0.0001) {
            throw Validator::fail('quantity', 'order_over_need', ['line' => $line['description'], 'max' => rtrim(rtrim(number_format((float) $line['qty_to_source'], 2, '.', ''), '0'), '.')]);
        }

        return [$request, $line];
    }

    /** @return list<array{part_id: int|null, description: string, unit: string, quantity: float, est_unit_cost: float|null}> */
    private function lines(array $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $qty = self::number($row['quantity'] ?? null);
            $partId = is_numeric($row['part_id'] ?? null) && (int) $row['part_id'] > 0 ? (int) $row['part_id'] : null;
            $description = trim((string) (is_scalar($row['description'] ?? null) ? $row['description'] : ''));
            if ($qty === null && $partId === null && $description === '') {
                continue; // ligne vide du formulaire
            }
            if ($qty === null || $qty <= 0) {
                throw Validator::fail('lines', 'edb_line_quantity');
            }
            $unit = trim((string) (is_scalar($row['unit'] ?? null) ? $row['unit'] : ''));
            $cost = self::number($row['est_unit_cost'] ?? null);
            if ($partId !== null) {
                $part = $this->db->fetchOne('SELECT reference, name, unit, unit_cost FROM parts WHERE id = :id AND is_active = 1', ['id' => $partId])
                    ?? throw Validator::fail('lines', 'exists');
                $description = $part['reference'] . ' – ' . $part['name'];
                $unit = (string) $part['unit'];
                $cost ??= (float) $part['unit_cost'] > 0 ? (float) $part['unit_cost'] : null;
            } elseif ($description === '') {
                throw Validator::fail('lines', 'edb_line_description');
            }
            $lines[] = [
                'part_id' => $partId,
                'description' => mb_substr($description, 0, 190),
                'unit' => mb_substr($unit !== '' ? $unit : 'u', 0, 10),
                'quantity' => round($qty, 2),
                'est_unit_cost' => $cost !== null && $cost >= 0 ? round($cost, 4) : null,
            ];
        }
        if ($lines === []) {
            throw Validator::fail('lines', 'required');
        }

        return $lines;
    }

    private static function number(mixed $value): ?float
    {
        $text = is_scalar($value) ? trim(str_replace(',', '.', (string) $value)) : '';

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }
}
