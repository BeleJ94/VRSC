<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\Role;
use App\Repositories\ApprovalRepository;
use App\Security\Auth;
use App\Security\CurrentUser;

/**
 * Circuit d'approbation commun (EDB, transferts d'engins, transferts de stock, inventaires, décaissements).
 *
 * Règles du cahier des charges (§ 2.2) :
 *  - chaque étape est un acte distinct, identifié et daté ; l'avis hiérarchique et l'autorisation ne se substituent pas ;
 *  - séparation des fonctions : le demandeur ne valide jamais sa propre demande, et une même personne ne passe pas
 *    deux étapes d'une même demande ;
 *  - un acte se fait avec le rôle de l'étape, détenu sur le site de la demande (en propre ou par délégation tracée) ;
 *  - toute dérogation est motivée, réservée aux comptes habilités (approvals.override) et tracée.
 */
final class ApprovalService
{
    public const FAVORABLE = 'favorable';
    public const UNFAVORABLE = 'unfavorable';

    public function __construct(
        private readonly Database $db,
        private readonly ApprovalRepository $approvals,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Paramétrage d'un circuit. Étape : code, name, role, kind (opinion|control|authorization),
     * is_blocking (un avis défavorable arrête-t-il la demande ?), min_amount (seuil d'application).
     *
     * @param list<array{code: string, name: string, role: string, kind?: string, is_blocking?: bool, min_amount?: float|null}> $steps
     */
    public function defineFlow(string $code, string $name, array $steps): int
    {
        foreach ($steps as $step) {
            if (Role::tryFrom($step['role']) === null) {
                throw new \InvalidArgumentException("Rôle inconnu pour l'étape {$step['code']} : {$step['role']}");
            }
        }

        return $this->db->transaction(fn (): int => $this->approvals->defineFlow($code, $name, $steps));
    }

    /**
     * Ouvre une demande d'approbation pour une transaction.
     *
     * @return array{id: int, status: string} status = approved si aucune étape ne s'applique (montant sous les seuils)
     */
    public function start(string $flowCode, string $entityType, int $entityId, int $siteId, ?float $amount = null, ?string $reference = null): array
    {
        return $this->db->transaction(function () use ($flowCode, $entityType, $entityId, $siteId, $amount, $reference): array {
            $flow = $this->approvals->flowByCode($flowCode) ?? throw new \RuntimeException("Circuit d'approbation non paramétré : $flowCode");
            $first = $this->nextStep((int) $flow['id'], 0, $amount);
            $status = $first === null ? 'approved' : 'pending';
            $id = $this->approvals->createRequest([
                'flow_id' => (int) $flow['id'],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'site_id' => $siteId,
                'reference' => $reference,
                'amount' => $amount,
                'status' => $status,
                'current_step_id' => $first !== null ? (int) $first['id'] : null,
                'requested_by' => $this->auth->user()?->id,
                'closed_at' => $first === null ? date('Y-m-d H:i:s') : null,
            ]);
            $this->logger->log('approval_request', $entityType, $entityId, $siteId, null,
                ['flow' => $flowCode, 'amount' => $amount, 'status' => $status], $reference);

            return ['id' => $id, 'status' => $status];
        });
    }

    /**
     * Acte sur l'étape courante.
     *
     * @param array{decision?: string, comment?: string, override_reason?: string} $input
     * @return array{status: string, step: string, next_step: string|null} état de la demande après l'acte
     */
    public function act(int $requestId, array $input): array
    {
        $data = Validator::validate($input, [
            'decision' => 'required|in:' . self::FAVORABLE . ',' . self::UNFAVORABLE,
            'comment' => 'string|max:1000',
            'override_reason' => 'string|max:500',
        ]);
        if ($data['decision'] === self::UNFAVORABLE && $data['comment'] === null) {
            throw Validator::fail('comment', 'required');
        }
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');

        return $this->db->transaction(function () use ($requestId, $data, $user): array {
            $request = $this->approvals->findForUpdate($requestId) ?? throw HttpException::notFound();
            if ($request['status'] !== 'pending' || $request['current_step_id'] === null) {
                throw Validator::fail('decision', 'approval_closed');
            }
            $step = $this->approvals->step((int) $request['current_step_id']) ?? throw HttpException::notFound();
            $siteId = (int) $request['site_id'];

            // Séparation des fonctions : jamais sur sa propre demande, même par dérogation.
            if ($request['requested_by'] !== null && (int) $request['requested_by'] === $user->id) {
                throw Validator::fail('decision', 'approval_own_request');
            }
            [$assignment, $override] = $this->authority($user, $step, $siteId, $requestId, $data['override_reason']);

            $this->approvals->addAction([
                'request_id' => $requestId,
                'step_id' => (int) $step['id'],
                'step_code' => $step['code'],
                'step_kind' => $step['kind'],
                'decision' => $data['decision'],
                'comment' => $data['comment'],
                'acted_by' => $user->id,
                'actor_name' => $user->name(),
                'acted_role' => $assignment !== null ? $assignment['role']->value : $user->role->value,
                'delegated_from' => $assignment['delegated_from'] ?? null,
                'is_override' => $override ? 1 : 0,
                'override_reason' => $override ? $data['override_reason'] : null,
            ]);

            $next = null;
            if ($data['decision'] === self::UNFAVORABLE && (int) $step['is_blocking'] === 1) {
                $status = 'rejected';
            } else {
                $next = $this->nextStep((int) $request['flow_id'], (int) $step['position'], $request['amount'] !== null ? (float) $request['amount'] : null);
                $status = $next === null ? 'approved' : 'pending';
            }
            $this->approvals->updateRequest($requestId, $status, $next !== null ? (int) $next['id'] : null);

            $this->logger->log(
                'approval_' . $data['decision'],
                (string) $request['entity_type'],
                (int) $request['entity_id'],
                $siteId,
                null,
                ['step' => $step['code'], 'decision' => $data['decision'], 'status' => $status, 'override' => $override ? $data['override_reason'] : null,
                    'delegated_from' => $assignment['delegated_from'] ?? null, 'comment' => $data['comment']],
                $request['reference']
            );

            return ['status' => $status, 'step' => (string) $step['code'], 'next_step' => $next['code'] ?? null];
        });
    }

    /** Annulation motivée d'une demande en attente (demandeur ou compte habilité aux dérogations). */
    public function cancel(int $requestId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw Validator::fail('comment', 'required');
        }
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($requestId, $reason, $user): void {
            $request = $this->approvals->findForUpdate($requestId) ?? throw HttpException::notFound();
            if ($request['status'] !== 'pending') {
                throw Validator::fail('decision', 'approval_closed');
            }
            $own = $request['requested_by'] !== null && (int) $request['requested_by'] === $user->id;
            if (!$own && !$user->canAt('approvals.override', (int) $request['site_id'])) {
                throw HttpException::forbidden();
            }
            $this->approvals->updateRequest($requestId, 'cancelled', null);
            $this->logger->log('approval_cancel', (string) $request['entity_type'], (int) $request['entity_id'], (int) $request['site_id'],
                ['status' => 'pending'], ['status' => 'cancelled', 'reason' => mb_substr($reason, 0, 500)], $request['reference']);
        });
    }

    /** Demande et ses actes (null si hors périmètre ou inexistante). */
    public function detail(int $requestId): ?array
    {
        $request = $this->approvals->find($requestId);

        return $request === null ? null : $request + ['actions' => $this->approvals->actions($requestId)];
    }

    public function forEntity(string $entityType, int $entityId): ?array
    {
        $request = $this->approvals->latestForEntity($entityType, $entityId);

        return $request === null ? null : $request + ['actions' => $this->approvals->actions((int) $request['id'])];
    }

    /**
     * Demandes en attente que l'utilisateur connecté peut traiter avec ses propres rôles
     * (hors ses propres demandes et celles où il a déjà passé une étape).
     */
    public function inbox(): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            return [];
        }
        $out = [];
        foreach ($this->approvals->pending() as $request) {
            $role = Role::tryFrom((string) $request['step_role']);
            if ($role === null || $user->holds($role, (int) $request['site_id']) === null) {
                continue;
            }
            if ($request['requested_by'] !== null && (int) $request['requested_by'] === $user->id) {
                continue;
            }
            if ($this->alreadyActed((int) $request['id'], $user->id)) {
                continue;
            }
            $out[] = $request;
        }

        return $out;
    }

    /**
     * Habilitation utilisée pour l'acte, ou dérogation.
     *
     * @return array{0: array{role: Role, site_id: int|null, delegated_from: int|null}|null, 1: bool} [habilitation, dérogation ?]
     */
    private function authority(CurrentUser $user, array $step, int $siteId, int $requestId, ?string $overrideReason): array
    {
        $role = Role::tryFrom((string) $step['role']);
        $assignment = $role !== null ? $user->holds($role, $siteId) : null;
        $repeated = $this->alreadyActed($requestId, $user->id);
        if ($assignment !== null && !$repeated) {
            return [$assignment, false];
        }
        // Rôle non détenu, ou seconde étape par la même personne : dérogation motivée uniquement.
        if (!$user->canAt('approvals.override', $siteId)) {
            throw $assignment === null ? HttpException::forbidden() : Validator::fail('decision', 'approval_same_actor');
        }
        if ($overrideReason === null) {
            throw Validator::fail('override_reason', 'approval_override_reason');
        }

        return [$assignment, true];
    }

    private function alreadyActed(int $requestId, int $userId): bool
    {
        foreach ($this->approvals->actions($requestId) as $action) {
            if ($action['acted_by'] !== null && (int) $action['acted_by'] === $userId) {
                return true;
            }
        }

        return false;
    }

    /** Étape suivante applicable au montant (null = circuit terminé). */
    private function nextStep(int $flowId, int $afterPosition, ?float $amount): ?array
    {
        foreach ($this->approvals->steps($flowId) as $step) {
            if ((int) $step['position'] <= $afterPosition) {
                continue;
            }
            if ($step['min_amount'] !== null && ($amount === null || $amount < (float) $step['min_amount'])) {
                continue;
            }

            return $step;
        }

        return null;
    }
}
