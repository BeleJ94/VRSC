<?php
/**
 * Demande de transfert d'un engin. Elle ne vaut pas autorisation de départ : avis, contrôle technique et autorisation suivent.
 * @var array $equipment  @var int|null $equipmentId  @var array $sites  @var \App\Security\SiteScope $ownSites  @var array $carriers  @var array $drivers
 */
$equipmentOptions = ['' => '—'];
foreach ($equipment as $eq) {
    $equipmentOptions[$eq['id']] = $eq['code'] . ' – ' . $eq['name'] . ' (' . $eq['site'] . ')';
}
$driverOptions = ['' => '—'];
foreach ($drivers as $driver) {
    $driverOptions[$driver['id']] = $driver['name'] . ' (' . $driver['site'] . ')';
}
$modes = array_combine(\App\Services\FleetTransferService::MODES, array_map(static fn (string $m) => t('fleet_mode.' . $m), \App\Services\FleetTransferService::MODES));
$f = static fn (string $name, string $type, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $extra['default'] ?? null),
]);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url('/fleet-transfers')) ?>" class="card form">
    <?= csrf_field() ?>
    <p class="muted"><?= e(t('fleet.form_help')) ?></p>
    <div class="form-grid">
        <?= $f('equipment_id', 'select', ['required' => true, 'default' => $equipmentId, 'options' => $equipmentOptions]) ?>
        <?= $f('to_site_id', 'select', ['required' => true, 'options' => ['' => '—'] + array_column($sites, 'name', 'id')]) ?>
        <?= $f('reason', 'textarea', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 500, 'rows' => 2]]) ?>
        <?= $f('planned_departure', 'datetime-local') ?>
        <?= $f('planned_arrival', 'datetime-local') ?>
    </div>

    <h2 class="section-title"><i data-lucide="truck"></i><?= e(t('fleet.sec_transport')) ?></h2>
    <div class="form-grid">
        <?= $f('transport_mode', 'select', ['required' => true, 'default' => 'own_power', 'options' => $modes]) ?>
        <?= $f('carrier_id', 'select', ['options' => ['' => '—'] + $carriers]) ?>
        <?= $f('driver_id', 'select', ['options' => $driverOptions, 'hint' => t('fleet.driver_hint')]) ?>
        <?= $f('driver_name', 'text', ['attrs' => ['maxlength' => 170]]) ?>
        <?= $f('driver_phone', 'text', ['attrs' => ['maxlength' => 30]]) ?>
        <?= $f('convoy_leader', 'text', ['attrs' => ['maxlength' => 170]]) ?>
        <?= $f('tractor_plate', 'text', ['attrs' => ['maxlength' => 30]]) ?>
        <?= $f('trailer_plate', 'text', ['attrs' => ['maxlength' => 30]]) ?>
        <?= $f('transport_conditions', 'textarea', ['class' => 'span-2', 'attrs' => ['maxlength' => 500, 'rows' => 2], 'hint' => t('fleet.conditions_hint')]) ?>
        <?= $f('observations', 'textarea', ['class' => 'span-2', 'attrs' => ['maxlength' => 1000, 'rows' => 2]]) ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/fleet-transfers')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="send"></i><?= e(t('fleet.submit')) ?></button>
    </div>
</form>
