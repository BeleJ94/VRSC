<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Relie le circuit d'approbation aux documents métier : un acte et sa conséquence sur le document
 * (transfert autorisé, écarts d'inventaire passés en stock…) forment une seule transaction.
 */
final class ApprovalWorkflow
{
    /** Type d'entité → service qui applique l'issue de l'approbation (méthode onApproval(int $id, string $status)). */
    private const HANDLERS = [
        StockTransferService::ENTITY => StockTransferService::class,
        StockInventoryService::ENTITY => StockInventoryService::class,
        PurchaseRequestService::ENTITY => PurchaseRequestService::class,
        FleetTransferService::ENTITY => FleetTransferService::class,
        PaymentService::ENTITY => PaymentService::class,
    ];

    /** Étapes « type:code » qui ne se valident pas par le formulaire générique mais par un acte métier (check-list). */
    public const EXTERNAL_STEPS = [FleetTransferService::ENTITY . ':control'];

    /** Type d'entité → page du document. */
    private const PAGES = [
        StockTransferService::ENTITY => '/stock/transfers/',
        StockInventoryService::ENTITY => '/stock/inventories/',
        PurchaseRequestService::ENTITY => '/edb/',
        FleetTransferService::ENTITY => '/fleet-transfers/',
        PaymentService::ENTITY => '/finance/payments/',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ApprovalService $approvals,
        private readonly Container $container,
    ) {
    }

    /** @return array{status: string, step: string, next_step: string|null, entity_type: string, entity_id: int} */
    /** @param bool $fromBusinessAct true quand l'acte vient du service métier de l'étape (ex. check-list du contrôle technique) */
    public function act(int $requestId, array $input, bool $fromBusinessAct = false): array
    {
        return $this->db->transaction(function () use ($requestId, $input, $fromBusinessAct): array {
            $request = $this->approvals->detail($requestId) ?? throw HttpException::notFound();
            if (!$fromBusinessAct && in_array($request['entity_type'] . ':' . $request['step_code'], self::EXTERNAL_STEPS, true)) {
                throw \App\Core\Validator::fail('decision', 'approval_external_step');
            }
            $result = $this->approvals->act($requestId, $input);
            if ($result['status'] !== 'pending') {
                $this->apply((string) $request['entity_type'], (int) $request['entity_id'], $result['status']);
            }

            return $result + ['entity_type' => (string) $request['entity_type'], 'entity_id' => (int) $request['entity_id']];
        });
    }

    public function cancel(int $requestId, string $reason): void
    {
        $this->db->transaction(function () use ($requestId, $reason): void {
            $request = $this->approvals->detail($requestId) ?? throw HttpException::notFound();
            $this->approvals->cancel($requestId, $reason);
            $this->apply((string) $request['entity_type'], (int) $request['entity_id'], 'cancelled');
        });
    }

    /** Page du document concerné par une demande (null si le type n'a pas d'écran). */
    public static function page(string $entityType, int $entityId): ?string
    {
        return isset(self::PAGES[$entityType]) ? self::PAGES[$entityType] . $entityId : null;
    }

    private function apply(string $entityType, int $entityId, string $status): void
    {
        if (isset(self::HANDLERS[$entityType])) {
            $this->container->get(self::HANDLERS[$entityType])->onApproval($entityId, $status);
        }
    }
}
