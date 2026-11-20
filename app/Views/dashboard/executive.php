<?php
/**
 * Vue Exécutive : dépenses, coût de la flotte, disponibilité, stock, exécution des EDB ; comparaison des sites,
 * engins les plus coûteux, clôtures, alertes.
 * @var array $dash  @var array $period  @var int|null $siteId
 */
$k = $dash['kpis'];
$q = ($siteId !== null ? ['site_id' => $siteId] : []);
$kpi = static fn (array $args): string => $view->partial('dashboard/_kpi', $args);
$monthOf = substr($period['to'], 0, 7);
$avail = $k['availability'];
$availTone = $avail['total'] === 0 ? null : ($avail['value'] >= 85 ? 'good' : ($avail['value'] >= 70 ? 'warn' : 'bad'));
$maxSiteCost = max(0.01, ...array_map(static fn (array $s): float => $s['cost'], $dash['sites'] ?: [['cost' => 0]]));
$maxTop = max(0.01, ...array_map(static fn (array $r): float => (float) $r['total'], $dash['top_equipment'] ?: [['total' => 0]]));
$alertLabels = ['documents' => 'dashboard.alert_documents', 'fuel_alerts' => 'dashboard.alert_fuel', 'cost_missing' => 'dashboard.alert_cost_missing',
    'advances' => 'dashboard.alert_advances', 'out_of_service' => 'dashboard.alert_out_of_service', 'in_transit' => 'dashboard.alert_in_transit'];
$closing = $dash['closing'];
?>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.kpi_engaged'), 'value' => e(format_money($k['engaged']['value'])), 'kpi' => $k['engaged'], 'url' => '/finance/reports', 'hint' => t('dashboard.hint_engaged')]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_paid'), 'value' => e(format_money($k['paid']['value'])), 'kpi' => $k['paid'], 'url' => '/finance/payments', 'hint' => t('dashboard.hint_paid')]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_fleet_cost'), 'value' => e(format_money($k['fleet_cost']['value'])), 'kpi' => $k['fleet_cost'], 'url' => '/costs?period=' . $monthOf,
        'hint' => $k['fleet_cost']['missing'] > 0 ? t('dashboard.hint_missing', ['count' => $k['fleet_cost']['missing']]) : t('dashboard.hint_fleet_cost'),
        'tone' => $k['fleet_cost']['missing'] > 0 ? 'warn' : null]) ?>
    <?= $kpi(['label' => t('dashboard.availability'), 'value' => e(format_number($avail['value'], 1)) . '<small>%</small>', 'kpi' => $avail, 'url' => '/equipment',
        'hint' => t('dashboard.available_of', ['available' => $avail['available'], 'total' => $avail['total']]), 'tone' => $availTone]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_stock_value'), 'value' => e(format_money($k['stock_value']['value'])), 'kpi' => $k['stock_value'], 'url' => '/stock', 'hint' => t('dashboard.hint_stock_value')]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_edb_rate'), 'value' => $k['edb_rate']['empty'] ? '—' : e(format_number($k['edb_rate']['value'], 0)) . '<small>%</small>', 'kpi' => $k['edb_rate'], 'url' => '/edb/reports',
        'hint' => t('dashboard.hint_edb_rate', ['executed' => $k['edb_rate']['executed'], 'issued' => $k['edb_rate']['issued']])]) ?>
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
                        <?php $tone = $s['availability'] === null ? '' : ($s['availability'] >= 85 ? 'good' : ($s['availability'] >= 70 ? 'warn' : 'bad')); ?>
                        <tr>
                            <td><a href="<?= e(url('/sites/' . $s['id'])) ?>"><strong><?= e($s['name']) ?></strong></a><br><span class="muted small"><?= e(t('dashboard.equipment_count', ['count' => $s['equipment']])) ?></span></td>
                            <td class="num"><?= $s['availability'] !== null ? '<span class="xpill xpill--' . e($tone) . '">' . e(format_number($s['availability'], 0)) . ' %</span>' : '<span class="muted">—</span>' ?></td>
                            <td class="xtable__bar"><span class="xtable__barwrap"><span class="xbar"><span style="width: <?= e(round($s['cost'] / $maxSiteCost * 100)) ?>%"></span></span><span class="xbar__value<?= $s['cost'] < 0.005 ? ' muted' : '' ?>"><?= e(format_money($s['cost'])) ?></span></span></td>
                            <td class="num<?= $s['paid'] < 0.005 ? ' muted' : '' ?>"><?= e(format_money($s['paid'])) ?></td>
                            <td class="num<?= $s['stock'] < 0.005 ? ' muted' : '' ?>"><?= e(format_money($s['stock'])) ?></td>
                            <td class="num<?= $s['open_edb'] === 0 ? ' muted' : '' ?>"><a href="<?= e(url('/edb', ['site_id' => $s['id']])) ?>"><?= e($s['open_edb']) ?></a></td>
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
                    <li><a class="xalert xalert--<?= e($a['level']) ?>" href="<?= e(url($a['url'])) ?>"><i data-lucide="<?= e($a['icon']) ?>"></i>
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
        <div class="xcard__head"><h2><?= e(t('dashboard.top_equipment')) ?></h2><a class="small" href="<?= e(url('/costs', ['period' => $monthOf] + $q)) ?>"><?= e(t('common.see_all')) ?></a></div>
        <?php if ($dash['top_equipment'] === []): ?>
            <p class="xempty"><?= e(t('costs.no_line')) ?></p>
        <?php else: ?>
            <ol class="xrank">
                <?php foreach ($dash['top_equipment'] as $i => $r): ?>
                    <li>
                        <span class="xrank__n"><?= e($i + 1) ?></span>
                        <a class="xrank__name" href="<?= e(url('/costs/' . $r['k'], ['period' => $monthOf])) ?>"><strong><?= e($r['code']) ?></strong> <span class="muted"><?= e($r['name']) ?> · <?= e($r['site']) ?></span></a>
                        <span class="xbar"><span style="width: <?= e(round((float) $r['total'] / $maxTop * 100)) ?>%"></span></span>
                        <span class="xrank__value"><?= e(format_money($r['total'])) ?><?= (int) $r['missing'] > 0 ? ' <span class="badge badge--waiting_part" title="' . e(t('dashboard.hint_missing', ['count' => (int) $r['missing']])) . '">!</span>' : '' ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="hint small"><?= e(t('dashboard.top_equipment_hint')) ?></p>
        <?php endif; ?>
    </section>
</div>
