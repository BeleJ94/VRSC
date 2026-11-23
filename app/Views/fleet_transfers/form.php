<?php
/**
 * Demande de transfert d'un engin. Elle ne vaut pas autorisation de départ : avis, contrôle technique et autorisation suivent.
 * @var array $equipment  @var int|null $equipmentId  @var array $sites  @var \App\Security\SiteScope $ownSites  @var array $carriers  @var array $drivers
 */
// Engins proposés au sélecteur (recherche côté navigateur : code, nom, catégorie, site).
$equipmentJson = array_map(static fn (array $eq): array => [
    'id' => (int) $eq['id'], 'code' => $eq['code'], 'name' => $eq['name'], 'site_id' => (int) $eq['site_id'], 'site' => $eq['site'],
    'category' => $eq['category'] ?? '', 'status' => $eq['status'], 'status_label' => label('status', $eq['status']),
], $equipment);
$selectedEquipment = (int) old('equipment_id', $equipmentId ?? 0);
$selected = null;
foreach ($equipment as $eq) {
    if ((int) $eq['id'] === $selectedEquipment) {
        $selected = $eq;
    }
}
$siteOptions = ['' => '—'];
foreach ($sites as $site) {
    $siteOptions[$site['id']] = $site['name'] . (!empty($site['code']) ? ' (' . $site['code'] . ')' : '');
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
<script type="application/json" id="fleet-equipment"><?= json_for_html($equipmentJson) ?></script>
<form method="post" action="<?= e(url('/fleet-transfers')) ?>" class="card form">
    <?= csrf_field() ?>
    <p class="muted"><?= e(t('fleet.form_help')) ?></p>
    <div class="form-grid">
        <div class="field span-2 fleet-eq" data-fleet-equipment>
            <label for="fleet-eq-search" class="required"><?= e(t('fields.equipment_id')) ?></label>
            <input type="hidden" name="equipment_id" value="<?= $selected ? e($selected['id']) : '' ?>" data-fe-value>
            <div class="fleet-eq__selected" data-fe-selected<?= $selected ? '' : ' hidden' ?>>
                <div data-fe-card>
                    <?php if ($selected): ?>
                        <strong><?= e($selected['code']) ?></strong> · <?= e($selected['name']) ?>
                        <div class="muted small"><?= e(t('fleet.eq_current_site', ['site' => $selected['site']])) ?> · <?= status_badge($selected['status']) ?></div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn btn--sm" data-fe-clear><?= e(t('common.change')) ?></button>
            </div>
            <div data-fe-search<?= $selected ? ' hidden' : '' ?>>
                <input id="fleet-eq-search" type="search" class="input" autocomplete="off" placeholder="<?= e(t('fleet.equipment_search')) ?>"<?= field_error('equipment_id') ? ' aria-invalid="true"' : '' ?>>
                <ul class="search-results fleet-eq__results" data-fe-results></ul>
            </div>
            <span class="hint"><?= e(t('fleet.equipment_hint', ['count' => count($equipment)])) ?></span>
            <?php if (field_error('equipment_id')): ?><span class="error"><?= e(field_error('equipment_id')) ?></span><?php endif; ?>
        </div>
        <?= $f('to_site_id', 'select', ['required' => true, 'labelKey' => 'fleet.to_site', 'hint' => t('fleet.to_site_hint'), 'options' => $siteOptions, 'attrs' => ['data-fe-destination' => '1']]) ?>
        <div class="fleet-route" data-fe-route hidden>
            <span class="fleet-route__label"><?= e(t('fleet.eq_route')) ?></span>
            <span data-fe-from></span><i data-lucide="arrow-right"></i><span data-fe-to></span>
        </div>
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
