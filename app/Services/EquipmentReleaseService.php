<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\EquipmentStatus;
use App\Domain\EquipmentStatusResolver;
use App\Domain\WorkOrderStatus;
use App\Security\Auth;

/**
 * Validation de remise en service (cahier des charges, flotte) : après une intervention corrective ou immobilisante,
 * ou une demande manuelle, l'engin passe « à valider » et ne redevient « disponible » qu'après l'accord
 * d'une autre personne (responsable maintenance, supérieur hiérarchique ou Direction Logistique).
 * Un refus motivé remet l'engin « en panne ».
 */
final class EquipmentReleaseService
{
    public const ENTITY = 'equipment_release';
    public const FLOW = 'equipment_release';

    public function __construct(
        private readonly Database $db,
        private readonly ApprovalService $approvals,
        private readonly NumberingService $numbering,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** Une intervention corrective ou immobilisante terminée exige une validation avant le retour en exploitation. */
    public static function requiredAfter(array $workOrder, WorkOrderStatus $target): bool
    {
        return $target === WorkOrderStatus::Done && ($workOrder['type'] === 'corrective' || (bool) $workOrder['immobilizing']);
    }

    /**
     * État réellement applicable quand un ordre de travail ramènerait l'engin à « disponible ».
     * À appeler dans une transaction, avec la ligne verrouillée de l'engin.
     */
    public function gate(array $equipment, EquipmentStatus $target, ?array $workOrder, bool $required): EquipmentStatus
    {
        if ($target !== EquipmentStatus::Available || $equipment['status'] === EquipmentStatus::Available->value) {
            return $target;
        }
        if ($this->pending((int) $equipment['id']) !== null) {
            return EquipmentStatus::PendingRelease;
        }
        if (!$required) {
            return $target;
        }
        $this->open($equipment, $workOrder !== null ? (int) $workOrder['id'] : null, $workOrder['reference'] ?? null);

        return EquipmentStatus::PendingRelease;
    }

    /** Ouvre la demande et son circuit de validation. @return int identifiant de la demande */
    public function open(array $equipment, ?int $workOrderId, ?string $reason): int
    {
        $reference = $this->numbering->next('RMS');
        $siteId = (int) $equipment['site_id'];
        $id = $this->db->insert(
            'INSERT INTO equipment_releases (reference, equipment_id, site_id, work_order_id, reason, requested_by) VALUES (:r, :e, :s, :w, :m, :u)',
            ['r' => $reference, 'e' => (int) $equipment['id'], 's' => $siteId, 'w' => $workOrderId, 'm' => $reason, 'u' => $this->auth->user()?->id]
        );
        $this->approvals->start(self::FLOW, self::ENTITY, $id, $siteId, null, $reference . ' – ' . $equipment['code'], true);

        return $id;
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM equipment_releases WHERE id = :id', ['id' => $id]);
    }

    /** Demande en attente de validation pour cet engin. */
    public function pending(int $equipmentId): ?array
    {
        return $this->db->fetchOne("SELECT * FROM equipment_releases WHERE equipment_id = :e AND status = 'pending' ORDER BY id DESC LIMIT 1", ['e' => $equipmentId]);
    }

    /** Dernière demande de l'engin (en attente ou décidée), avec son circuit, pour la fiche. */
    public function latest(int $equipmentId): ?array
    {
        $release = $this->db->fetchOne(
            'SELECT r.*, w.reference AS work_order_ref FROM equipment_releases r LEFT JOIN work_orders w ON w.id = r.work_order_id
              WHERE r.equipment_id = :e ORDER BY r.id DESC LIMIT 1',
            ['e' => $equipmentId]
        );

        return $release === null ? null : $release + ['approval' => $this->approvals->forEntity(self::ENTITY, (int) $release['id'], true)];
    }

    /** Un changement d'état manuel pendant l'attente annule la demande en cours. */
    public function cancelPending(int $equipmentId, string $reason): void
    {
        $release = $this->pending($equipmentId);
        if ($release === null) {
            return;
        }
        $this->db->execute("UPDATE equipment_releases SET status = 'cancelled', decided_at = NOW() WHERE id = :id", ['id' => $release['id']]);
        $this->db->execute(
            "UPDATE approval_requests SET status = 'cancelled', current_step_id = NULL, closed_at = NOW() WHERE entity_type = :t AND entity_id = :id AND status = 'pending'",
            ['t' => self::ENTITY, 'id' => $release['id']]
        );
        $this->logger->log('release_cancel', 'equipment', $equipmentId, (int) $release['site_id'], ['status' => 'pending'], ['status' => 'cancelled', 'reason' => $reason], $release['reference']);
    }

    /** Issue du circuit : accord → l'engin retrouve l'état que ses ordres de travail imposent ; refus ou annulation → en panne. */
    public function onApproval(int $id, string $status): void
    {
        $release = $this->db->fetchOne('SELECT * FROM equipment_releases WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($release === null || $release['status'] !== 'pending' || !in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
            return;
        }
        $this->db->execute('UPDATE equipment_releases SET status = :s, decided_at = NOW() WHERE id = :id', ['s' => $status, 'id' => $id]);
        $equipment = $this->db->fetchOne('SELECT * FROM equipment WHERE id = :id FOR UPDATE', ['id' => $release['equipment_id']]);
        if ($equipment === null || $equipment['status'] !== EquipmentStatus::PendingRelease->value) {
            return; // un autre événement (nouvel OT, décision manuelle) a déjà fixé l'état de l'engin
        }
        if ($status === 'approved') {
            $open = $this->db->fetchAll(
                "SELECT status, immobilizing FROM work_orders WHERE equipment_id = :e AND status IN ('open', 'in_progress', 'waiting_part')",
                ['e' => $equipment['id']]
            );
            $target = EquipmentStatusResolver::afterClosure(EquipmentStatus::PendingRelease, $open);
            $reason = $release['reference'];
        } else {
            $target = EquipmentStatus::Breakdown;
            $comment = $this->db->fetchValue(
                'SELECT a.comment FROM approval_actions a JOIN approval_requests r ON r.id = a.request_id
                  WHERE r.entity_type = :t AND r.entity_id = :id ORDER BY a.id DESC LIMIT 1',
                ['t' => self::ENTITY, 'id' => $id]
            );
            $reason = mb_substr($release['reference'] . ($comment ? ' : ' . $comment : ''), 0, 255);
        }
        $userId = $this->auth->user()?->id;
        $this->db->execute('UPDATE equipment SET status = :s, status_changed_at = NOW() WHERE id = :id', ['s' => $target->value, 'id' => $equipment['id']]);
        $this->db->execute(
            'INSERT INTO equipment_status_history (equipment_id, from_status, to_status, reason, work_order_id, changed_by) VALUES (:e, :f, :t, :r, :w, :u)',
            ['e' => $equipment['id'], 'f' => $equipment['status'], 't' => $target->value, 'r' => $reason, 'w' => $release['work_order_id'], 'u' => $userId]
        );
        $this->logger->log('status', 'equipment', (int) $equipment['id'], (int) $equipment['site_id'], ['status' => $equipment['status']],
            ['status' => $target->value, 'reason' => $reason, 'release' => $release['reference'], 'decision' => $status],
            $equipment['code'] . ' : ' . $equipment['status'] . ' → ' . $target->value);
    }
}
