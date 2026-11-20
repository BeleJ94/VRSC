<?php
/**
 * Vue Magasin : valeur du stock, sorties et entrées valorisées, niveaux (sous mini, ruptures), EDB à servir,
 * commandes attendues ; cuves de carburant (autonomie), transferts en cours. Chaque élément ouvre son détail ou sa fiche.
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
$journal = static fn (array $p, string $title, array $chips = []): array => drill_spec('stock_journal', '/stock/journal/data', $title, $p + $q, '/stock/journal', null, $chips);
$inChips = [['label' => t('movement.receipt'), 'params' => ['type' => 'receipt']], ['label' => t('movement.return'), 'params' => ['type' => 'return']], ['label' => t('movement.transfer_in'), 'params' => ['type' => 'transfer_in']]];
$orders = static fn (array $p, string $title, array $chips = []): array => drill_spec('drill_orders', '/dashboard/drill/orders', $title, $p + $q, '/purchases', '{!link}', $chips, false);
$urgencyClass = ['critical' => 'bad', 'urgent' => 'warn', 'normal' => ''];
$transferClass = ['shipped' => 'warn', 'authorized' => 'good', 'requested' => ''];
?>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.kpi_stock_value'), 'value' => e(format_money($k['stock_value']['value'])), 'kpi' => $k['stock_value'], 'url' => '/stock', 'hint' => t('dashboard.hint_stock_value'),
        'drill' => drill_spec('drill_stock_values', '/dashboard/drill/stock-values', t('dashboard.kpi_stock_value'), $q, '/stock', '{!link}', [], false)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_outs'), 'value' => e(format_money($k['outs']['value'])), 'kpi' => $k['outs'], 'url' => '/stock/journal', 'hint' => t('dashboard.hint_outs'),
        'drill' => $journal(['type' => 'out', 'from' => $from, 'to' => $to], t('dashboard.kpi_outs')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $journal(['type' => 'out'] + $bounds($m), t('dashboard.kpi_outs') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_ins'), 'value' => e(format_money($k['ins']['value'])), 'kpi' => $k['ins'], 'url' => '/stock/journal', 'hint' => t('dashboard.hint_ins'),
        'drill' => $journal(['from' => $from, 'to' => $to], t('dashboard.kpi_ins'), $inChips), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $journal($bounds($m), t('dashboard.kpi_ins') . ' — ' . $m, $inChips)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_levels'), 'value' => e((string) (int) $k['levels']['value']), 'kpi' => $k['levels'], 'url' => '/procurement',
        'hint' => t('dashboard.hint_levels', ['low' => $k['levels']['low'], 'out' => $k['levels']['out']]), 'tone' => $k['levels']['out'] > 0 ? 'bad' : ($k['levels']['low'] > 0 ? 'warn' : null),
        'drill' => drill_spec('procurement', '/procurement/data', t('dashboard.kpi_levels'), $q, '/procurement', '/parts/{id}',
            [['label' => t('common.all'), 'params' => ['level' => 'action']], ['label' => t('level.out'), 'params' => ['level' => 'out']], ['label' => t('level.low'), 'params' => ['level' => 'low']]])]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_edb_to_serve'), 'value' => e((string) (int) $k['edb']['value']), 'kpi' => $k['edb'], 'url' => '/edb', 'hint' => t('dashboard.hint_edb_to_serve'),
        'tone' => $k['edb']['value'] > 0 ? 'warn' : null,
        'drill' => drill_spec('purchase_requests', '/edb/data', t('dashboard.todo_edb_title'), ['status' => 'approved'] + $q, '/edb', '/edb/{id}')]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_orders_expected'), 'value' => e((string) (int) $k['orders']['value']), 'kpi' => $k['orders'], 'url' => '/purchases',
        'hint' => $k['orders']['late'] > 0 ? t('dashboard.hint_orders_late', ['count' => $k['orders']['late']]) : format_money($k['orders']['amount']),
        'tone' => $k['orders']['late'] > 0 ? 'bad' : null,
        'drill' => $orders(['status' => 'issued,partially_received'], t('dashboard.kpi_orders_expected'), [['label' => t('common.all'), 'params' => []], ['label' => t('dashboard.chip_late'), 'params' => ['late' => '1']]])]) ?>
</div>

<div class="xgrid">
    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.fuel_tanks')) ?></h2><span class="muted small"><?= e(t('dashboard.fuel_tanks_hint')) ?></span></div>
        <?php if ($dash['tanks'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_tank')) ?></p>
        <?php else: ?>
            <ul class="xtanks">
                <?php foreach ($dash['tanks'] as $t): ?>
                    <?php
                    $autonomy = $t['autonomy'];
                    $tone = $autonomy === null ? '' : ($autonomy >= 10 ? 'good' : ($autonomy >= 5 ? 'warn' : 'bad'));
                    $capacity = max((float) $t['quantity'], (float) $t['min_quantity'] * 2, 1.0);
                    $tankPeek = peek($t['site'], $t['reference'], 'fuel', [
                        [t('dashboard.tank_stock'), format_number($t['quantity'], 0) . ' ' . $t['unit']],
                        [t('dashboard.tank_daily'), format_number($t['daily'], 0) . ' ' . $t['unit'] . ' / ' . t('dashboard.day')],
                        [t('dashboard.tank_autonomy'), $autonomy !== null ? t('dashboard.days', ['count' => format_number($autonomy, 1)]) : '—', $tone],
                        [t('dashboard.tank_min'), format_number($t['min_quantity'], 0) . ' ' . $t['unit'], (float) $t['quantity'] < (float) $t['min_quantity'] ? 'bad' : ''],
                    ], '/parts/' . $t['part_id'], t('dashboard.open_part'),
                        drill_spec('stock_journal', '/stock/journal/data', t('dashboard.tank_outs', ['site' => $t['site']]), ['part_id' => $t['part_id'], 'site_id' => $t['site_id'], 'type' => 'out', 'from' => date('Y-m-d', strtotime('-30 days'))], '/stock/journal'),
                        t('dashboard.tank_outs_short'));
                    ?>
                    <li class="xtank xrow" role="button" tabindex="0"<?= $tankPeek ?>>
                        <span class="xtank__site"><strong><?= e($t['site']) ?></strong><span class="muted small"><?= e(t('dashboard.tank_daily_short', ['value' => format_number($t['daily'], 0), 'unit' => $t['unit']])) ?></span></span>
                        <span class="xtank__gauge"><span class="xtank__fill xtank__fill--<?= e($tone ?: 'neutral') ?>" style="width: <?= e(min(100, round((float) $t['quantity'] / $capacity * 100))) ?>%"></span>
                            <span class="xtank__min" style="left: <?= e(min(100, round((float) $t['min_quantity'] / $capacity * 100))) ?>%" title="<?= e(t('dashboard.tank_min')) ?>"></span></span>
                        <span class="xtank__qty"><?= e(format_number($t['quantity'], 0)) ?> <?= e($t['unit']) ?></span>
                        <span class="xpill xpill--<?= e($tone ?: 'good') ?>"><?= $autonomy !== null ? e(t('dashboard.days', ['count' => format_number($autonomy, 0)])) : '—' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card xcard xgrid__tall">
        <div class="xcard__head"><h2><?= e(t('dashboard.open_transfers')) ?></h2><a class="small" href="<?= e(url('/stock/transfers')) ?>"><?= e(t('common.see_all')) ?></a></div>
        <?php if ($dash['transfers'] === []): ?>
            <p class="xempty"><i data-lucide="check-circle-2"></i><?= e(t('dashboard.no_transfer')) ?></p>
        <?php else: ?>
            <ul class="xlist">
                <?php foreach ($dash['transfers'] as $tr): ?>
                    <li class="xrow" role="button" tabindex="0"<?= peek($tr['reference'], $tr['from_site'] . ' → ' . $tr['to_site'], 'arrow-left-right', [
                        [t('fields.status'), t('transfer_status.' . $tr['status']), $transferClass[$tr['status']] ?? ''],
                        [t('dashboard.lines'), (string) $tr['line_count']],
                        [t('dashboard.requested_on'), format_date($tr['requested_at'])],
                        [t('dashboard.shipped_on'), $tr['shipped_at'] ? format_date($tr['shipped_at']) : '—'],
                    ], '/stock/transfers/' . $tr['id'], t('dashboard.open_transfer')) ?>>
                        <span class="xlist__main"><strong><?= e($tr['reference']) ?></strong><span class="muted small"><?= e($tr['from_site']) ?> → <?= e($tr['to_site']) ?></span></span>
                        <span class="xpill xpill--<?= e($transferClass[$tr['status']] ?? '') ?>"><?= e(t('transfer_status.' . $tr['status'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card xcard">
        <div class="xcard__head"><h2><?= e(t('dashboard.todo_edb_title')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr(drill_spec('purchase_requests', '/edb/data', t('dashboard.todo_edb_title'), ['status' => 'approved'] + $q, '/edb', '/edb/{id}')) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['edb'] === []): ?>
            <p class="xempty"><i data-lucide="check-circle-2"></i><?= e(t('dashboard.no_edb_to_serve')) ?></p>
        <?php else: ?>
            <ul class="xlist">
                <?php foreach ($dash['edb'] as $r): ?>
                    <li class="xrow" role="button" tabindex="0"<?= peek($r['reference'], $r['subject'], 'file-pen-line', [
                        [t('fields.site_id'), $r['site']],
                        [t('js.edb.urgency'), t('urgency.' . $r['urgency']), $urgencyClass[$r['urgency']] ?? ''],
                        [t('dashboard.approved_on'), format_date($r['approved_at'])],
                        [t('dashboard.waiting'), t('dashboard.days', ['count' => (int) $r['age']]), (int) $r['age'] > 7 ? 'warn' : ''],
                    ], '/edb/' . $r['id'], t('dashboard.open_edb')) ?>>
                        <span class="xlist__main"><strong><?= e($r['reference']) ?></strong><span class="muted small"><?= e($r['subject']) ?> · <?= e($r['site']) ?></span></span>
                        <span class="xpill xpill--<?= e($urgencyClass[$r['urgency']] ?? '') ?>"><?= e(t('dashboard.days', ['count' => (int) $r['age']])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card xcard">
        <div class="xcard__head"><h2><?= e(t('dashboard.kpi_orders_expected')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr($orders(['status' => 'issued,partially_received'], t('dashboard.kpi_orders_expected'))) ?>><?= e(t('common.see_all')) ?></button></div>
        <?= $view->partial('dashboard/_orders', ['rows' => $dash['orders']]) ?>
    </section>
</div>
