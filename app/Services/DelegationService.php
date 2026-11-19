<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\Role;
use App\Repositories\DelegationRepository;
use App\Repositories\UserRepository;
use App\Security\Auth;

/**
 * Délégations : un délégant confie une fonction QU'IL EXERCE à un autre compte, pour une période et un motif.
 * Les actes passés par le délégataire sont tracés « par délégation de … » (voir ApprovalService).
 */
final class DelegationService
{
    public function __construct(
        private readonly Database $db,
        private readonly DelegationRepository $delegations,
        private readonly UserRepository $users,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    public function create(array $input): int
    {
        $data = Validator::validate($input, [
            'from_user_id' => 'required|int',
            'to_user_id' => 'required|int',
            'role' => 'required|in:' . implode(',', array_diff(Role::values(), [Role::Admin->value])),
            'site_id' => 'int',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date',
            'reason' => 'required|string|max:255',
        ]);
        if ($data['from_user_id'] === $data['to_user_id']) {
            throw Validator::fail('to_user_id', 'delegation_self');
        }
        if ($data['ends_on'] < $data['starts_on']) {
            throw Validator::fail('ends_on', 'period_order');
        }
        // Le périmètre de la personne qui enregistre doit couvrir le site (tous les sites = périmètre global).
        $scope = $this->delegations->scope();
        if ($data['site_id'] === null ? !$scope->isAll() : !$scope->allows($data['site_id'])) {
            throw Validator::fail('site_id', 'exists');
        }
        $active = array_map('intval', array_column($this->users->listActive(), 'id'));
        foreach (['from_user_id', 'to_user_id'] as $field) {
            if (!in_array($data[$field], $active, true)) {
                throw Validator::fail($field, 'exists');
            }
        }
        // On ne délègue que ce que l'on détient.
        if (!$this->users->holdsRole($data['from_user_id'], $data['role'], $data['site_id'])) {
            throw Validator::fail('role', 'delegation_not_held');
        }
        $data['created_by'] = $this->auth->user()?->id;

        return $this->db->transaction(function () use ($data): int {
            $id = $this->delegations->create($data);
            $this->logger->log('delegate', 'delegation', $id, $data['site_id'], null, $data, $data['role'] . ' : #' . $data['from_user_id'] . ' → #' . $data['to_user_id']);

            return $id;
        });
    }

    public function revoke(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $delegation = $this->delegations->find($id) ?? throw HttpException::notFound();
            if ($delegation['revoked_at'] !== null) {
                return;
            }
            $this->delegations->revoke($id, $this->auth->user()?->id);
            $this->logger->log(
                'delegation_revoke', 'delegation', $id, $delegation['site_id'] !== null ? (int) $delegation['site_id'] : null,
                ['revoked' => 0], ['revoked' => 1], $delegation['role'] . ' : #' . $delegation['from_user_id'] . ' → #' . $delegation['to_user_id']
            );
        });
    }
}
