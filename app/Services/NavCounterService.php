<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\Auth;
use App\Security\SiteScope;

/**
 * Compteurs « à traiter » du menu : ce que l'utilisateur connecté doit faire, selon ses droits et ses sites.
 * Un compteur n'est calculé que pour celui qui peut agir dessus.
 */
final class NavCounterService
{
    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly ApprovalService $approvals,
        private readonly SiteScope $scope,
    ) {
    }

    /** @return array<string, int> approvals, edb, stock_transfers, fleet_transfers, payments (clés absentes = sans objet) */
    public function counts(): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            return [];
        }
        $counts = [];
        if ($user->can('approvals.use')) {
            // Validations que l'utilisateur peut passer lui-même (hors ses propres demandes).
            $counts['approvals'] = count($this->approvals->inbox());
        }
        if ($user->can('stock.move') || $user->can('purchase.manage')) {
            // États de besoins approuvés qui restent à servir (stock, transfert ou achat).
            $counts['edb'] = $this->count('purchase_requests', "status = 'approved'", 'site_id');
        }
        if ($user->can('stock.move')) {
            // Transferts de stock expédiés, à réceptionner sur un de ses sites.
            $counts['stock_transfers'] = $this->count('stock_transfers', "status = 'shipped'", 'to_site_id');
        }
        if ($user->can('fleet.dispatch') || $user->can('fleet.check')) {
            // Engins partis, attendus sur un de ses sites.
            $counts['fleet_transfers'] = $this->count('fleet_transfers', "status = 'departed'", 'to_site_id');
        }
        if ($user->can('finance.pay')) {
            // Décaissements approuvés, à payer.
            $counts['payments'] = $this->count('payment_requests', "status = 'approved'", 'site_id');
        }

        return $counts;
    }

    private function count(string $table, string $condition, string $siteColumn): int
    {
        [$scopeSql, $params] = $this->scope->clause($siteColumn);

        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM $table WHERE $condition AND $scopeSql", $params);
    }
}
