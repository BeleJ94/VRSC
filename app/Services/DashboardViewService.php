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
        'maintenance' => 'equipment.view',
    ];

    /** Vue par défaut selon le rôle principal. */
    private const DEFAULT_VIEW = [
        'director' => 'executive', 'logistics' => 'executive', 'admin' => 'executive',
        'finance' => 'finance', 'cashier' => 'finance', 'buyer' => 'finance',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly ExecutiveDashboardRepository $exec,
        private readonly EquipmentCostRepository $costs,
        private readonly SiteRepository $sites,
        private readonly NavCounterService $counters,
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
                $bySite[] = ['name' => $siteNames[$id]] + $row;
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
