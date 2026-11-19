<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Role;

/**
 * Utilisateur connecté et ses habilitations. Une habilitation = un rôle exercé sur un site (ou sur tous) :
 * le profil principal (users.role + sites rattachés), les fonctions supplémentaires (user_roles) et les
 * délégations reçues en cours de validité.
 */
final class CurrentUser
{
    /** @var list<array{role: Role, site_id: int|null, delegated_from: int|null}> */
    private readonly array $assignments;

    /**
     * @param list<int> $siteIds sites du profil principal
     * @param list<array{role: Role, site_id: int|null, delegated_from: int|null}> $extraAssignments
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly Role $role,
        public readonly string $locale,
        public readonly bool $allSites,
        public readonly array $siteIds,
        array $extraAssignments = [],
    ) {
        $assignments = [];
        if ($role === Role::Admin || $allSites) {
            $assignments[] = ['role' => $role, 'site_id' => null, 'delegated_from' => null];
        } else {
            foreach ($siteIds as $siteId) {
                $assignments[] = ['role' => $role, 'site_id' => (int) $siteId, 'delegated_from' => null];
            }
        }
        foreach ($extraAssignments as $extra) {
            $assignments[] = [
                'role' => $extra['role'],
                'site_id' => $extra['site_id'] !== null ? (int) $extra['site_id'] : null,
                'delegated_from' => isset($extra['delegated_from']) ? (int) $extra['delegated_from'] : null,
            ];
        }
        $this->assignments = $assignments;
    }

    public function name(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->firstName, 0, 1) . mb_substr($this->lastName, 0, 1));
    }

    /** @return list<array{role: Role, site_id: int|null, delegated_from: int|null}> */
    public function assignments(): array
    {
        return $this->assignments;
    }

    /** La permission est-elle détenue sur au moins un site ? (accès aux écrans) */
    public function can(string $permission): bool
    {
        if ($this->role->can($permission)) {
            return true;
        }
        foreach ($this->assignments as $assignment) {
            if ($assignment['role']->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** La permission est-elle détenue sur ce site précis ? (écritures et validations) */
    public function canAt(string $permission, ?int $siteId): bool
    {
        if ($siteId === null) {
            return false;
        }
        foreach ($this->assignments as $assignment) {
            if ($assignment['role']->can($permission) && ($assignment['site_id'] === null || $assignment['site_id'] === $siteId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Habilitation par laquelle l'utilisateur exerce $role sur $siteId (la sienne de préférence, sinon une délégation).
     *
     * @return array{role: Role, site_id: int|null, delegated_from: int|null}|null
     */
    public function holds(Role $role, ?int $siteId): ?array
    {
        $found = null;
        foreach ($this->assignments as $assignment) {
            if ($assignment['role'] !== $role || ($assignment['site_id'] !== null && $assignment['site_id'] !== $siteId)) {
                continue;
            }
            if ($assignment['delegated_from'] === null) {
                return $assignment;
            }
            $found ??= $assignment;
        }

        return $found;
    }

    /** Périmètre global : tous les sites où l'utilisateur exerce au moins un rôle. */
    public function scope(): SiteScope
    {
        return $this->scopeOf(static fn (Role $role): bool => true);
    }

    /** Sites sur lesquels la permission est détenue. */
    public function scopeFor(string $permission): SiteScope
    {
        return $this->scopeOf(static fn (Role $role): bool => $role->can($permission));
    }

    /** @param callable(Role): bool $filter */
    private function scopeOf(callable $filter): SiteScope
    {
        $sites = [];
        foreach ($this->assignments as $assignment) {
            if (!$filter($assignment['role'])) {
                continue;
            }
            if ($assignment['site_id'] === null) {
                return SiteScope::all();
            }
            $sites[] = $assignment['site_id'];
        }

        return SiteScope::forSites($sites);
    }
}
