<?php

declare(strict_types=1);

namespace App\Repositories;

/** Circuits d'approbation : définitions (flux, étapes), demandes et actes. Les demandes sont filtrées par site. */
final class ApprovalRepository extends Repository
{
    public function flowByCode(string $code): ?array
    {
        return $this->db->fetchOne('SELECT * FROM approval_flows WHERE code = :c AND is_active = 1', ['c' => $code]);
    }

    /** @return list<array> étapes du flux, dans l'ordre */
    public function steps(int $flowId): array
    {
        return $this->db->fetchAll('SELECT * FROM approval_flow_steps WHERE flow_id = :f ORDER BY position', ['f' => $flowId]);
    }

    /** Crée ou remplace la définition d'un flux (paramétrage ; les actes déjà passés gardent leur copie de l'étape). */
    public function defineFlow(string $code, string $name, array $steps): int
    {
        $flow = $this->db->fetchOne('SELECT id FROM approval_flows WHERE code = :c', ['c' => $code]);
        if ($flow === null) {
            $flowId = $this->db->insert('INSERT INTO approval_flows (code, name) VALUES (:c, :n)', ['c' => $code, 'n' => $name]);
        } else {
            $flowId = (int) $flow['id'];
            $this->db->execute('UPDATE approval_flows SET name = :n, is_active = 1 WHERE id = :id', ['n' => $name, 'id' => $flowId]);
        }
        foreach (array_values($steps) as $i => $step) {
            $this->db->execute(
                'INSERT INTO approval_flow_steps (flow_id, position, code, name, kind, role, is_blocking, min_amount)
                 VALUES (:f, :p, :c, :n, :k, :r, :b, :m)
                 ON DUPLICATE KEY UPDATE position = VALUES(position), name = VALUES(name), kind = VALUES(kind), role = VALUES(role),
                                         is_blocking = VALUES(is_blocking), min_amount = VALUES(min_amount)',
                [
                    'f' => $flowId, 'p' => ($i + 1) * 10, 'c' => $step['code'], 'n' => $step['name'],
                    'k' => $step['kind'] ?? 'authorization', 'r' => $step['role'],
                    'b' => ($step['is_blocking'] ?? true) ? 1 : 0, 'm' => $step['min_amount'] ?? null,
                ]
            );
        }

        return $flowId;
    }

    public function createRequest(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO approval_requests (flow_id, entity_type, entity_id, site_id, reference, amount, status, current_step_id, requested_by, closed_at)
             VALUES (:flow_id, :entity_type, :entity_id, :site_id, :reference, :amount, :status, :current_step_id, :requested_by, :closed_at)',
            $data
        );
    }

    /** Demande verrouillée pour un acte (dans le périmètre). */
    public function findForUpdate(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('r.site_id');

        return $this->db->fetchOne(
            "SELECT r.* FROM approval_requests r WHERE r.id = :id AND $scopeSql FOR UPDATE",
            ['id' => $id] + $params
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('r.site_id');

        return $this->db->fetchOne(
            "SELECT r.*, f.code AS flow_code, f.name AS flow_name, s.name AS site, st.code AS step_code, st.name AS step_name,
                    st.kind AS step_kind, st.role AS step_role,
                    CONCAT(u.first_name, ' ', u.last_name) AS requester
               FROM approval_requests r
               JOIN approval_flows f ON f.id = r.flow_id
               JOIN sites s ON s.id = r.site_id
               LEFT JOIN approval_flow_steps st ON st.id = r.current_step_id
               LEFT JOIN users u ON u.id = r.requested_by
              WHERE r.id = :id AND $scopeSql",
            ['id' => $id] + $params
        );
    }

    /** Dernière demande ouverte ou close d'une transaction. */
    public function latestForEntity(string $entityType, int $entityId): ?array
    {
        $id = $this->db->fetchValue(
            'SELECT id FROM approval_requests WHERE entity_type = :t AND entity_id = :e ORDER BY id DESC LIMIT 1',
            ['t' => $entityType, 'e' => $entityId]
        );

        return $id === null ? null : $this->find((int) $id);
    }

    public function step(int $stepId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM approval_flow_steps WHERE id = :id', ['id' => $stepId]);
    }

    public function updateRequest(int $id, string $status, ?int $currentStepId): void
    {
        $this->db->execute(
            "UPDATE approval_requests SET status = :s, current_step_id = :c, closed_at = IF(:s2 = 'pending', NULL, NOW()) WHERE id = :id",
            ['s' => $status, 's2' => $status, 'c' => $currentStepId, 'id' => $id]
        );
    }

    public function addAction(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO approval_actions (request_id, step_id, step_code, step_kind, decision, comment, acted_by, actor_name, acted_role,
                                           delegated_from, is_override, override_reason)
             VALUES (:request_id, :step_id, :step_code, :step_kind, :decision, :comment, :acted_by, :actor_name, :acted_role,
                     :delegated_from, :is_override, :override_reason)',
            $data
        );
    }

    /** @return list<array> actes d'une demande, dans l'ordre chronologique */
    public function actions(int $requestId): array
    {
        return $this->db->fetchAll(
            "SELECT a.*, st.name AS step_name, CONCAT(d.first_name, ' ', d.last_name) AS delegated_from_name
               FROM approval_actions a
               JOIN approval_flow_steps st ON st.id = a.step_id
               LEFT JOIN users d ON d.id = a.delegated_from
              WHERE a.request_id = :r ORDER BY a.id",
            ['r' => $requestId]
        );
    }

    /** Demandes en attente dans le périmètre, avec leur étape courante. */
    public function pending(): array
    {
        [$scopeSql, $params] = $this->scope->clause('r.site_id');

        return $this->db->fetchAll(
            "SELECT r.id, r.entity_type, r.entity_id, r.site_id, r.reference, r.amount, r.requested_at, r.requested_by,
                    f.code AS flow_code, f.name AS flow_name, s.name AS site,
                    st.code AS step_code, st.name AS step_name, st.kind AS step_kind, st.role AS step_role,
                    CONCAT(u.first_name, ' ', u.last_name) AS requester
               FROM approval_requests r
               JOIN approval_flows f ON f.id = r.flow_id
               JOIN sites s ON s.id = r.site_id
               JOIN approval_flow_steps st ON st.id = r.current_step_id
               LEFT JOIN users u ON u.id = r.requested_by
              WHERE r.status = 'pending' AND $scopeSql
              ORDER BY r.requested_at",
            $params
        );
    }
}
