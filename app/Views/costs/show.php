<?php
/**
 * Rapport mensuel d'un engin : compteurs début / fin, détail des coûts avec leurs pièces sources, coût unitaire,
 * comparaison au mois précédent, cumul annuel, alertes, observations, évolution sur 12 mois.
 * @var array $row  @var string $period  @var array $lines  @var array $history  @var array|null $closing  @var bool $canCharge
 */
$kindLabels = ['fuel' => 'cost_kind.fuel', 'parts' => 'cost_kind.parts', 'lubricants' => 'cost_kind.lubricants', 'tyres_filters' => 'cost_kind.tyres_filters',
    'labor' => 'cost_kind.labor', 'external' => 'cost_kind.external', 'other' => 'cost_kind.other'];
$sourceLabels = ['stock_movement' => 'cost_source.stock_movement', 'fuel_refill' => 'cost_source.fuel_refill', 'work_order_technician' => 'cost_source.work_order_technician',
    'work_order_service' => 'cost_source.work_order_service', 'equipment_charge' => 'cost_source.equipment_charge', 'purchase_fulfilment' => 'cost_source.purchase_fulfilment'];
$km = $row['meter_basis'] === 'km';
$unit = $km ? 'km' : 'h';
$start = $km ? $row['km_start'] : $row['hours_start'];
$end = $km ? $row['km_end'] : $row['hours_end'];
$missingBadge = '<span class="badge badge--waiting_part">' . e(t('costs.missing')) . '</span>';
$prev = date('Y-m', (int) strtotime($period . '-01 -1 month'));
$next = date('Y-m', (int) strtotime($period . '-01 +1 month'));
$maxHistory = max(0.01, ...array_map(static fn (array $h): float => (float) $h['total'], $history));
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="calculator"></i></span>
        <div>
            <h1><?= e($row['code']) ?> — <?= e($row['name']) ?></h1>
            <div class="page-title__meta">
                <span><?= e($row['site']) ?> · <?= e($row['category'] ?? '—') ?> · <?= e($period) ?></span>
                <?php if ($closing !== null): ?><span class="badge badge--neutral"><?= e(t('costs.closed_by', ['name' => $closing['closed_by_name'], 'date' => format_date($closing['closed_at'])])) ?></span><?php endif; ?>
                <?php if ((int) $row['missing_count'] > 0): ?><span class="badge badge--waiting_part"><?= e(t('costs.missing_lines', ['count' => (int) $row['missing_count']])) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/costs/' . $row['id'], ['period' => $prev])) ?>"><i data-lucide="chevron-left"></i><?= e($prev) ?></a>
        <?php if ($next <= date('Y-m')): ?><a class="btn" href="<?= e(url('/costs/' . $row['id'], ['period' => $next])) ?>"><?= e($next) ?><i data-lucide="chevron-right"></i></a><?php endif; ?>
        <a class="btn" href="<?= e(url('/costs', ['period' => $period])) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button>
    </div>
</div>

<?php foreach (['period', 'amount', 'charge_date', 'label', 'note'] as $errorField): ?>
    <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
<?php endforeach; ?>

<div class="kpis kpis--compact">
    <div class="kpi kpi--hero">
        <div class="kpi__label"><?= e(t('costs.total')) ?></div>
        <div class="kpi__value"><?= e(format_money($row['total'])) ?></div>
        <div class="kpi__hint"><?= (int) $row['missing_count'] > 0 ? e(t('costs.total_incomplete')) : e(t('costs.vs_previous', ['amount' => ((float) $row['variation'] >= 0 ? '+' : '') . format_money($row['variation'])])) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi__label"><?= e(t('costs.meters')) ?></div>
        <div class="kpi__value" style="font-size:1.1rem"><?= $start !== null && $end !== null ? e(format_number($start)) . ' → ' . e(format_number($end)) . ' ' . e($unit) : $missingBadge ?></div>
        <div class="kpi__hint"><?= $row['usage_value'] !== null ? e(t('costs.usage', ['value' => format_number($row['usage_value']), 'unit' => $unit])) : e(t('costs.usage_missing')) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi__label"><?= e(t($km ? 'costs.per_km' : 'costs.per_hour')) ?></div>
        <div class="kpi__value"><?= $row['unit_cost'] !== null ? e(format_money($row['unit_cost'])) : $missingBadge ?></div>
    </div>
    <div class="kpi<?= $row['fuel_alert'] !== null ? ' kpi--alert' : '' ?>">
        <div class="kpi__label"><?= e(t($km ? 'costs.fuel_per_100km' : 'costs.fuel_per_hour')) ?></div>
        <div class="kpi__value"><?= $row['fuel_ratio'] !== null ? e(format_number($row['fuel_ratio'], 2)) : '—' ?></div>
        <div class="kpi__hint"><?= e(t('costs.litres', ['litres' => format_number($row['litres'], 1)])) ?><?= $row['fuel_reference'] !== null ? ' · ' . e(t('costs.reference', ['value' => format_number($row['fuel_reference'], 2)])) : '' ?></div>
    </div>
    <div class="kpi"><div class="kpi__label"><?= e(t('costs.ytd')) ?></div><div class="kpi__value"><?= e(format_money($row['ytd_total'])) ?></div><div class="kpi__hint"><?= e(t('costs.previous', ['amount' => format_money($row['prev_total'])])) ?></div></div>
</div>

<?php if ($row['fuel_alert'] !== null || (int) $row['meter_alert'] === 1): ?>
<section class="card">
    <?php if ($row['fuel_alert'] !== null): ?><p><span class="badge badge--breakdown"><?= e($row['fuel_alert'] === 'over' ? t('costs.alert_fuel_over') : t('costs.alert_fuel_under')) ?></span> <?= e(t('costs.alert_fuel_detail', ['ratio' => format_number($row['fuel_ratio'], 2), 'reference' => format_number($row['fuel_reference'], 2)])) ?></p><?php endif; ?>
    <?php if ((int) $row['meter_alert'] === 1): ?><p><span class="badge badge--breakdown"><?= e(t('costs.alert_meter')) ?></span> <?= e(t('costs.alert_meter_detail')) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<div class="grid grid--main-side">
    <section class="card">
        <div class="card__title"><h2><?= e(t('costs.detail')) ?></h2></div>
        <table class="table table--compact">
            <tbody>
                <?php foreach ($kindLabels as $kind => $label): ?>
                    <tr><td><?= e(t($label)) ?></td><td class="num"><strong><?= e(format_money($row[$kind])) ?></strong></td></tr>
                <?php endforeach; ?>
                <tr><td><strong><?= e(t('costs.total')) ?></strong></td><td class="num"><strong><?= e(format_money($row['total'])) ?></strong><?= (int) $row['missing_count'] > 0 ? ' ' . $missingBadge : '' ?></td></tr>
            </tbody>
        </table>
        <?php if ($lines === []): ?>
            <p class="empty"><?= e(t('costs.no_line')) ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th><?= e(t('fields.date')) ?></th><th><?= e(t('costs.kind')) ?></th><th><?= e(t('fields.label')) ?></th><th class="num"><?= e(t('fields.quantity')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th><th><?= e(t('costs.source')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                            <tr>
                                <td class="nowrap"><?= e(format_date($line['cost_date'])) ?></td>
                                <td><?= e(t($kindLabels[$line['kind']])) ?></td>
                                <td><?= e($line['label']) ?></td>
                                <td class="num"><?= e(format_qty($line['quantity'])) ?> <?= e($line['unit']) ?></td>
                                <td class="num"><?= $line['amount'] !== null ? e(format_money($line['amount'])) : $missingBadge ?></td>
                                <td class="small"><?= e(t($sourceLabels[$line['source_type']])) ?><?= $line['document_ref'] ? ' · <span class="mono">' . e($line['document_ref']) . '</span>' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="hint small"><?= e(t('costs.rule')) ?></p>
    </section>

    <div>
        <section class="card">
            <div class="card__title"><h2><?= e(t('costs.history')) ?></h2></div>
            <ul class="bars">
                <?php foreach ($history as $month): ?>
                    <li><span class="bars__label"><?= e($month['period']) ?></span><span class="bars__track"><span style="width: <?= e(round((float) $month['total'] / $maxHistory * 100)) ?>%"></span></span><span class="bars__value"><?= e(format_money($month['total'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= e(t('costs.observations')) ?></h2></div>
            <?php if ($canCharge): ?>
                <form method="post" action="<?= e(url('/costs/' . $row['id'] . '/note')) ?>" class="form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="period" value="<?= e($period) ?>">
                    <textarea name="note" class="input" maxlength="500" rows="3" required aria-label="<?= e(t('costs.observations')) ?>"><?= e(old('note', $row['observation'])) ?></textarea>
                    <div class="form-actions"><button type="submit" class="btn btn--sm"><i data-lucide="save"></i><?= e(t('common.save')) ?></button></div>
                </form>
            <?php else: ?>
                <p><?= $row['observation'] ? e($row['observation']) : '<span class="muted">' . e(t('costs.no_observation')) . '</span>' ?></p>
            <?php endif; ?>
        </section>

        <?php if ($canCharge): ?>
        <section class="card">
            <div class="card__title"><h2><?= e(t('costs.add_charge')) ?></h2></div>
            <form method="post" action="<?= e(url('/costs/charges')) ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="equipment_id" value="<?= e($row['id']) ?>">
                <?= $view->partial('partials/field', ['name' => 'label', 'labelKey' => 'fields.label', 'type' => 'text', 'required' => true, 'value' => old('label'), 'attrs' => ['maxlength' => 190], 'hint' => t('costs.charge_hint')]) ?>
                <?= $view->partial('partials/field', ['name' => 'charge_date', 'labelKey' => 'fields.date', 'type' => 'date', 'required' => true, 'value' => old('charge_date', min(date('Y-m-d'), date('Y-m-t', (int) strtotime($period . '-01')))), 'attrs' => ['max' => date('Y-m-d')]]) ?>
                <?= $view->partial('partials/field', ['name' => 'amount', 'labelKey' => 'fields.amount', 'type' => 'number', 'required' => true, 'value' => old('amount'), 'attrs' => ['min' => '0.01', 'step' => '0.01']]) ?>
                <?= $view->partial('partials/field', ['name' => 'document_ref', 'labelKey' => 'fields.document_ref', 'type' => 'text', 'value' => old('document_ref'), 'attrs' => ['maxlength' => 60]]) ?>
                <div class="form-actions"><button type="submit" class="btn btn--sm btn--primary"><i data-lucide="plus"></i><?= e(t('costs.add_charge')) ?></button></div>
            </form>
        </section>
        <?php endif; ?>
    </div>
</div>
