<?php

declare(strict_types=1);

namespace App\Repositories;

/** Délégations de fonction (cahier des charges § 2.1 / 2.2) : jamais supprimées, seulement révoquées. */
final class DelegationRepository extends Repository
{
    /** Délégations visibles : celles des sites du périmètre (une délégation « tous sites » exige un périmètre global). */
    public function all(): array
    {
        [$where, $params] = $this->visibility();

        return $this->db->fetchAll(
            "SELECT d.*, s.name AS site,
                    CONCAT(f.first_name, ' ', f.last_name) AS from_name,
                    CONCAT(t.first_name, ' ', t.last_name) AS to_name,
                    CASE WHEN d.revoked_at IS NOT NULL THEN 'revoked'
                         WHEN CURDATE() < d.starts_on THEN 'upcoming'
                         WHEN CURDATE() > d.ends_on THEN 'expired'
                         ELSE 'active' END AS state
               FROM delegations d
               JOIN users f ON f.id = d.from_user_id
               JOIN users t ON t.id = d.to_user_id
               LEFT JOIN sites s ON s.id = d.site_id
              WHERE $where
              ORDER BY d.revoked_at IS NOT NULL, d.ends_on DESC, d.id DESC",
            $params
        );
    }

    public function find(int $id): ?array
    {
        [$where, $params] = $this->visibility();

        return $this->db->fetchOne("SELECT d.* FROM delegations d WHERE d.id = :id AND $where", ['id' => $id] + $params);
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO delegations (from_user_id, to_user_id, role, site_id, starts_on, ends_on, reason, created_by)
             VALUES (:from_user_id, :to_user_id, :role, :site_id, :starts_on, :ends_on, :reason, :created_by)',
            [
                'from_user_id' => $data['from_user_id'],
                'to_user_id' => $data['to_user_id'],
                'role' => $data['role'],
                'site_id' => $data['site_id'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'reason' => $data['reason'],
                'created_by' => $data['created_by'] ?? null,
            ]
        );
    }

    public function revoke(int $id, ?int $userId): void
    {
        $this->db->execute(
            'UPDATE delegations SET revoked_at = NOW(), revoked_by = :by WHERE id = :id AND revoked_at IS NULL',
            ['by' => $userId, 'id' => $id]
        );
    }

    /** @return array{0: string, 1: array<string, int>} */
    private function visibility(): array
    {
        if ($this->scope->isAll()) {
            return ['1=1', []];
        }
        [$scopeSql, $params] = $this->scope->clause('d.site_id');

        return ["(d.site_id IS NOT NULL AND $scopeSql)", $params];
    }
}
