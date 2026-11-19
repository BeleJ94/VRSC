<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Csrf;
use App\Core\Session;
use App\Domain\Role;
use App\Repositories\IdentityRepository;

/**
 * Authentification par session, verrouillage après échecs répétés.
 */
final class Auth
{
    private const SESSION_KEY = 'auth_user_id';

    private ?CurrentUser $user = null;
    private bool $resolved = false;
    /** Permission de la route en cours : le périmètre est limité aux sites où elle est détenue. */
    private ?string $focus = null;

    public function __construct(
        private readonly Session $session,
        private readonly IdentityRepository $identities,
        private readonly Csrf $csrf,
        private readonly array $security = ['max_login_attempts' => 5, 'lockout_minutes' => 15],
    ) {
    }

    public function user(): ?CurrentUser
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $id = $this->session->get(self::SESSION_KEY);
            if (is_int($id)) {
                $row = $this->identities->findActive($id);
                $this->user = $row === null ? null : $this->hydrate($row);
                if ($this->user === null) {
                    $this->session->remove(self::SESSION_KEY);
                }
            }
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Limite le périmètre de la requête aux sites où l'utilisateur détient $permission
     * (un magasinier du site A, simple lecteur du site B, n'écrit que sur A).
     */
    public function focus(?string $permission): void
    {
        $this->focus = $permission;
    }

    public function scope(): SiteScope
    {
        $user = $this->user();
        if ($user === null) {
            return SiteScope::none();
        }

        return $this->focus !== null ? $user->scopeFor($this->focus) : $user->scope();
    }

    /**
     * @return array{ok: bool, error?: string, user?: CurrentUser, user_id?: int}
     *   error = clé i18n (message volontairement générique pour ne pas révéler l'existence du compte)
     *   user_id = compte visé par un mot de passe erroné (journalisation uniquement, jamais renvoyé au client)
     */
    public function attempt(string $email, string $password): array
    {
        $row = $this->identities->findByEmail(trim($email));
        if ($row === null) {
            // Vérification factice : même durée de réponse que pour un compte existant.
            password_verify($password, '$2y$10$tK6nKHd3NnV9s1/.xCmune9CDFKcd6NhQj4nkFQ44RGqGAAG.Q1s6');

            return ['ok' => false, 'error' => 'auth.invalid'];
        }
        if (!(bool) $row['is_active']) {
            return ['ok' => false, 'error' => 'auth.invalid'];
        }
        if ($row['locked_until'] !== null && strtotime((string) $row['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'auth.locked'];
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            $this->identities->recordFailure(
                (int) $row['id'],
                (int) $this->security['max_login_attempts'],
                (int) $this->security['lockout_minutes']
            );

            return ['ok' => false, 'error' => 'auth.invalid', 'user_id' => (int) $row['id']];
        }

        $this->identities->recordSuccess((int) $row['id']);
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_KEY, (int) $row['id']);
        $this->session->set('locale', (string) $row['locale']);
        $this->user = $this->hydrate($row);
        $this->resolved = true;

        return ['ok' => true, 'user' => $this->user];
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->session->regenerate();
        $this->user = null;
        $this->resolved = true;
    }

    private function hydrate(array $row): CurrentUser
    {
        return new CurrentUser(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            Role::from((string) $row['role']),
            (string) $row['locale'],
            (bool) $row['all_sites'],
            $this->identities->siteIds((int) $row['id']),
            $this->extraAssignments((int) $row['id']),
        );
    }

    /** Fonctions supplémentaires et délégations reçues (rôles inconnus ignorés). */
    private function extraAssignments(int $userId): array
    {
        $assignments = [];
        foreach ($this->identities->roleAssignments($userId) as $assignment) {
            $role = Role::tryFrom((string) $assignment['role']);
            if ($role !== null) {
                $assignments[] = [
                    'role' => $role,
                    'site_id' => $assignment['site_id'] !== null ? (int) $assignment['site_id'] : null,
                    'delegated_from' => $assignment['delegated_from'] !== null ? (int) $assignment['delegated_from'] : null,
                ];
            }
        }

        return $assignments;
    }
}
