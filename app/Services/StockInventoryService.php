<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\StockDocumentRepository;
use App\Security\Auth;

/**
 * Inventaire physique (cahier des charges § 5, recette R7). Le stock constaté ne se confond pas avec le stock
 * théorique : l'inventaire photographie le théorique, reçoit les quantités comptées, puis ses écarts — tous
 * justifiés — sont soumis à approbation. Le stock n'est ajusté qu'après approbation, par des mouvements
 * « écart d'inventaire » datés dans la période ouverte ; l'historique reste intact.
 */
final class StockInventoryService
{
    public const ENTITY = 'stock_inventory';

    public function __construct(
        private readonly Database $db,
        private readonly StockDocumentRepository $documents,
        private readonly StockService $stock,
        private readonly ApprovalService $approvals,
        private readonly NumberingService $numbering,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    public function open(array $input): int
    {
        $data = Validator::validate($input, [
            'site_id' => 'required|int',
            'counted_on' => 'date',
            'note' => 'string|max:255',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        if (!$user->canAt('stock.inventory', $data['site_id'])) {
            throw Validator::fail('site_id', 'exists');
        }
        $data['counted_on'] ??= date('Y-m-d');
        if ($data['counted_on'] > date('Y-m-d')) {
            throw Validator::fail('counted_on', 'stock_future');
        }
        $this->stock->assertOpen($data['site_id'], $data['counted_on']);
        if ($this->documents->hasOpenInventory($data['site_id'])) {
            throw Validator::fail('site_id', 'inventory_open');
        }

        return $this->db->transaction(function () use ($data, $user): int {
            $reference = $this->numbering->next('INV');
            $id = $this->documents->createInventory([
                'reference' => $reference, 'site_id' => $data['site_id'], 'counted_on' => $data['counted_on'],
                'note' => $data['note'], 'created_by' => $user->id,
            ]);
            $this->logger->log('create', self::ENTITY, $id, $data['site_id'], null, ['reference' => $reference, 'counted_on' => $data['counted_on']], $reference);

            return $id;
        });
    }

    /**
     * Saisie des quantités comptées (inventaire en cours uniquement).
     * counted[part_id] = quantité (vide = non compté), justification[part_id] = explication de l'écart.
     */
    public function saveCounts(int $id, array $input): void
    {
        $counted = is_array($input['counted'] ?? null) ? $input['counted'] : [];
        $justifications = is_array($input['justification'] ?? null) ? $input['justification'] : [];
        $this->db->transaction(function () use ($id, $counted, $justifications): void {
            $inventory = $this->editable($id);
            foreach ($this->documents->inventoryLines($id) as $line) {
                $partId = (int) $line['part_id'];
                if (!array_key_exists($partId, $counted)) {
                    continue;
                }
                $raw = trim(str_replace(',', '.', (string) (is_scalar($counted[$partId]) ? $counted[$partId] : '')));
                if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100000000)) {
                    throw Validator::fail('quantity', 'numeric');
                }
                $justification = trim((string) (is_scalar($justifications[$partId] ?? null) ? $justifications[$partId] : ''));
                $this->documents->setCount($id, $partId, $raw === '' ? null : round((float) $raw, 2), $justification !== '' ? mb_substr($justification, 0, 255) : null);
            }
            $this->logger->log('inventory_count', self::ENTITY, $id, (int) $inventory['site_id'], null, ['lines' => count($counted)], $inventory['reference']);
        });
    }

    /**
     * Soumission : les comptages sont figés. Sans écart, l'inventaire est clos ; sinon chaque écart doit être
     * justifié et l'ensemble part en approbation. Le stock ne bouge pas à ce stade.
     *
     * @return string statut de l'inventaire après soumission
     */
    public function submit(int $id): string
    {
        return $this->db->transaction(function () use ($id): string {
            $inventory = $this->editable($id);
            $lines = $this->documents->inventoryLines($id);
            $counted = array_filter($lines, static fn (array $l): bool => $l['qty_counted'] !== null);
            if ($counted === []) {
                throw Validator::fail('quantity', 'inventory_empty');
            }
            $gapValue = 0.0;
            $gaps = 0;
            foreach ($counted as $line) {
                if (abs((float) $line['gap']) < 0.0001) {
                    continue;
                }
                $gaps++;
                if ($line['justification'] === null || trim((string) $line['justification']) === '') {
                    throw Validator::fail('justification', 'inventory_justification', ['part' => $line['reference']]);
                }
                $gapValue += abs((float) ($line['gap_value'] ?? 0));
            }
            if ($gaps === 0) {
                $this->documents->updateInventory($id, 'approved', true);
                $status = 'approved';
            } else {
                $this->documents->updateInventory($id, 'submitted', true);
                $status = 'submitted';
                $approval = $this->approvals->start('stock_inventory', self::ENTITY, $id, (int) $inventory['site_id'], round($gapValue, 2), $inventory['reference']);
                if ($approval['status'] === 'approved') {
                    $this->onApproval($id, 'approved');
                    $status = 'approved';
                }
            }
            $this->logger->log('transition', self::ENTITY, $id, (int) $inventory['site_id'], ['status' => 'draft'],
                ['status' => $status, 'gaps' => $gaps, 'gap_value' => round($gapValue, 2)], $inventory['reference']);

            return $status;
        });
    }

    /** Issue de l'approbation : les écarts approuvés deviennent des mouvements ; un refus laisse le stock inchangé. */
    public function onApproval(int $id, string $status): void
    {
        $inventory = $this->db->fetchOne('SELECT * FROM stock_inventories WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($inventory === null || $inventory['status'] !== 'submitted') {
            return;
        }
        if ($status === 'approved') {
            foreach ($this->documents->inventoryLines($id) as $line) {
                if ($line['qty_counted'] === null || abs((float) $line['gap']) < 0.0001) {
                    continue;
                }
                $this->stock->move(
                    (int) $line['part_id'], (int) $inventory['site_id'], 'inventory', (float) $line['gap'],
                    $inventory['reference'] . ' : ' . $line['justification'], null, null,
                    ['document_ref' => $inventory['reference'], 'inventory_id' => $id]
                );
            }
        }
        if (in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
            $this->documents->updateInventory($id, $status);
            $this->logger->log('transition', self::ENTITY, $id, (int) $inventory['site_id'], ['status' => 'submitted'], ['status' => $status], $inventory['reference']);
        }
    }

    /** Abandon d'un inventaire non soumis. */
    public function cancel(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $inventory = $this->editable($id);
            $this->documents->updateInventory($id, 'cancelled');
            $this->logger->log('transition', self::ENTITY, $id, (int) $inventory['site_id'], ['status' => 'draft'], ['status' => 'cancelled'], $inventory['reference']);
        });
    }

    /** Inventaire en cours de comptage, verrouillé, sur un site où l'utilisateur tient l'inventaire. */
    private function editable(int $id): array
    {
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $inventory = $this->documents->findInventory($id, true) ?? throw HttpException::notFound();
        if (!$user->canAt('stock.inventory', (int) $inventory['site_id'])) {
            throw HttpException::forbidden();
        }
        if ($inventory['status'] !== 'draft') {
            // Après soumission, un écart ne se modifie plus : il est approuvé ou refusé tel quel.
            throw Validator::fail('status', 'inventory_locked');
        }

        return $inventory;
    }
}
