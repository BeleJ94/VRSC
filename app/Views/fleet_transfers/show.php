<?php
/**
 * Ordre de transfert d'un engin : étapes, circuit d'approbation, check-list technique, départ, procès-verbal d'arrivée.
 * @var array $transfer  @var array $checks  @var array|null $lastCheck  @var array|null $approval  @var array $history
 * @var bool $canCheck  @var bool $canDepart  @var bool $blockedUnfit  @var bool $canReceive  @var bool $canCancel  @var array $carriers  @var array $document
 */
use App\Services\FleetTransferService;

$view->share('pageScripts', ['js/pm-doc.js']);
$tr = $transfer;
$badge = ['requested' => 'waiting_part', 'authorized' => 'maintenance', 'departed' => 'high', 'received' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
$conclusionBadge = ['fit' => 'available', 'conditional' => 'waiting_part', 'unfit' => 'breakdown'];
$resultBadge = ['ok' => 'available', 'reserve' => 'waiting_part', 'nok' => 'breakdown', 'na' => 'neutral'];
$meters = static function ($hours, $km, $fuel = null): string {
    $parts = [];
    if ($hours !== null) { $parts[] = format_number($hours) . ' h'; }
    if ($km !== null) { $parts[] = format_number($km) . ' km'; }
    if ($fuel !== null) { $parts[] = $fuel . ' %'; }
    return $parts === [] ? '—' : implode(' · ', $parts);
};
$kmFirst = $tr['meter_basis'] === 'km';
$f = static fn (string $name, string $type, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $extra['default'] ?? null),
]);
$steps = ['requested' => 0, 'authorized' => 1, 'departed' => 2, 'received' => 3];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="route"></i></span>
        <div>
            <h1><?= e(t('fleet.otv')) ?> <?= e($tr['reference']) ?></h1>
            <div class="page-title__meta">
                <span><strong><?= e($tr['equipment_code']) ?></strong> · <?= e($tr['from_site']) ?> → <?= e($tr['to_site']) ?></span>
                <span class="badge badge--<?= e($badge[$tr['status']]) ?>"><?= e(t('fleet_status.' . $tr['status'])) ?></span>
                <?php if ($tr['stage'] !== null): ?><span class="muted"><?= e(t('fleet.waiting_for')) ?> : <?= e(t('fleet_stage.' . $tr['stage'])) ?></span><?php endif; ?>
                <?php if ((int) $tr['is_late'] === 1): ?><span class="badge badge--critical"><?= e(t('fleet.late')) ?></span><?php endif; ?>
                <?php if ((int) $tr['has_discrepancy'] === 1): ?><span class="badge badge--breakdown"><?= e(t('stock.discrepancy')) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/fleet-transfers')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <button type="button" class="btn btn--primary" data-doc-pdf><i data-lucide="file-text"></i><?= e(t('fleet.pdf')) ?></button>
    </div>
</div>

<?php foreach (['status', 'conclusion'] as $errorField): ?>
    <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
<?php endforeach; ?>

<section class="card">
    <div class="grid grid--3">
        <div><span class="muted small"><?= e(t('fields.equipment_id')) ?></span><br><a href="<?= e(url('/equipment/' . $tr['equipment_id'])) ?>"><strong><?= e($tr['equipment_code']) ?></strong></a> — <?= e($tr['equipment_name']) ?><br><span class="muted small"><?= e($tr['plate_number'] ?: t('fleet.not_registered')) ?></span></div>
        <div><span class="muted small"><?= e(t('fields.reason')) ?></span><br><?= e($tr['reason']) ?></div>
        <div><span class="muted small"><?= e(t('approvals.requester')) ?></span><br><?= e($tr['requester']) ?> · <?= e(format_date($tr['requested_at'], true)) ?></div>
        <div><span class="muted small"><?= e(t('fleet.planned')) ?></span><br><?= e($tr['planned_departure'] ? format_date($tr['planned_departure'], true) : '—') ?> → <?= e($tr['planned_arrival'] ? format_date($tr['planned_arrival'], true) : '—') ?></div>
        <div><span class="muted small"><?= e(t('fleet.actual')) ?></span><br><?= e($tr['departed_at'] ? format_date($tr['departed_at'], true) : '—') ?> → <?= e($tr['received_at'] ? format_date($tr['received_at'], true) : '—') ?></div>
        <div><span class="muted small"><?= e(t('fields.transport_mode')) ?></span><br><?= e(t('fleet_mode.' . $tr['transport_mode'])) ?><?= $tr['carrier'] ? ' · ' . e($tr['carrier']) : '' ?></div>
        <div><span class="muted small"><?= e(t('fleet.driver')) ?></span><br><?= e($tr['driver_name'] ?? '—') ?> <?= e($tr['driver_phone'] ?? '') ?></div>
        <div><span class="muted small"><?= e(t('fleet.departure_meters')) ?></span><br><?= e($meters($tr['departure_hours'], $tr['departure_km'], $tr['departure_fuel'])) ?></div>
        <div><span class="muted small"><?= e(t('fleet.arrival_meters')) ?></span><br><?= e($meters($tr['arrival_hours'], $tr['arrival_km'], $tr['arrival_fuel'])) ?></div>
        <?php if ($tr['transport_conditions']): ?><div><span class="muted small"><?= e(t('fields.transport_conditions')) ?></span><br><?= e($tr['transport_conditions']) ?></div><?php endif; ?>
        <?php if ($tr['documents_refs']): ?><div><span class="muted small"><?= e(t('fleet.documents_refs')) ?></span><br><?= e($tr['documents_refs']) ?></div><?php endif; ?>
        <?php if ($tr['observations']): ?><div><span class="muted small"><?= e(t('fields.observations')) ?></span><br><?= e($tr['observations']) ?></div><?php endif; ?>
    </div>
    <?php if ($tr['status'] === 'cancelled'): ?><p><span class="badge badge--neutral"><?= e(t('fleet_status.cancelled')) ?></span> <?= e($tr['cancel_reason']) ?></p><?php endif; ?>
    <?php if ($tr['arrival_note']): ?><p><span class="badge badge--<?= (int) $tr['has_discrepancy'] === 1 ? 'breakdown' : 'neutral' ?>"><?= e(t('fleet.arrival_gaps')) ?></span> <?= e($tr['arrival_note']) ?></p><?php endif; ?>
    <?php if ($tr['status'] === 'requested'): ?><p class="muted small"><?= e(t('fleet.request_note')) ?></p><?php endif; ?>
</section>

<?= $view->partial('partials/approval', ['approval' => $approval]) ?>

<section class="card" id="checks">
    <div class="card__title"><h2><?= e(t('fleet.checklist')) ?> <?= e($tr['reference']) ?></h2>
        <?php if ($lastCheck !== null): ?><span class="badge badge--<?= e($conclusionBadge[$lastCheck['conclusion']]) ?>"><?= e(t('fleet_conclusion.' . $lastCheck['conclusion'])) ?></span><?php endif; ?>
    </div>
    <?php if ($checks === []): ?>
        <p class="empty"><?= e(t('fleet.no_check')) ?></p>
    <?php endif; ?>
    <?php foreach ($checks as $check): ?>
        <details<?= $check === end($checks) ? ' open' : '' ?>>
            <summary>
                <strong><?= e(t('fleet_check_kind.' . $check['kind'])) ?></strong> — <?= e($check['checker_name']) ?> · <?= e(format_date($check['checked_at'], true)) ?> · <?= e($meters($check['hour_meter'], $check['odometer_km'])) ?>
                <?php if ($check['conclusion'] !== null): ?><span class="badge badge--<?= e($conclusionBadge[$check['conclusion']]) ?>"><?= e(t('fleet_conclusion.' . $check['conclusion'])) ?></span><?php endif; ?>
            </summary>
            <div class="table-wrap">
                <table class="table table--compact">
                    <tbody>
                        <?php foreach (FleetTransferService::ITEMS as $item): ?>
                            <?php $entry = $check['items'][$item] ?? null; ?>
                            <tr>
                                <td><?= e(t('fleet_item.' . $item)) ?></td>
                                <td><?php if ($entry !== null): ?><span class="badge badge--<?= e($resultBadge[$entry['result']]) ?>"><?= e(t('fleet_result.' . $entry['result'])) ?></span><?php endif; ?></td>
                                <td><?= e($entry['note'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($check['remarks']): ?><p><span class="muted small"><?= e(t('fleet.reserves')) ?></span> <?= e($check['remarks']) ?></p><?php endif; ?>
            <?php foreach ($check['photos'] as $photo): ?>
                <a class="icon-btn" href="<?= e(url('/attachments/' . $photo['id'])) ?>" target="_blank" rel="noopener" title="<?= e($photo['original_name']) ?>" aria-label="<?= e($photo['original_name']) ?>"><i data-lucide="image"></i></a>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>

    <?php if ($canCheck): ?>
        <h2 class="section-title"><i data-lucide="clipboard-check"></i><?= e(t($lastCheck === null ? 'fleet.new_check' : 'fleet.recheck')) ?></h2>
        <form method="post" action="<?= e(url('/fleet-transfers/' . $tr['id'] . '/check')) ?>" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <?= $view->partial('fleet_transfers/_checklist', ['previous' => null, 'arrival' => false]) ?>
            <div class="form-grid">
                <?php if (!$kmFirst || $tr['equipment_hours'] !== null): ?><?= $f('hour_meter', 'number', ['default' => $tr['equipment_hours'], 'attrs' => ['min' => '0', 'step' => '1']]) ?><?php endif; ?>
                <?php if ($kmFirst || $tr['equipment_km'] !== null): ?><?= $f('odometer_km', 'number', ['default' => $tr['equipment_km'], 'attrs' => ['min' => '0', 'step' => '1']]) ?><?php endif; ?>
                <?= $f('conclusion', 'select', ['required' => true, 'options' => ['' => '—'] + array_combine(FleetTransferService::CONCLUSIONS, array_map(static fn (string $c) => t('fleet_conclusion.' . $c), FleetTransferService::CONCLUSIONS))]) ?>
                <?= $f('remarks', 'textarea', ['class' => 'span-2', 'attrs' => ['maxlength' => 1000, 'rows' => 2], 'hint' => t('fleet.remarks_hint')]) ?>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="clipboard-check"></i><?= e(t('fleet.save_check')) ?></button></div>
        </form>
    <?php endif; ?>
</section>

<?php if ($tr['status'] === 'authorized'): ?>
<section class="card" id="departure">
    <div class="card__title"><h2><?= e(t('fleet.departure')) ?></h2></div>
    <?php if ($blockedUnfit): ?>
        <p class="error"><?= e(t('validation.fleet_unfit')) ?></p>
    <?php elseif ($canDepart): ?>
        <?php foreach (['departure_hours', 'departure_km'] as $errorField): ?>
            <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
        <?php endforeach; ?>
        <form method="post" action="<?= e(url('/fleet-transfers/' . $tr['id'] . '/depart')) ?>" class="form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <?php if (!$kmFirst || $tr['equipment_hours'] !== null): ?><?= $f('departure_hours', 'number', ['default' => $tr['equipment_hours'], 'attrs' => ['min' => '0', 'step' => '1']]) ?><?php endif; ?>
                <?php if ($kmFirst || $tr['equipment_km'] !== null): ?><?= $f('departure_km', 'number', ['default' => $tr['equipment_km'], 'attrs' => ['min' => '0', 'step' => '1']]) ?><?php endif; ?>
                <?= $f('departure_fuel', 'number', ['default' => $tr['equipment_fuel'], 'attrs' => ['min' => '0', 'max' => '100', 'step' => '1']]) ?>
                <?= $f('handover_to', 'text', ['required' => true, 'default' => $tr['driver_name'], 'attrs' => ['maxlength' => 170], 'hint' => t('fleet.handover_hint')]) ?>
                <?= $f('driver_name', 'text', ['default' => $tr['driver_name'], 'attrs' => ['maxlength' => 170]]) ?>
                <?= $f('driver_phone', 'text', ['default' => $tr['driver_phone'], 'attrs' => ['maxlength' => 30]]) ?>
                <?= $f('convoy_leader', 'text', ['default' => $tr['convoy_leader'], 'attrs' => ['maxlength' => 170]]) ?>
                <?= $f('tractor_plate', 'text', ['default' => $tr['tractor_plate'], 'attrs' => ['maxlength' => 30]]) ?>
                <?= $f('trailer_plate', 'text', ['default' => $tr['trailer_plate'], 'attrs' => ['maxlength' => 30]]) ?>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="truck"></i><?= e(t('fleet.confirm_departure')) ?></button></div>
        </form>
    <?php else: ?>
        <p class="muted small"><?= e(t('fleet.departure_other')) ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tr['status'] === 'departed'): ?>
<section class="card" id="reception">
    <div class="card__title"><h2><?= e(t('fleet.reception')) ?></h2></div>
    <?php if ($canReceive): ?>
        <?php foreach (['arrival_hours', 'arrival_km', 'arrival_note'] as $errorField): ?>
            <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
        <?php endforeach; ?>
        <form method="post" action="<?= e(url('/fleet-transfers/' . $tr['id'] . '/receive')) ?>" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <?= $view->partial('fleet_transfers/_checklist', ['previous' => $lastCheck['items'] ?? null, 'arrival' => true]) ?>
            <div class="form-grid">
                <?php if ($tr['departure_hours'] !== null || !$kmFirst): ?><?= $f('arrival_hours', 'number', ['default' => $tr['departure_hours'], 'attrs' => ['min' => (string) (int) $tr['departure_hours'], 'step' => '1']]) ?><?php endif; ?>
                <?php if ($tr['departure_km'] !== null || $kmFirst): ?><?= $f('arrival_km', 'number', ['default' => $tr['departure_km'], 'attrs' => ['min' => (string) (int) $tr['departure_km'], 'step' => '1']]) ?><?php endif; ?>
                <?= $f('arrival_fuel', 'number', ['attrs' => ['min' => '0', 'max' => '100', 'step' => '1']]) ?>
                <?= $f('arrival_note', 'textarea', ['class' => 'span-2', 'attrs' => ['maxlength' => 1000, 'rows' => 2], 'hint' => t('fleet.arrival_hint')]) ?>
            </div>
            <p class="hint small"><?= e(t('fleet.reception_rule')) ?></p>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="map-pin-check"></i><?= e(t('fleet.confirm_reception')) ?></button></div>
        </form>
    <?php else: ?>
        <p class="muted small"><?= e(t('fleet.reception_other')) ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <div class="card__title"><h2><?= e(t('fleet.site_history')) ?></h2></div>
    <?php if ($history === []): ?>
        <p class="empty"><?= e(t('common.none')) ?></p>
    <?php else: ?>
        <table class="table table--compact">
            <thead><tr><th><?= e(t('fields.site_id')) ?></th><th><?= e(t('fields.starts_on')) ?></th><th><?= e(t('fields.ends_on')) ?></th><th><?= e(t('fleet.otv')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><strong><?= e($row['site']) ?></strong></td>
                        <td class="nowrap"><?= e(format_date($row['started_at'], true)) ?></td>
                        <td class="nowrap"><?= $row['ended_at'] !== null ? e(format_date($row['ended_at'], true)) : '<span class="badge badge--available">' . e(t('fleet.current_site')) . '</span>' ?></td>
                        <td class="mono"><?= e($row['transfer_reference'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if ($canCancel): ?>
    <section class="card">
        <?php if (field_error('cancel_reason')): ?><p class="error"><?= e(field_error('cancel_reason')) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('/fleet-transfers/' . $tr['id'] . '/cancel')) ?>" class="filters">
            <?= csrf_field() ?>
            <div class="field">
                <label for="cancel-reason"><?= e(t('stock.cancel_reason')) ?></label>
                <input id="cancel-reason" name="cancel_reason" class="input" maxlength="500" required>
            </div>
            <div class="field"><button type="submit" class="btn"><i data-lucide="ban"></i><?= e(t('fleet.cancel')) ?></button></div>
        </form>
    </section>
<?php endif; ?>

<script type="application/json" id="doc-data"><?= json_for_html($document) ?></script>
