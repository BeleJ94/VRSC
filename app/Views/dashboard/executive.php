<?php
/**
 * Vue Exécutive : dépenses, coût de la flotte, disponibilité, stock, exécution des EDB ; comparaison des sites,
 * engins les plus coûteux, clôtures, alertes. Chaque chiffre ouvre son détail (tableau exportable) ou sa fiche.
 * @var array $dash  @var array $period  @var int|null $siteId
 */
$k = $dash['kpis'];
$q = $siteId !== null ? ['site_id' => $siteId] : [];
$from = $period['from'];
$to = $period['to'];
$months = $dash['trend_months'];
$monthOf = substr($to, 0, 7);
$bounds = static fn (string $m): array => ['from' => $m . '-01', 'to' => date('Y-m-t', (int) strtotime($m . '-01'))];
$kpi = static fn (array $args): string => $view->partial('dashboard/_kpi', $args);
$asAttr = static fn (array $spec): string => ' data-drill="' . e(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
$commitments = static fn (array $p, string $title): array => drill_spec('drill_commitments', '/dashboard/drill/commitments', $title, $p + $q, '/finance/reports', '{!link}', [], false);
$payments = static fn (array $p, string $title, array $chips = []): array => drill_spec('drill_payments', '/dashboard/drill/payments', $title, $p + $q, '/finance/payments', '{!link}', $chips, false);
$costLines = static fn (array $p, string $title, array $chips = []): array => drill_spec('drill_cost_lines', '/dashboard/drill/cost-lines', $title, $p + $q, '/costs', '{!link}', $chips, false);
$equipmentChips = [['label' => t('common.all'), 'params' => []]];
foreach (\App\Domain\EquipmentStatus::cases() as $st) {
    $equipmentChips[] = ['label' => t($st->labelKey()), 'params' => ['status' => $st->value]];
}
$edbChips = [['label' => t('common.all'), 'params' => []]];
foreach (\App\Repositories\PurchaseRepository::DISPLAY_STATUSES as $st) {
    $edbChips[] = ['label' => t('edb_status.' . $st), 'params' => ['status' => $st]];
}
$isMonth = in_array($period['key'], ['month', 'last_month'], true);
$avail = $k['availability'];
$availTone = $avail['total'] === 0 ? null : ($avail['value'] >= 85 ? 'good' : ($avail['value'] >= 70 ? 'warn' : 'bad'));
$maxTop = max(0.01, ...array_map(static fn (array $r): float => (float) $r['total'], $dash['top_equipment'] ?: [['total' => 0]]));
$maxSiteCost = max(0.01, ...array_map(static fn (array $s): float => $s['cost'], $dash['sites'] ?: [['cost' => 0]]));
$alertLabels = ['documents' => 'dashboard.alert_documents', 'fuel_alerts' => 'dashboard.alert_fuel', 'cost_missing' => 'dashboard.alert_cost_missing',
    'advances' => 'dashboard.alert_advances', 'out_of_service' => 'dashboard.alert_out_of_service', 'in_transit' => 'dashboard.alert_in_transit'];
$alertDrills = [
    'documents' => drill_spec('documents', '/documents/data', t('dashboard.alert_documents_title'), ['state' => 'expired'] + $q, '/documents', '/equipment/{equipment_id}'),
    'fuel_alerts' => drill_spec('equipment_costs', '/costs/data', t('dashboard.alert_fuel_title'), ['flag' => 'alert', 'period' => date('Y-m', (int) strtotime(date('Y-m-01') . ' -1 month'))] + $q, '/costs', '/costs/{id}'),
    'cost_missing' => $costLines(['missing' => '1', 'from' => $from, 'to' => $to], t('dashboard.alert_cost_missing_title')),
    'advances' => $payments(['kind' => 'advance', 'status' => 'paid'], t('dashboard.alert_advances_title')),
    'out_of_service' => drill_spec('equipment', '/equipment/data', t('status.out_of_service'), ['status' => 'out_of_service'] + $q, '/equipment', '/equipment/{id}'),
];
$closing = $dash['closing'];

// Rapport PDF de direction (une page) : mêmes chiffres que l'écran, déjà formatés.
$tone = static function (array $kpi): string {
    $d = $kpi['delta'];
    if ($d === null || abs($d) < 0.5) {
        return 'flat';
    }

    return $kpi['better'] === 'neutral' ? 'neutral' : ((($d > 0) === ($kpi['better'] === 'up')) ? 'good' : 'bad');
};
$deltaText = static fn (array $kpi): ?string => $kpi['delta'] !== null ? ($kpi['delta'] > 0 ? '+' : '') . format_number($kpi['delta'], 1) . ' %' : null;
$siteName = '';
foreach ($dash['sites'] as $s) {
    if ($siteId !== null && $s['id'] === $siteId) {
        $siteName = $s['name'];
    }
}
$periodLabels = ['month' => 'dashboard.period_month', 'last_month' => 'dashboard.period_last_month', 'quarter' => 'dashboard.period_quarter', 'year' => 'dashboard.period_year'];
$report = [
    'title' => t('dashboard.report_title'),
    'subtitle' => t($periodLabels[$period['key']]) . ' · ' . format_date($from) . ' – ' . format_date($to) . ' · ' . ($siteName !== '' ? $siteName : t('common.all_sites')),
    'generated' => t('dashboard.report_generated', ['date' => format_date(date('Y-m-d H:i:s'), true), 'name' => $currentUser->name()]),
    'confidential' => t('js.drill.confidential'),
    'filename' => 'rapport-direction-' . $period['key'] . '-' . date('Ymd'),
    'kpis' => [
        ['label' => t('dashboard.kpi_engaged'), 'value' => format_money($k['engaged']['value']), 'delta' => $deltaText($k['engaged']), 'tone' => $tone($k['engaged']), 'hint' => t('dashboard.hint_engaged'), 'series' => $k['engaged']['series']],
        ['label' => t('dashboard.kpi_paid'), 'value' => format_money($k['paid']['value']), 'delta' => $deltaText($k['paid']), 'tone' => $tone($k['paid']), 'hint' => t('dashboard.hint_paid'), 'series' => $k['paid']['series']],
        ['label' => t('dashboard.kpi_fleet_cost'), 'value' => format_money($k['fleet_cost']['value']), 'delta' => $deltaText($k['fleet_cost']), 'tone' => $tone($k['fleet_cost']),
            'hint' => $k['fleet_cost']['missing'] > 0 ? t('dashboard.hint_missing', ['count' => $k['fleet_cost']['missing']]) : t('dashboard.hint_fleet_cost'), 'series' => $k['fleet_cost']['series']],
        ['label' => t('dashboard.availability'), 'value' => format_number($avail['value'], 1) . ' %', 'delta' => null, 'tone' => $availTone ?? 'flat',
            'hint' => t('dashboard.available_of', ['available' => $avail['available'], 'total' => $avail['total']]), 'series' => []],
        ['label' => t('dashboard.kpi_stock_value'), 'value' => format_money($k['stock_value']['value']), 'delta' => null, 'tone' => 'flat', 'hint' => t('dashboard.hint_stock_value'), 'series' => []],
        ['label' => t('dashboard.kpi_edb_rate'), 'value' => $k['edb_rate']['empty'] ? '—' : format_number($k['edb_rate']['value'], 0) . ' %', 'delta' => $deltaText($k['edb_rate']), 'tone' => $tone($k['edb_rate']),
            'hint' => t('dashboard.hint_edb_rate', ['executed' => $k['edb_rate']['executed'], 'issued' => $k['edb_rate']['issued']]), 'series' => []],
    ],
    'sites' => [
        'title' => t('dashboard.sites_title'),
        'headers' => [t('fields.site_id'), t('dashboard.col_availability'), t('dashboard.col_fleet_cost'), t('dashboard.col_paid'), t('dashboard.col_stock'), t('dashboard.col_open_edb')],
        'rows' => array_map(static fn (array $s): array => [
            $s['name'], $s['availability'] !== null ? format_number($s['availability'], 0) . ' %' : '—', format_money($s['cost']), format_money($s['paid']), format_money($s['stock']), (string) $s['open_edb'],
            $s['availability'] === null ? '' : ($s['availability'] >= 85 ? 'good' : ($s['availability'] >= 70 ? 'warn' : 'bad')),
        ], $dash['sites']),
    ],
    'top' => [
        'title' => t('dashboard.top_equipment'),
        'headers' => ['#', t('fields.equipment_id'), t('fields.site_id'), t('dashboard.kpi_fleet_cost')],
        'rows' => array_map(static fn (array $r, int $i): array => [(string) ($i + 1), $r['code'] . ' — ' . $r['name'], $r['site'], format_money($r['total']) . ((int) $r['missing'] > 0 ? ' (!)' : '')],
            $dash['top_equipment'], array_keys($dash['top_equipment'])),
        'note' => t('dashboard.top_equipment_hint'),
    ],
    'alerts' => [
        'title' => t('dashboard.alerts_title'),
        'empty' => t('dashboard.no_alert'),
        'rows' => array_map(static fn (array $a): array => [t($alertLabels[$a['key']], ['count' => $a['count'], 'amount' => format_money($a['amount'] ?? 0), 'period' => $a['period'] ?? '']), $a['level']], $dash['alerts']),
    ],
    'trend' => [
        'title' => t('dashboard.report_trend'),
        'headers' => [t('dashboard.report_month'), t('dashboard.kpi_engaged'), t('dashboard.kpi_paid'), t('dashboard.kpi_fleet_cost')],
        'rows' => array_map(static fn (string $m, int $i): array => [
            $m, format_money($k['engaged']['series'][$i] ?? 0), format_money($k['paid']['series'][$i] ?? 0), format_money($k['fleet_cost']['series'][$i] ?? 0),
        ], $months, array_keys($months)),
    ],
    'closing' => ['title' => t('dashboard.closing_title', ['period' => $closing['period']]), 'done' => $closing['done'], 'expected' => $closing['expected'],
        'text' => t('dashboard.report_closing', ['done' => $closing['done'], 'expected' => $closing['expected']])],
];
?>
<script type="application/json" id="exec-report"><?= json_for_html($report) ?></script>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.kpi_engaged'), 'value' => e(format_money($k['engaged']['value'])), 'kpi' => $k['engaged'], 'url' => '/finance/reports', 'hint' => t('dashboard.hint_engaged'),
        'drill' => $commitments(['from' => $from, 'to' => $to], t('dashboard.kpi_engaged')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $commitments($bounds($m), t('dashboard.kpi_engaged') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_paid'), 'value' => e(format_money($k['paid']['value'])), 'kpi' => $k['paid'], 'url' => '/finance/payments', 'hint' => t('dashboard.hint_paid'),
        'drill' => $payments(['status' => 'paid', 'date_field' => 'paid', 'from' => $from, 'to' => $to], t('dashboard.kpi_paid')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $payments(['status' => 'paid', 'date_field' => 'paid'] + $bounds($m), t('dashboard.kpi_paid') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_fleet_cost'), 'value' => e(format_money($k['fleet_cost']['value'])), 'kpi' => $k['fleet_cost'], 'url' => '/costs?period=' . $monthOf,
        'hint' => $k['fleet_cost']['missing'] > 0 ? t('dashboard.hint_missing', ['count' => $k['fleet_cost']['missing']]) : t('dashboard.hint_fleet_cost'),
        'tone' => $k['fleet_cost']['missing'] > 0 ? 'warn' : null,
        'drill' => $costLines(['from' => $from, 'to' => $to], t('dashboard.kpi_fleet_cost'), [['label' => t('common.all'), 'params' => []], ['label' => t('dashboard.chip_missing'), 'params' => ['missing' => '1']]]),
        'months' => $months, 'monthDrill' => static fn (string $m): array => $costLines($bounds($m), t('dashboard.kpi_fleet_cost') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.availability'), 'value' => e(format_number($avail['value'], 1)) . '<small>%</small>', 'kpi' => $avail, 'url' => '/equipment',
        'hint' => t('dashboard.available_of', ['available' => $avail['available'], 'total' => $avail['total']]), 'tone' => $availTone,
        'drill' => drill_spec('equipment', '/equipment/data', t('dashboard.availability'), $q, '/equipment', '/equipment/{id}', $equipmentChips)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_stock_value'), 'value' => e(format_money($k['stock_value']['value'])), 'kpi' => $k['stock_value'], 'url' => '/stock', 'hint' => t('dashboard.hint_stock_value'),
        'drill' => drill_spec('drill_stock_values', '/dashboard/drill/stock-values', t('dashboard.kpi_stock_value'), $q, '/stock', '{!link}', [], false)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_edb_rate'), 'value' => $k['edb_rate']['empty'] ? '—' : e(format_number($k['edb_rate']['value'], 0)) . '<small>%</small>', 'kpi' => $k['edb_rate'], 'url' => '/edb/reports',
        'hint' => t('dashboard.hint_edb_rate', ['executed' => $k['edb_rate']['executed'], 'issued' => $k['edb_rate']['issued']]),
        'drill' => drill_spec('purchase_requests', '/edb/data', t('dashboard.kpi_edb_rate'), ($isMonth ? ['report' => 'issued', 'period' => substr($from, 0, 7)] : []) + $q, '/edb/reports', '/edb/{id}', $edbChips)]) ?>
</div>

<div class="xgrid">
    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.sites_title')) ?></h2><a class="small" href="<?= e(url('/reports')) ?>"><?= e(t('common.see_all')) ?></a></div>
        <div class="table-wrap">
            <table class="table table--compact xtable">
                <thead><tr>
                    <th><?= e(t('fields.site_id')) ?></th><th class="num"><?= e(t('dashboard.col_availability')) ?></th><th><?= e(t('dashboard.col_fleet_cost')) ?></th>
                    <th class="num"><?= e(t('dashboard.col_paid')) ?></th><th class="num"><?= e(t('dashboard.col_stock')) ?></th><th class="num" title="<?= e(t('dashboard.col_open_edb_title')) ?>"><?= e(t('dashboard.col_open_edb')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($dash['sites'] as $s): ?>
                        <?php
                        $tone = $s['availability'] === null ? '' : ($s['availability'] >= 85 ? 'good' : ($s['availability'] >= 70 ? 'warn' : 'bad'));
                        $sitePeek = peek($s['name'], t('dashboard.equipment_count', ['count' => $s['equipment']]), 'building-2', [
                            [t('dashboard.col_availability'), $s['availability'] !== null ? format_number($s['availability'], 1) . ' %' : '—', $tone],
                            [t('dashboard.col_fleet_cost'), format_money($s['cost'])],
                            [t('dashboard.kpi_engaged'), format_money($s['engaged'])],
                            [t('dashboard.col_paid'), format_money($s['paid'])],
                            [t('dashboard.kpi_stock_value'), format_money($s['stock'])],
                            [t('dashboard.col_open_edb_title'), (string) $s['open_edb'], $s['open_edb'] > 0 ? 'warn' : ''],
                        ], '/sites/' . $s['id'], t('dashboard.open_site_page'),
                            drill_spec('drill_cost_lines', '/dashboard/drill/cost-lines', t('dashboard.kpi_fleet_cost') . ' — ' . $s['name'], ['from' => $from, 'to' => $to, 'site_id' => $s['id']], '/costs', '{!link}', [], false),
                            t('dashboard.cost_lines'));
                        ?>
                        <tr class="row-hover xrow"<?= $sitePeek ?> tabindex="0" role="button">
                            <td><strong><?= e($s['name']) ?></strong><br><span class="muted small"><?= e(t('dashboard.equipment_count', ['count' => $s['equipment']])) ?></span></td>
                            <td class="num"><?= $s['availability'] !== null ? '<span class="xpill xpill--' . e($tone) . '">' . e(format_number($s['availability'], 0)) . ' %</span>' : '<span class="muted">—</span>' ?></td>
                            <td class="xtable__bar"><span class="xtable__barwrap"><span class="xbar"><span style="width: <?= e(round($s['cost'] / $maxSiteCost * 100)) ?>%"></span></span><span class="xbar__value<?= $s['cost'] < 0.005 ? ' muted' : '' ?>"><?= e(format_money($s['cost'])) ?></span></span></td>
                            <td class="num<?= $s['paid'] < 0.005 ? ' muted' : '' ?>"><?= e(format_money($s['paid'])) ?></td>
                            <td class="num<?= $s['stock'] < 0.005 ? ' muted' : '' ?>"><?= e(format_money($s['stock'])) ?></td>
                            <td class="num<?= $s['open_edb'] === 0 ? ' muted' : '' ?>"><?= e($s['open_edb']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card xcard xgrid__tall">
        <div class="xcard__head"><h2><?= e(t('dashboard.alerts_title')) ?></h2></div>
        <?php if ($dash['alerts'] === []): ?>
            <p class="xempty"><i data-lucide="check-circle-2"></i><?= e(t('dashboard.no_alert')) ?></p>
        <?php else: ?>
            <ul class="xalerts">
                <?php foreach ($dash['alerts'] as $a): ?>
                    <?php $spec = $alertDrills[$a['key']] ?? null; ?>
                    <li><a class="xalert xalert--<?= e($a['level']) ?>" href="<?= e(url($a['url'])) ?>"<?= $spec !== null ? $asAttr($spec) : '' ?>><i data-lucide="<?= e($a['icon']) ?>"></i>
                        <span><?= e(t($alertLabels[$a['key']], ['count' => $a['count'], 'amount' => format_money($a['amount'] ?? 0), 'period' => $a['period'] ?? ''])) ?></span><i data-lucide="chevron-right" class="xalert__go"></i></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="xclosing">
            <div class="xclosing__head"><span><?= e(t('dashboard.closing_title', ['period' => $closing['period']])) ?></span><strong><?= e($closing['done']) ?> / <?= e($closing['expected']) ?></strong></div>
            <span class="xbar xbar--<?= $closing['done'] >= $closing['expected'] ? 'good' : 'warn' ?>"><span style="width: <?= e($closing['expected'] > 0 ? round($closing['done'] / $closing['expected'] * 100) : 0) ?>%"></span></span>
            <a class="small" href="<?= e(url('/closings', ['period' => $closing['period']])) ?>"><?= e(t('dashboard.closing_open')) ?></a>
        </div>
    </section>

    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.top_equipment')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr($costLines(['from' => $from, 'to' => $to], t('dashboard.kpi_fleet_cost'))) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['top_equipment'] === []): ?>
            <p class="xempty"><?= e(t('costs.no_line')) ?></p>
        <?php else: ?>
            <ol class="xrank">
                <?php foreach ($dash['top_equipment'] as $i => $r): ?>
                    <?php
                    $eqPeek = peek($r['code'], $r['name'] . ' · ' . $r['site'], 'truck', [
                        [t('dashboard.kpi_fleet_cost'), format_money($r['total'])],
                        [t('cost_kind.fuel'), format_money($r['fuel'])],
                        [t('dashboard.other_costs'), format_money((float) $r['total'] - (float) $r['fuel'])],
                        [t('dashboard.missing_amounts'), (string) (int) $r['missing'], (int) $r['missing'] > 0 ? 'warn' : ''],
                        [t('fields.period'), format_date($from) . ' → ' . format_date($to)],
                    ], '/costs/' . $r['k'] . '?period=' . $monthOf, t('dashboard.open_cost_report'),
                        drill_spec('drill_cost_lines', '/dashboard/drill/cost-lines', $r['code'] . ' — ' . t('dashboard.cost_lines'), ['from' => $from, 'to' => $to, 'equipment_id' => $r['k']], '/costs/' . $r['k'], '{!link}', [], false),
                        t('dashboard.cost_lines'));
                    ?>
                    <li class="xrow"<?= $eqPeek ?> tabindex="0" role="button">
                        <span class="xrank__n"><?= e($i + 1) ?></span>
                        <span class="xrank__name"><strong><?= e($r['code']) ?></strong> <span class="muted"><?= e($r['name']) ?> · <?= e($r['site']) ?></span></span>
                        <span class="xbar"><span style="width: <?= e(round((float) $r['total'] / $maxTop * 100)) ?>%"></span></span>
                        <span class="xrank__value"><?= e(format_money($r['total'])) ?><?= (int) $r['missing'] > 0 ? ' <span class="badge badge--waiting_part" title="' . e(t('dashboard.hint_missing', ['count' => (int) $r['missing']])) . '">!</span>' : '' ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="hint small"><?= e(t('dashboard.top_equipment_hint')) ?></p>
        <?php endif; ?>
    </section>
</div>
