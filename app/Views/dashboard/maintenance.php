<?php
/**
 * Vue Maintenance (compacte) : disponibilité, engins arrêtés, OT ouverts, OT terminés, préventifs échus, coût de maintenance ;
 * OT par semaine (barres cliquables), engins arrêtés depuis quand, prochains préventifs. Chaque élément ouvre son détail ou sa fiche.
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
$wo = static fn (array $p, string $title, array $chips = []): array => drill_spec('work_orders', '/work-orders/data', $title, $p + $q, '/work-orders', '/work-orders/{id}', $chips);
$equipment = static fn (array $p, string $title, array $chips = []): array => drill_spec('equipment', '/equipment/data', $title, $p + $q, '/equipment', '/equipment/{id}', $chips);
$costLines = static fn (array $p, string $title): array => drill_spec('drill_cost_lines', '/dashboard/drill/cost-lines', $title, $p + $q, '/costs', '{!link}', [], false);
$woChips = [['label' => t('common.all'), 'params' => []], ['label' => t('priority.critical'), 'params' => ['priority' => 'critical']],
    ['label' => t('dashboard.overdue'), 'params' => ['status' => 'overdue']], ['label' => t('wo_type.corrective'), 'params' => ['type' => 'corrective']], ['label' => t('wo_type.preventive'), 'params' => ['type' => 'preventive']]];
$downChips = [];
foreach (['breakdown', 'maintenance', 'waiting_part', 'immobilized', 'pending_release'] as $st) {
    $downChips[] = ['label' => t('status.' . $st), 'params' => ['status' => $st]];
}
$avail = $k['availability'];
$availTone = $avail['total'] === 0 ? null : ($avail['value'] >= 85 ? 'good' : ($avail['value'] >= 70 ? 'warn' : 'bad'));
$weekly = $dash['weekly'];
$weekMax = max(1, ...array_map(static fn (int $i): int => $weekly['corrective'][$i] + $weekly['preventive'][$i], array_keys($weekly['keys'])));
$duration = static function (int $hours): string {
    return $hours >= 48 ? t('dashboard.days', ['count' => intdiv($hours, 24)]) : t('dashboard.hours', ['count' => $hours]);
};
?>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.availability'), 'value' => e(format_number($avail['value'], 1)) . '<small>%</small>', 'kpi' => $avail, 'url' => '/equipment',
        'hint' => t('dashboard.available_of', ['available' => $avail['available'], 'total' => $avail['total']]), 'tone' => $availTone,
        'drill' => $equipment([], t('dashboard.availability'), array_merge([['label' => t('common.all'), 'params' => []], ['label' => t('status.available'), 'params' => ['status' => 'available']]], $downChips))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_down'), 'value' => e((string) (int) $k['down']['value']), 'kpi' => $k['down'], 'url' => '/equipment',
        'hint' => t('dashboard.hint_down', ['breakdown' => $k['down']['breakdown'], 'release' => $k['down']['pending_release']]), 'tone' => $k['down']['breakdown'] > 0 ? 'bad' : null,
        'drill' => $equipment(['status' => 'breakdown'], t('dashboard.kpi_down'), $downChips)]) ?>
    <?= $kpi(['label' => t('dashboard.open_work_orders'), 'value' => e((string) (int) $k['open']['value']), 'kpi' => $k['open'], 'url' => '/work-orders',
        'hint' => t('dashboard.hint_open_wo', ['critical' => $k['open']['critical'], 'overdue' => $k['open']['overdue']]), 'tone' => $k['open']['overdue'] > 0 ? 'bad' : ($k['open']['critical'] > 0 ? 'warn' : null),
        'drill' => $wo(['status' => 'active'], t('dashboard.open_work_orders'), $woChips)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_wo_done'), 'value' => e((string) (int) $k['done']['value']), 'kpi' => $k['done'], 'url' => '/work-orders',
        'hint' => $dash['mttr'] !== null ? t('dashboard.hint_mttr', ['hours' => format_number($dash['mttr'], 1)]) : t('dashboard.mttr_hint'),
        'drill' => $wo(['status' => 'done', 'closed_days' => 90], t('dashboard.kpi_wo_done'))]) ?>
    <?= $kpi(['label' => t('dashboard.preventive_due'), 'value' => e((string) (int) $k['preventive']['value']), 'kpi' => $k['preventive'], 'url' => '/preventive',
        'hint' => t('dashboard.preventive_due_hint'), 'tone' => $k['preventive']['value'] > 0 ? 'warn' : null,
        'drill' => drill_spec('preventive', '/preventive/data', t('dashboard.preventive_due'), ['due' => '1'] + $q, '/preventive', '/preventive/{id}/edit')]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_maintenance_cost'), 'value' => e(format_money($k['cost']['value'])), 'kpi' => $k['cost'], 'url' => '/costs', 'hint' => t('dashboard.hint_maintenance_cost'),
        'drill' => $costLines(['from' => $from, 'to' => $to], t('dashboard.kpi_maintenance_cost')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $costLines($bounds($m), t('dashboard.kpi_maintenance_cost') . ' — ' . $m)]) ?>
</div>

<div class="xgrid">
    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.weekly_title')) ?></h2>
            <span class="xlegend"><span class="xlegend__engaged"><?= e(t('wo_type.corrective')) ?></span><span class="xlegend__paid"><?= e(t('wo_type.preventive')) ?></span></span></div>
        <div class="xweeks">
            <?php foreach ($weekly['keys'] as $i => $key): ?>
                <?php $c = $weekly['corrective'][$i]; $p = $weekly['preventive'][$i]; ?>
                <button type="button" class="xweek" title="<?= e($weekly['labels'][$i] . ' : ' . $c . ' ' . t('wo_type.corrective') . ', ' . $p . ' ' . t('wo_type.preventive')) ?>"<?= $asAttr($wo(['week' => $key], t('dashboard.week_title', ['week' => $weekly['labels'][$i]]))) ?>>
                    <span class="xweek__bar">
                        <span class="xweek__seg xweek__seg--prev" style="height: <?= e(round($p / $weekMax * 100)) ?>%"></span>
                        <span class="xweek__seg xweek__seg--corr" style="height: <?= e(round($c / $weekMax * 100)) ?>%"></span>
                    </span>
                    <span class="xweek__total"><?= e($c + $p) ?></span>
                    <span class="xweek__label"><?= e($weekly['labels'][$i]) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card xcard xgrid__tall">
        <div class="xcard__head"><h2><?= e(t('dashboard.down_title')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr($equipment(['status' => 'breakdown'], t('dashboard.kpi_down'), $downChips)) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['down_list'] === []): ?>
            <p class="xempty"><i data-lucide="check-circle-2"></i><?= e(t('dashboard.no_down')) ?></p>
        <?php else: ?>
            <ul class="xlist">
                <?php foreach ($dash['down_list'] as $e): ?>
                    <?php $hours = (int) $e['hours']; ?>
                    <li class="xrow" role="button" tabindex="0"<?= peek($e['code'], $e['name'] . ' · ' . $e['site'], 'truck', [
                        [t('fields.status'), t('status.' . $e['status']), $e['status'] === 'breakdown' ? 'bad' : 'warn'],
                        [t('dashboard.since'), format_date($e['status_changed_at'], true)],
                        [t('dashboard.duration'), $duration($hours), $hours > 72 ? 'bad' : ''],
                        [t('dashboard.work_order'), $e['work_order'] ?? '—'],
                    ], '/equipment/' . $e['id'], t('dashboard.open_equipment')) ?>>
                        <span class="xlist__main"><strong><?= e($e['code']) ?></strong><span class="muted small"><?= e($e['name']) ?> · <?= e($e['site']) ?></span></span>
                        <span class="xlist__side"><?= status_badge($e['status']) ?><span class="muted small"><?= e($duration($hours)) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.next_preventive')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr(drill_spec('preventive', '/preventive/data', t('dashboard.preventive_due'), ['due' => '1'] + $q, '/preventive', '/preventive/{id}/edit')) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['preventive'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_preventive')) ?></p>
        <?php else: ?>
            <ul class="xlist xlist--grid">
                <?php foreach ($dash['preventive'] as $pp): ?>
                    <?php
                    $due = [];
                    if ($pp['next_due_date']) { $due[] = format_date($pp['next_due_date']); }
                    if ($pp['next_due_hours'] !== null) { $due[] = format_number($pp['next_due_hours']) . ' h (' . format_number($pp['hour_meter']) . ' h)'; }
                    if ($pp['next_due_km'] !== null) { $due[] = format_number($pp['next_due_km']) . ' km (' . format_number($pp['odometer_km']) . ' km)'; }
                    ?>
                    <li class="xrow" role="button" tabindex="0"<?= peek($pp['title'], $pp['code'] . ' · ' . $pp['site'], 'calendar-clock', [
                        [t('dashboard.due_on'), implode(' · ', $due) ?: '—', (int) $pp['due'] === 1 ? 'bad' : ''],
                        [t('fields.equipment_id'), $pp['code']],
                    ], '/preventive/' . $pp['id'] . '/edit', t('dashboard.open_plan')) ?>>
                        <span class="xlist__main"><strong><?= e($pp['code']) ?> — <?= e($pp['title']) ?></strong><span class="muted small"><?= e(implode(' · ', $due) ?: '—') ?></span></span>
                        <span class="xpill xpill--<?= (int) $pp['due'] === 1 ? 'bad' : '' ?>"><?= e((int) $pp['due'] === 1 ? t('dashboard.due_now') : t('dashboard.upcoming')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
