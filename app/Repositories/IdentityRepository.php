<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Accès aux comptes pour l'authentification uniquement. Intervient AVANT que le périmètre
 * de sites ne soit connu : ne manipule aucune donnée métier rattachée à un site.
 */
final class IdentityRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE email = :email', ['email' => mb_strtolower($email)]);
    }

    public function findActive(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $id]);
    }

    /** @return list<int> */
    public function siteIds(int $userId): array
    {
        return array_map(
            'intval',
            array_column($this->db->fetchAll('SELECT site_id FROM user_sites WHERE user_id = :id', ['id' => $userId]), 'site_id')
        );
    }

    /**
     * Fonctions supplémentaires (user_roles) et délégations en cours de validité reçues d'un délégant actif.
     *
     * @return list<array{role: string, site_id: int|null, delegated_from: int|null}>
     */
    public function roleAssignments(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT ur.role, ur.site_id, NULL AS delegated_from FROM user_roles ur WHERE ur.user_id = :u1
             UNION ALL
             SELECT d.role, d.site_id, d.from_user_id AS delegated_from
               FROM delegations d JOIN users f ON f.id = d.from_user_id AND f.is_active = 1
              WHERE d.to_user_id = :u2 AND d.revoked_at IS NULL AND CURDATE() BETWEEN d.starts_on AND d.ends_on',
            ['u1' => $userId, 'u2' => $userId]
        );
    }

    public function recordSuccess(int $id): void
    {
        $this->db->execute(
            'UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    public function recordFailure(int $id, int $maxAttempts, int $lockMinutes): void
    {
        $this->db->execute(
            'UPDATE users
                SET failed_logins = failed_logins + 1,
                    locked_until = IF(failed_logins >= :max, NOW() + INTERVAL :minutes MINUTE, locked_until)
              WHERE id = :id',
            ['id' => $id, 'max' => $maxAttempts, 'minutes' => $lockMinutes]
        );
    }

    public function updateLocale(int $id, string $locale): void
    {
        $this->db->execute('UPDATE users SET locale = :locale WHERE id = :id', ['locale' => $locale, 'id' => $id]);
    }
}
