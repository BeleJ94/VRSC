<?php
/**
 * Vue Achats : EDB émis, en circuit de validation, à acheter, commandes en cours, engagé, délai EDB → commande ;
 * fournisseurs, répartition des EDB par statut, commandes à suivre. Chaque élément ouvre son détail ou sa fiche.
 * @var array $dash  @var array $period  @var int|null $siteId
 */
$k = $dash['kpis'];
$q = $siteId !== null ? ['site_id' => $siteId] : [];
$from = $period['from'];
$to = $period['to'];
$months = $dash['trend_months'];
$bounds = static fn (string $m): array => ['from' => $m . '-01', 'to' => date('Y-m-t', (int) strtotime($m . '-01'))];
$kpi = static fn (array $args): string => $view->partial('dashboard/_kpi', $args);
$asAttr = static fn (array $spec): string => ' data-drill="' . e(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
$isMonth = in_array($period['key'], ['month', 'last_month'], true);
$edb = static fn (array $p, string $title, array $chips = []): array => drill_spec('purchase_requests', '/edb/data', $title, $p + $q, '/edb', '/edb/{id}', $chips);
$orders = static fn (array $p, string $title, array $chips = []): array => drill_spec('drill_orders', '/dashboard/drill/orders', $title, $p + $q, '/purchases', '{!link}', $chips, false);
$commitments = static fn (array $p, string $title): array => drill_spec('drill_commitments', '/dashboard/drill/commitments', $title, $p + $q, '/finance/reports', '{!link}', [], false);
$statusChips = [['label' => t('common.all'), 'params' => []]];
foreach (\App\Repositories\PurchaseRepository::DISPLAY_STATUSES as $st) {
    $statusChips[] = ['label' => t('edb_status.' . $st), 'params' => ['status' => $st]];
}
$maxSupplier = max(0.01, ...array_map(static fn (array $s): float => (float) $s['amount'], $dash['suppliers'] ?: [['amount' => 0]]));
$pipeline = $dash['pipeline'];
$pipeTotal = max(1, array_sum($pipeline));
$pipeClass = ['pending' => 'maintenance', 'approved' => 'waiting_part', 'executed' => 'available', 'cancelled' => 'neutral'];
$lead = $k['lead'];
?>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.kpi_edb_issued'), 'value' => e((string) (int) $k['issued']['value']), 'kpi' => $k['issued'], 'url' => '/edb/reports', 'hint' => t('dashboard.hint_edb_issued'),
        'drill' => $edb($isMonth ? ['report' => 'issued', 'period' => substr($from, 0, 7)] : [], t('dashboard.kpi_edb_issued'), $statusChips), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $edb(['report' => 'issued', 'period' => $m], t('dashboard.kpi_edb_issued') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_edb_pending'), 'value' => e((string) (int) $k['pending']['value']), 'kpi' => $k['pending'], 'url' => '/approvals', 'hint' => t('dashboard.hint_edb_pending'),
        'tone' => $k['pending']['value'] > 0 ? 'warn' : null, 'drill' => $edb(['status' => 'pending'], t('dashboard.kpi_edb_pending'))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_edb_to_buy'), 'value' => e((string) (int) $k['to_buy']['value']), 'kpi' => $k['to_buy'], 'url' => '/edb', 'hint' => t('dashboard.hint_edb_to_buy'),
        'tone' => $k['to_buy']['value'] > 0 ? 'warn' : null, 'drill' => $edb(['status' => 'approved'], t('dashboard.kpi_edb_to_buy'))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_open_orders'), 'value' => e(format_money($k['orders']['value'])), 'kpi' => $k['orders'], 'url' => '/purchases',
        'hint' => $k['orders']['late'] > 0 ? t('dashboard.hint_orders_late', ['count' => $k['orders']['late']]) : t('dashboard.hint_open_orders', ['count' => $k['orders']['count']]),
        'tone' => $k['orders']['late'] > 0 ? 'bad' : null,
        'drill' => $orders(['status' => 'issued,partially_received'], t('dashboard.kpi_open_orders'), [['label' => t('common.all'), 'params' => []], ['label' => t('dashboard.chip_late'), 'params' => ['late' => '1']]])]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_engaged'), 'value' => e(format_money($k['engaged']['value'])), 'kpi' => $k['engaged'], 'url' => '/finance/reports', 'hint' => t('dashboard.hint_engaged'),
        'drill' => $commitments(['from' => $from, 'to' => $to], t('dashboard.kpi_engaged')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $commitments($bounds($m), t('dashboard.kpi_engaged') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_lead'), 'value' => $lead['empty'] ? '—' : e(format_number($lead['value'], 1)) . '<small>' . e(t('dashboard.days_unit')) . '</small>', 'kpi' => $lead, 'url' => '/purchases',
        'hint' => t('dashboard.hint_lead'),
        'drill' => $orders(['from' => $from, 'to' => $to], t('dashboard.kpi_lead_drill')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $orders($bounds($m), t('dashboard.kpi_lead_drill') . ' — ' . $m)]) ?>
</div>

<div class="xgrid">
    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.suppliers')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr($orders(['from' => $from, 'to' => $to], t('dashboard.suppliers'))) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['suppliers'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_order_period')) ?></p>
        <?php else: ?>
            <ol class="xrank">
                <?php foreach ($dash['suppliers'] as $i => $s): ?>
                    <li class="xrow" role="button" tabindex="0"<?= $asAttr($orders(['from' => $from, 'to' => $to, 'supplier_id' => $s['id']], t('dashboard.orders_of', ['name' => $s['name']]))) ?>>
                        <span class="xrank__n"><?= e($i + 1) ?></span>
                        <span class="xrank__name"><strong><?= e($s['name']) ?></strong> <span class="muted"><?= e(t('dashboard.orders_count', ['count' => (int) $s['orders']])) ?><?= (int) $s['open'] > 0 ? ' · ' . e(t('dashboard.orders_open', ['count' => (int) $s['open']])) : '' ?></span></span>
                        <span class="xbar"><span style="width: <?= e(round((float) $s['amount'] / $maxSupplier * 100)) ?>%"></span></span>
                        <span class="xrank__value"><?= e(format_money($s['amount'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="card xcard xgrid__tall">
        <div class="xcard__head"><h2><?= e(t('dashboard.orders_to_follow')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr($orders(['status' => 'issued,partially_received'], t('dashboard.orders_to_follow'))) ?>><?= e(t('common.see_all')) ?></button></div>
        <?= $view->partial('dashboard/_orders', ['rows' => $dash['orders']]) ?>
    </section>

    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.edb_pipeline')) ?></h2><span class="muted small"><?= e(t('dashboard.edb_pipeline_hint')) ?></span></div>
        <?php if (array_sum($pipeline) === 0): ?>
            <p class="xempty"><?= e(t('dashboard.no_edb_period')) ?></p>
        <?php else: ?>
            <div class="xpipe" role="list">
                <?php foreach (['pending', 'approved', 'executed', 'cancelled'] as $st): ?>
                    <?php if ($pipeline[$st] > 0): ?>
                        <span class="xpipe__seg xpipe__seg--<?= e($pipeClass[$st]) ?>" style="flex: <?= e($pipeline[$st]) ?>" role="listitem" title="<?= e(t('edb_status.' . $st)) ?> : <?= e($pipeline[$st]) ?>"></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <ul class="xpipe__legend">
                <?php foreach (['pending', 'approved', 'executed', 'cancelled'] as $st): ?>
                    <li class="xrow" role="button" tabindex="0"<?= $asAttr($edb(['status' => $st] + ($isMonth ? ['report' => 'issued', 'period' => substr($from, 0, 7)] : []), t('edb_status.' . $st))) ?>>
                        <span class="dot dot--<?= e($pipeClass[$st]) ?>"></span><?= e(t('edb_status.' . $st)) ?>
                        <strong><?= e($pipeline[$st]) ?></strong><span class="muted small"><?= e(format_number($pipeline[$st] / $pipeTotal * 100, 0)) ?> %</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
