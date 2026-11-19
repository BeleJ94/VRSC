<?php
/**
 * Bon de ravitaillement : engin, date, compteur, litres, origine (cuve du site ou station), prix, bon.
 * @var array $equipment  @var int|null $equipmentId  @var array $fuels
 */
$equipmentOptions = ['' => '—'];
foreach ($equipment as $eq) {
    $index = $eq['basis'] === 'km' ? ($eq['odometer_km'] !== null ? format_number($eq['odometer_km']) . ' km' : '—') : ($eq['hour_meter'] !== null ? format_number($eq['hour_meter']) . ' h' : '—');
    $equipmentOptions[$eq['id']] = $eq['code'] . ' – ' . $eq['name'] . ' (' . $eq['site'] . ' · ' . $index . ')';
}
$fuelOptions = ['' => '—'];
foreach ($fuels as $fuel) {
    $fuelOptions[$fuel['id']] = $fuel['reference'] . ' – ' . $fuel['name'];
}
$f = static fn (string $name, string $type, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $extra['default'] ?? null),
]);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url('/fuel')) ?>" class="card form">
    <?= csrf_field() ?>
    <?php if (field_error('period')): ?><p class="error"><?= e(field_error('period')) ?></p><?php endif; ?>
    <div class="form-grid">
        <?= $f('equipment_id', 'select', ['required' => true, 'default' => $equipmentId, 'options' => $equipmentOptions, 'class' => 'span-2']) ?>
        <?= $f('refill_date', 'date', ['required' => true, 'default' => date('Y-m-d'), 'attrs' => ['max' => date('Y-m-d')]]) ?>
        <?= $f('litres', 'number', ['required' => true, 'attrs' => ['min' => '0.1', 'step' => '0.01']]) ?>
        <?= $f('hour_meter', 'number', ['attrs' => ['min' => '0', 'step' => '1'], 'hint' => t('fuel.meter_hint')]) ?>
        <?= $f('odometer_km', 'number', ['attrs' => ['min' => '0', 'step' => '1']]) ?>
    </div>
    <h2 class="section-title"><i data-lucide="fuel"></i><?= e(t('fuel.origin')) ?></h2>
    <div class="form-grid">
        <?= $f('source', 'select', ['required' => true, 'default' => $fuels !== [] ? 'stock' : 'external', 'options' => ['stock' => t('fuel_source.stock'), 'external' => t('fuel_source.external')]]) ?>
        <?= $f('part_id', 'select', ['options' => $fuelOptions, 'default' => count($fuels) === 1 ? $fuels[0]['id'] : null, 'hint' => t('fuel.stock_hint')]) ?>
        <?= $f('station', 'text', ['attrs' => ['maxlength' => 120]]) ?>
        <?= $f('unit_price', 'number', ['attrs' => ['min' => '0', 'step' => '0.0001'], 'hint' => t('fuel.price_hint')]) ?>
        <?= $f('voucher_ref', 'text', ['attrs' => ['maxlength' => 60]]) ?>
        <?= $f('note', 'text', ['attrs' => ['maxlength' => 255]]) ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/fuel')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('fuel.record')) ?></button>
    </div>
</form>
