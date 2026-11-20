<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\EquipmentCostRepository;
use App\Repositories\ExecutiveDashboardRepository;
use App\Repositories\SiteRepository;
use App\Security\Auth;
use App\Security\CurrentUser;

/**
 * Tableaux de bord par profil : bandeau « À traiter » commun, vues Exécutive et Finance.
 * Chaque indicateur porte sa valeur, sa période de comparaison, son sens favorable et une série sur 6 mois.
 */
final class DashboardViewService
{
    /** Vue → permission requise. */
    public const VIEWS = [
        'executive' => 'dashboard.executive',
        'finance' => 'finance.view',
        'cash' => 'cash.manage',
        'purchasing' => 'dashboard.purchasing',
        'stores' => 'dashboard.stores',
        'maintenance' => 'equipment.view',
        'requests' => 'edb.create',
    ];

    /** Vues à période (indicateurs et tendances calculés sur la période choisie). */
    public const PERIOD_VIEWS = ['executive', 'finance', 'cash', 'purchasing', 'stores', 'maintenance', 'requests'];

    /** Natures de coût de maintenance (hors carburant et charges diverses). */
    private const MAINTENANCE_KINDS = ['parts', 'lubricants', 'tyres_filters', 'labor', 'external'];

    /** Vue par défaut selon le rôle principal. */
    private const DEFAULT_VIEW = [
        'director' => 'executive', 'logistics' => 'executive', 'admin' => 'executive',
        'finance' => 'finance', 'cashier' => 'cash', 'buyer' => 'purchasing', 'storekeeper' => 'stores',
        'requester' => 'requests', 'supervisor' => 'requests',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly ExecutiveDashboardRepository $exec,
        private readonly EquipmentCostRepository $costs,
        private readonly SiteRepository $sites,
        private readonly NavCounterService $counters,
        private readonly DashboardService $dashboard,
        private readonly ApprovalService $approvals,
    ) {
    }

    /** @return list<string> vues accessibles, dans l'ordre des onglets */
    public static function viewsFor(CurrentUser $user): array
    {
        return array_values(array_filter(array_keys(self::VIEWS), static fn (string $v): bool => $user->can(self::VIEWS[$v])));
    }

    public static function defaultView(CurrentUser $user, array $views): ?string
    {
        $preferred = self::DEFAULT_VIEW[$user->role->value] ?? 'maintenance';

        return in_array($preferred, $views, true) ? $preferred : ($views[0] ?? null);
    }

    /**
     * Bandeau « À traiter » : ce que l'utilisateur doit faire maintenant, du plus urgent au moins urgent.
     *
     * @return list<array{key: string, count: int, url: string, icon: string, level: string}>
     */
    public function todo(?int $siteId): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            return [];
        }
        $counts = $this->counters->counts();
        $items = [];
        $add = static function (string $key, int $count, string $url, string $icon, string $level) use (&$items): void {
            if ($count > 0) {
                $items[] = ['key' => $key, 'count' => $count, 'url' => $url, 'icon' => $icon, 'level' => $level];
            }
        };
        $add('approvals', $counts['approvals'] ?? 0, '/approvals', 'stamp', 'warn');
        $add('payments', $counts['payments'] ?? 0, '/finance/payments', 'banknote', 'warn');
        $add('edb', $counts['edb'] ?? 0, '/edb', 'file-pen-line', 'info');
        $add('stock_transfers', $counts['stock_transfers'] ?? 0, '/stock/transfers', 'arrow-left-right', 'info');
        $add('fleet_transfers', $counts['fleet_transfers'] ?? 0, '/fleet-transfers', 'route', 'info');
        if ($user->can('closings.view')) {
            $closing = $this->exec->closingStatus($siteId);
            $add('closings', $closing['expected'] - $closing['done'], '/closings', 'lock', 'bad');
        }
        if ($user->can('cash.manage')) {
            $add('cash_days', array_sum(array_map(static fn (array $r): int => (int) $r['open_days'], $this->exec->registers($siteId))), '/finance/cash', 'wallet', 'warn');
        }
        if ($user->can('equipment.view')) {
            $add('documents', $this->exec->expiredDocuments($siteId), '/documents?state=expired', 'file-x', 'bad');
        }
        $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($items, static fn (array $a, array $b): int => $rank[$a['level']] <=> $rank[$b['level']]);

        return $items;
    }

    /** Indicateur : valeur, valeur de comparaison, variation, sens favorable, série. */
    private static function kpi(float $value, ?float $previous, string $better, array $series = [], array $extra = []): array
    {
        $delta = $previous !== null && abs($previous) > 0.005 ? round(($value - $previous) / abs($previous) * 100, 1) : null;

        return $extra + ['value' => $value, 'previous' => $previous, 'delta' => $delta, 'better' => $better, 'series' => array_values($series)];
    }

    /** @param array<string, float> $byMonth @return list<float> valeurs sur les mois demandés (0 si absent) */
    private static function series(array $byMonth, array $months): array
    {
        return array_map(static fn (string $m): float => round((float) ($byMonth[$m] ?? 0), 2), $months);
    }

    /** Vue Exécutive : dépenses, coûts de flotte, disponibilité, stock, exécution des EDB, sites, engins coûteux, alertes. */
    public function executive(array $period, ?int $siteId): array
    {
        $trend = ExecutiveDashboardRepository::trendMonths($period['to']);
        $trendFrom = $trend[0] . '-01';
        $earliest = min($trendFrom, $period['prev_from']);

        $paid = $this->exec->paidByMonth($earliest, $period['to'], $siteId);
        $engaged = $this->exec->engagedByMonth($earliest, $period['to'], $siteId);
        $costMonths = array_column($this->costs->costsBetween($earliest, $period['to'], $siteId, 'month'), 'total', 'k');
        $inPeriod = fn (string $from, string $to, ?int $site, callable $fetch): float => ExecutiveDashboardRepository::sum($fetch($from, $to, $site));

        $paidNow = $inPeriod($period['from'], $period['to'], $siteId, fn ($f, $t, $s) => $this->exec->paidByMonth($f, $t, $s));
        $paidPrev = $inPeriod($period['prev_from'], $period['prev_to'], $siteId, fn ($f, $t, $s) => $this->exec->paidByMonth($f, $t, $s));
        $engNow = $inPeriod($period['from'], $period['to'], $siteId, fn ($f, $t, $s) => $this->exec->engagedByMonth($f, $t, $s));
        $engPrev = $inPeriod($period['prev_from'], $period['prev_to'], $siteId, fn ($f, $t, $s) => $this->exec->engagedByMonth($f, $t, $s));
        $costNow = (float) array_sum(array_column($this->costs->costsBetween($period['from'], $period['to'], $siteId, 'month'), 'total'));
        $costPrev = (float) array_sum(array_column($this->costs->costsBetween($period['prev_from'], $period['prev_to'], $siteId, 'month'), 'total'));
        $costMissing = (int) array_sum(array_column($this->costs->costsBetween($period['from'], $period['to'], $siteId, 'month'), 'missing'));

        $fleet = $this->exec->fleetBySite($siteId);
        $fleetTotal = array_sum(array_map(static fn (array $f): int => (int) $f['total'], $fleet));
        $fleetAvailable = array_sum(array_map(static fn (array $f): int => (int) $f['available'], $fleet));
        $stock = $this->exec->stockValueBySite($siteId);
        $edb = $this->exec->edbExecution($period['from'], $period['to'], $siteId);
        $edbPrev = $this->exec->edbExecution($period['prev_from'], $period['prev_to'], $siteId);
        $rate = static fn (array $e): ?float => (int) $e['issued'] - (int) $e['cancelled'] > 0 ? round((int) $e['executed'] / ((int) $e['issued'] - (int) $e['cancelled']) * 100, 1) : null;

        // Comparaison des sites.
        $spendBySite = $this->exec->spendBySite($period['from'], $period['to'], $siteId);
        $costBySite = array_column($this->costs->costsBetween($period['from'], $period['to'], $siteId, 'site'), 'total', 'k');
        $openEdb = array_column($this->db->fetchAll(
            "SELECT site_id, COUNT(*) AS n FROM purchase_requests WHERE status IN ('pending', 'approved') GROUP BY site_id"
        ), 'n', 'site_id');
        $sites = [];
        foreach ($this->sites->listActive() as $site) {
            $id = (int) $site['id'];
            if ($siteId !== null && $id !== $siteId) {
                continue;
            }
            $f = $fleet[$id] ?? null;
            $sites[] = [
                'id' => $id, 'name' => $site['name'],
                'equipment' => (int) ($f['total'] ?? 0),
                'availability' => $f !== null && (int) $f['total'] > 0 ? round((int) $f['available'] / (int) $f['total'] * 100, 1) : null,
                'cost' => (float) ($costBySite[$id] ?? 0),
                'paid' => $spendBySite[$id]['paid'] ?? 0.0,
                'engaged' => $spendBySite[$id]['engaged'] ?? 0.0,
                'stock' => $stock[$id] ?? 0.0,
                'open_edb' => (int) ($openEdb[$id] ?? 0),
            ];
        }

        $pipeline = $this->exec->paymentPipeline($siteId);
        $outOfService = array_sum(array_map(static fn (array $f): int => (int) $f['out_of_service'], $fleet));
        $lastMonth = date('Y-m', (int) strtotime(date('Y-m-01') . ' -1 month'));
        $lastMonthTotals = $this->costs->monthlyTotals($lastMonth, $siteId);

        return [
            'kpis' => [
                'engaged' => self::kpi($engNow, $engPrev, 'neutral', self::series($engaged, $trend)),
                'paid' => self::kpi($paidNow, $paidPrev, 'neutral', self::series($paid, $trend)),
                'fleet_cost' => self::kpi($costNow, $costPrev, 'down', self::series($costMonths, $trend), ['missing' => $costMissing]),
                'availability' => self::kpi($fleetTotal > 0 ? round($fleetAvailable / $fleetTotal * 100, 1) : 0, null, 'up', [],
                    ['available' => $fleetAvailable, 'total' => $fleetTotal]),
                'stock_value' => self::kpi(round(array_sum($stock), 2), null, 'neutral'),
                'edb_rate' => self::kpi((float) ($rate($edb) ?? 0), $rate($edbPrev), 'up', [],
                    ['issued' => (int) $edb['issued'], 'executed' => (int) $edb['executed'], 'open' => (int) $edb['open'], 'empty' => $rate($edb) === null]),
            ],
            'trend_months' => $trend,
            'sites' => $sites,
            'top_equipment' => $this->costs->costsBetween($period['from'], $period['to'], $siteId, 'equipment', 5),
            'closing' => $this->exec->closingStatus($siteId),
            'alerts' => array_values(array_filter([
                ['key' => 'documents', 'count' => $this->exec->expiredDocuments($siteId), 'url' => '/documents?state=expired', 'level' => 'bad', 'icon' => 'file-x'],
                ['key' => 'fuel_alerts', 'count' => (int) ($lastMonthTotals['alert_count'] ?? 0), 'url' => '/costs?flag=alert&period=' . $lastMonth, 'level' => 'warn', 'icon' => 'fuel', 'period' => $lastMonth],
                ['key' => 'cost_missing', 'count' => $costMissing, 'url' => '/costs?flag=missing', 'level' => 'warn', 'icon' => 'circle-help'],
                ['key' => 'advances', 'count' => (float) ($pipeline['advances'] ?? 0) > 0.005 ? 1 : 0, 'amount' => (float) ($pipeline['advances'] ?? 0), 'url' => '/finance/payments', 'level' => 'warn', 'icon' => 'hand-coins'],
                ['key' => 'out_of_service', 'count' => $outOfService, 'url' => '/equipment?status=out_of_service', 'level' => 'info', 'icon' => 'ban'],
                ['key' => 'in_transit', 'count' => $this->exec->fleetInTransit($siteId), 'url' => '/fleet-transfers', 'level' => 'info', 'icon' => 'route'],
            ], static fn (array $a): bool => $a['count'] > 0)),
        ];
    }

    /** Vue Maintenance (compacte) : disponibilité, engins arrêtés, OT, préventif, MTTR, coût de maintenance ; semaines, arrêts, échéances. */
    public function maintenance(array $period, ?int $siteId): array
    {
        $stats = $this->dashboard->data($siteId);
        $trend = ExecutiveDashboardRepository::trendMonths($period['to']);
        $earliest = min($trend[0] . '-01', $period['prev_from']);
        $costMonths = array_column($this->costs->costsBetween($earliest, $period['to'], $siteId, 'month', 0, self::MAINTENANCE_KINDS), 'total', 'k');
        $cost = fn (string $from, string $to): float => (float) array_sum(array_column($this->costs->costsBetween($from, $to, $siteId, 'month', 0, self::MAINTENANCE_KINDS), 'total'));
        $done = $this->exec->workOrdersDoneByMonth($earliest, $period['to'], $siteId);
        $doneSum = fn (string $from, string $to): float => ExecutiveDashboardRepository::sum($this->exec->workOrdersDoneByMonth($from, $to, $siteId));
        $eq = $stats['equipment'];
        $down = (int) $eq['by_status']['breakdown'] + (int) $eq['by_status']['maintenance'] + (int) $eq['by_status']['waiting_part'] + (int) $eq['by_status']['immobilized'];
        $wo = $stats['work_orders'];

        return [
            'kpis' => [
                'availability' => self::kpi((float) ($eq['availability'] ?? 0), null, 'up', [], ['available' => (int) $eq['by_status']['available'], 'total' => (int) $eq['total']]),
                'down' => self::kpi((float) $down, null, 'down', [], ['breakdown' => (int) $eq['by_status']['breakdown'], 'pending_release' => (int) ($eq['by_status']['pending_release'] ?? 0)]),
                'open' => self::kpi((float) $wo['open'], null, 'down', [], ['critical' => (int) $wo['critical'], 'overdue' => (int) $wo['overdue']]),
                'done' => self::kpi($doneSum($period['from'], $period['to']), $doneSum($period['prev_from'], $period['prev_to']), 'neutral', self::series($done, $trend)),
                'preventive' => self::kpi((float) $stats['preventive_due'], null, 'down'),
                'cost' => self::kpi($cost($period['from'], $period['to']), $cost($period['prev_from'], $period['prev_to']), 'down', self::series($costMonths, $trend)),
            ],
            'mttr' => $wo['mttr_hours'],
            'trend_months' => $trend,
            'weekly' => $stats['weekly'],
            'down_list' => $this->exec->equipmentDown($siteId),
            'preventive' => $this->exec->upcomingPreventive($siteId),
            'meters' => $stats['meters'],
        ];
    }

    /** Vue Caisse : trésorerie, décaissements à payer, payés, mouvements du jour, journées à arrêter, écarts ; caisses, listes. */
    public function cash(array $period, ?int $siteId): array
    {
        $trend = ExecutiveDashboardRepository::trendMonths($period['to']);
        $earliest = min($trend[0] . '-01', $period['prev_from']);
        $paid = $this->exec->paidByMonth($earliest, $period['to'], $siteId);
        $registers = $this->exec->registers($siteId);
        $today = $this->exec->cashToday($siteId);
        $pipeline = $this->exec->paymentPipeline($siteId);
        $gaps = $this->exec->cashGaps($period['from'], $period['to'], $siteId);
        foreach ($registers as &$r) {
            $r['today_in'] = $today[(int) $r['id']]['in'] ?? 0.0;
            $r['today_out'] = $today[(int) $r['id']]['out'] ?? 0.0;
        }
        unset($r);

        return [
            'kpis' => [
                'cash' => self::kpi(round(array_sum(array_column($registers, 'balance')), 2), null, 'neutral', [], ['registers' => count($registers)]),
                'to_pay' => self::kpi((float) ($pipeline['to_pay_amount'] ?? 0), null, 'down', [], ['count' => (int) ($pipeline['to_pay_count'] ?? 0)]),
                'paid' => self::kpi(ExecutiveDashboardRepository::sum($this->exec->paidByMonth($period['from'], $period['to'], $siteId)),
                    ExecutiveDashboardRepository::sum($this->exec->paidByMonth($period['prev_from'], $period['prev_to'], $siteId)), 'neutral', self::series($paid, $trend)),
                'today_out' => self::kpi(round(array_sum(array_column($registers, 'today_out')), 2), null, 'neutral', [], ['in' => round(array_sum(array_column($registers, 'today_in')), 2)]),
                'open_days' => self::kpi((float) array_sum(array_map(static fn (array $r): int => (int) $r['open_days'], $registers)), null, 'down'),
                'gaps' => self::kpi(round((float) $gaps['amount'], 2), null, 'neutral', [], ['days' => (int) $gaps['days']]),
            ],
            'trend_months' => $trend,
            'registers' => $registers,
            'to_pay' => $this->exec->paymentsList($siteId, 'approved', 8),
            'paid_list' => $this->exec->paymentsList($siteId, 'paid', 6),
        ];
    }

    /** Vue « Mes demandes » : états de besoins et décaissements du compte connecté, avis à donner. */
    public function requests(array $period, ?int $siteId): array
    {
        $user = $this->auth->user();
        $userId = $user?->id ?? 0;
        $trend = ExecutiveDashboardRepository::trendMonths($period['to']);
        $mine = $this->exec->myRequests($userId, $period['from'], $period['to']);
        $prev = $this->exec->myRequests($userId, $period['prev_from'], $period['prev_to']);
        $executed = $this->exec->myExecutedByMonth($userId, min($trend[0] . '-01', $period['prev_from']), $period['to']);

        return [
            'kpis' => [
                'pending' => self::kpi((float) $mine['pending'], null, 'down'),
                'approved' => self::kpi((float) $mine['approved'], null, 'down'),
                'executed' => self::kpi((float) $mine['executed'], (float) $prev['executed'], 'up', self::series($executed, $trend)),
                'lead' => self::kpi((float) ($mine['lead'] ?? 0), $prev['lead'], 'down', [], ['empty' => $mine['lead'] === null]),
                'approvals' => self::kpi((float) ($user?->can('approvals.use') ? count($this->approvals->inbox()) : 0), null, 'down', [], ['allowed' => (bool) $user?->can('approvals.use')]),
                'cancelled' => self::kpi((float) $mine['cancelled'], null, 'down'),
            ],
            'trend_months' => $trend,
            'recent' => $this->exec->myRecentRequests($userId),
            'payments' => $user?->can('finance.request') ? $this->exec->paymentsList(null, 'any', 6, $userId) : null,
            'user_id' => $userId,
        ];
    }

    /** Vue Magasin : valeur du stock, sorties et entrées, niveaux, EDB à servir, réceptions attendues ; cuves, transferts. */
    public function stores(array $period, ?int $siteId): array
    {
        $trend = ExecutiveDashboardRepository::trendMonths($period['to']);
        $earliest = min($trend[0] . '-01', $period['prev_from']);
        $outs = $this->exec->movementsByMonth('out', $earliest, $period['to'], $siteId);
        $ins = $this->exec->movementsByMonth('in', $earliest, $period['to'], $siteId);
        $sum = fn (string $dir, string $from, string $to): float => ExecutiveDashboardRepository::sum($this->exec->movementsByMonth($dir, $from, $to, $siteId));
        $levels = $this->exec->stockLevels($siteId);
        $edb = $this->exec->edbToServe($siteId);
        $orders = $this->exec->openOrders($siteId);
        $tanks = array_map(static function (array $t): array {
            $daily = (float) $t['daily'];
            $t['autonomy'] = $daily > 0.01 ? round((float) $t['quantity'] / $daily, 1) : null;

            return $t;
        }, $this->exec->fuelTanks($siteId));

        return [
            'kpis' => [
                'stock_value' => self::kpi(round(array_sum($this->exec->stockValueBySite($siteId)), 2), null, 'neutral'),
                'outs' => self::kpi($sum('out', $period['from'], $period['to']), $sum('out', $period['prev_from'], $period['prev_to']), 'neutral', self::series($outs, $trend)),
                'ins' => self::kpi($sum('in', $period['from'], $period['to']), $sum('in', $period['prev_from'], $period['prev_to']), 'neutral', self::series($ins, $trend)),
                'levels' => self::kpi((float) ((int) $levels['low'] + (int) $levels['out_of_stock']), null, 'down', [], ['low' => (int) $levels['low'], 'out' => (int) $levels['out_of_stock']]),
                'edb' => self::kpi((float) $edb['count'], null, 'down'),
                'orders' => self::kpi((float) $orders['count'], null, 'neutral', [], ['amount' => $orders['amount'], 'late' => $orders['late']]),
            ],
            'trend_months' => $trend,
            'tanks' => $tanks,
            'edb' => $edb['rows'],
            'orders' => $orders['rows'],
            'transfers' => $this->exec->openTransfers($siteId),
        ];
    }

    /** Vue Achats : EDB émis, en circuit, à acheter, commandes en cours et en retard, délai EDB → commande ; fournisseurs, pipeline. */
    public function purchasing(array $period, ?int $siteId): array
    {
        $trend = ExecutiveDashboardRepository::trendMonths($period['to']);
        $earliest = min($trend[0] . '-01', $period['prev_from']);
        $issued = $this->exec->edbIssuedByMonth($earliest, $period['to'], $siteId);
        $count = fn (string $from, string $to): float => ExecutiveDashboardRepository::sum($this->exec->edbIssuedByMonth($from, $to, $siteId));
        $engaged = $this->exec->engagedByMonth($earliest, $period['to'], $siteId);
        $engNow = ExecutiveDashboardRepository::sum($this->exec->engagedByMonth($period['from'], $period['to'], $siteId));
        $engPrev = ExecutiveDashboardRepository::sum($this->exec->engagedByMonth($period['prev_from'], $period['prev_to'], $siteId));
        $status = $this->exec->edbByStatus($siteId);
        $orders = $this->exec->openOrders($siteId);
        $lead = $this->exec->leadDays($period['from'], $period['to'], $siteId);
        $leadPrev = $this->exec->leadDays($period['prev_from'], $period['prev_to'], $siteId);

        return [
            'kpis' => [
                'issued' => self::kpi($count($period['from'], $period['to']), $count($period['prev_from'], $period['prev_to']), 'neutral', self::series($issued, $trend)),
                'pending' => self::kpi((float) $status['pending'], null, 'down'),
                'to_buy' => self::kpi((float) $this->exec->edbToBuy($siteId), null, 'down'),
                'orders' => self::kpi($orders['amount'], null, 'neutral', [], ['count' => $orders['count'], 'late' => $orders['late']]),
                'engaged' => self::kpi($engNow, $engPrev, 'neutral', self::series($engaged, $trend)),
                'lead' => self::kpi((float) ($lead ?? 0), $leadPrev, 'down', self::series($this->exec->leadDaysByMonth($earliest, $period['to'], $siteId), $trend), ['empty' => $lead === null]),
            ],
            'trend_months' => $trend,
            'suppliers' => $this->exec->suppliersRanking($period['from'], $period['to'], $siteId),
            'pipeline' => $this->exec->edbByStatus($siteId, $period['from'], $period['to']),
            'orders' => $orders['rows'],
        ];
    }

    /** Vue Finance : engagé / facturé / payé, restes à payer, trésorerie, dépenses par catégorie et par site, caisses, clôtures. */
    public function finance(array $period, ?int $siteId): array
    {
        $trend = ExecutiveDashboardRepository::trendMonths($period['to']);
        $earliest = min($trend[0] . '-01', $period['prev_from']);
        $paid = $this->exec->paidByMonth($earliest, $period['to'], $siteId);
        $engaged = $this->exec->engagedByMonth($earliest, $period['to'], $siteId);
        $invoiced = $this->exec->invoicedByMonth($earliest, $period['to'], $siteId);
        $exact = fn (callable $fetch, string $from, string $to): float => ExecutiveDashboardRepository::sum($fetch($from, $to, $siteId));
        $paidNow = $exact(fn ($f, $t, $s) => $this->exec->paidByMonth($f, $t, $s), $period['from'], $period['to']);
        $paidPrev = $exact(fn ($f, $t, $s) => $this->exec->paidByMonth($f, $t, $s), $period['prev_from'], $period['prev_to']);
        $engNow = $exact(fn ($f, $t, $s) => $this->exec->engagedByMonth($f, $t, $s), $period['from'], $period['to']);
        $engPrev = $exact(fn ($f, $t, $s) => $this->exec->engagedByMonth($f, $t, $s), $period['prev_from'], $period['prev_to']);
        $invNow = $exact(fn ($f, $t, $s) => $this->exec->invoicedByMonth($f, $t, $s), $period['from'], $period['to']);
        $invPrev = $exact(fn ($f, $t, $s) => $this->exec->invoicedByMonth($f, $t, $s), $period['prev_from'], $period['prev_to']);

        $unpaid = $this->exec->unpaidInvoices($siteId);
        $pipeline = $this->exec->paymentPipeline($siteId);
        $registers = $this->exec->registers($siteId);
        $spendBySite = $this->exec->spendBySite($period['from'], $period['to'], $siteId);
        $siteNames = array_column($this->sites->listActive(), 'name', 'id');
        $bySite = [];
        foreach ($spendBySite as $id => $row) {
            if (isset($siteNames[$id])) {
                $bySite[] = ['id' => (int) $id, 'name' => $siteNames[$id]] + $row;
            }
        }
        usort($bySite, static fn (array $a, array $b): int => $b['engaged'] <=> $a['engaged']);

        return [
            'kpis' => [
                'engaged' => self::kpi($engNow, $engPrev, 'neutral', self::series($engaged, $trend)),
                'invoiced' => self::kpi($invNow, $invPrev, 'neutral', self::series($invoiced, $trend)),
                'paid' => self::kpi($paidNow, $paidPrev, 'neutral', self::series($paid, $trend)),
                'unpaid' => self::kpi((float) $unpaid['amount'], null, 'down', [], ['count' => (int) $unpaid['count'], 'overdue_count' => (int) $unpaid['overdue_count'], 'overdue_amount' => (float) $unpaid['overdue_amount']]),
                'to_pay' => self::kpi((float) ($pipeline['to_pay_amount'] ?? 0), null, 'down', [], ['count' => (int) ($pipeline['to_pay_count'] ?? 0), 'pending_count' => (int) ($pipeline['pending_count'] ?? 0), 'pending_amount' => (float) ($pipeline['pending_amount'] ?? 0)]),
                'cash' => self::kpi(round(array_sum(array_map(static fn (array $r): float => (float) $r['balance'], $registers)), 2), null, 'neutral', [], ['registers' => count($registers)]),
            ],
            'trend_months' => $trend,
            'categories' => $this->exec->spendByCategory($period['from'], $period['to'], $siteId),
            'sites' => $bySite,
            'registers' => $registers,
            'advances' => (float) ($pipeline['advances'] ?? 0),
            'closing' => $this->exec->closingStatus($siteId),
        ];
    }
}
