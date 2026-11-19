<?php
/**
 * Suivi des transferts d'engins : en attente d'avis, de contrôle, d'autorisation, de départ, de réception ; retards et écarts.
 * @var array $transfers  @var array $counts  @var int $late  @var int $discrepancies  @var string|null $stage  @var string|null $status  @var bool $canRequest
 */
$badge = ['requested' => 'waiting_part', 'authorized' => 'maintenance', 'departed' => 'high', 'received' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
$conclusionBadge = ['fit' => 'available', 'conditional' => 'waiting_part', 'unfit' => 'breakdown'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="route"></i></span>
        <div>
            <h1><?= e(t('nav.fleet_transfers')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('fleet.subtitle')) ?></span></div>
        </div>
    </div>
    <?php if ($canRequest): ?>
        <div class="page-header__actions">
            <a class="btn btn--primary" href="<?= e(url('/fleet-transfers/create')) ?>"><i data-lucide="plus"></i><?= e(t('fleet.new')) ?></a>
        </div>
    <?php endif; ?>
</div>

<div class="kpis kpis--compact">
    <?php foreach ($counts as $key => $count): ?>
        <a class="kpi<?= $count > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/fleet-transfers', ['stage' => $key])) ?>">
            <div class="kpi__label"><?= e(t('fleet_stage.' . $key)) ?></div>
            <div class="kpi__value"><?= e($count) ?></div>
        </a>
    <?php endforeach; ?>
    <a class="kpi<?= $late > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/fleet-transfers', ['late' => 1])) ?>">
        <div class="kpi__label"><?= e(t('fleet.late')) ?></div>
        <div class="kpi__value"><?= e($late) ?></div>
        <div class="kpi__hint"><?= e(t('fleet.discrepancies', ['count' => $discrepancies])) ?></div>
    </a>
</div>

<nav class="chips" aria-label="<?= e(t('fields.status')) ?>">
    <a class="chip-btn" aria-pressed="<?= $stage === null && $status === null ? 'true' : 'false' ?>" href="<?= e(url('/fleet-transfers')) ?>"><?= e(t('common.all')) ?></a>
    <?php foreach (\App\Controllers\FleetTransferController::STATUSES as $s): ?>
        <a class="chip-btn" aria-pressed="<?= $status === $s ? 'true' : 'false' ?>" href="<?= e(url('/fleet-transfers', ['status' => $s])) ?>"><?= e(t('fleet_status.' . $s)) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <?php if ($transfers === []): ?>
        <div class="empty-state"><i data-lucide="route"></i><p><?= e(t('fleet.none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('fleet.otv')) ?></th>
                        <th><?= e(t('fields.equipment_id')) ?></th>
                        <th><?= e(t('fleet.route')) ?></th>
                        <th><?= e(t('fleet.planned')) ?></th>
                        <th><?= e(t('fields.carrier_id')) ?></th>
                        <th><?= e(t('fleet.waiting_for')) ?></th>
                        <th><?= e(t('fields.status')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transfers as $tr): ?>
                        <tr>
                            <td><a href="<?= e(url('/fleet-transfers/' . $tr['id'])) ?>"><strong><?= e($tr['reference']) ?></strong></a><br><span class="muted small"><?= e($tr['requester']) ?> · <?= e(format_date($tr['requested_at'])) ?></span></td>
                            <td><strong><?= e($tr['equipment_code']) ?></strong><br><span class="muted"><?= e($tr['equipment_name']) ?></span></td>
                            <td><?= e($tr['from_site']) ?> → <?= e($tr['to_site']) ?></td>
                            <td class="nowrap<?= (int) $tr['is_late'] === 1 ? ' overdue' : '' ?>"><?= e($tr['planned_departure'] ? format_date($tr['planned_departure'], true) : '—') ?>
                                <?php if ($tr['transit_hours'] !== null): ?><br><span class="muted small"><?= e(t('fleet.transit_hours', ['n' => (int) $tr['transit_hours']])) ?></span><?php endif; ?>
                            </td>
                            <td><?= e($tr['carrier'] ?? t('fleet_mode.' . $tr['transport_mode'])) ?></td>
                            <td>
                                <?= $tr['stage'] !== null ? e(t('fleet_stage.' . $tr['stage'])) : '—' ?>
                                <?php if ($tr['last_conclusion'] !== null && in_array($tr['status'], ['requested', 'authorized'], true)): ?>
                                    <br><span class="badge badge--<?= e($conclusionBadge[$tr['last_conclusion']]) ?>"><?= e(t('fleet_conclusion.' . $tr['last_conclusion'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge--<?= e($badge[$tr['status']]) ?>"><?= e(t('fleet_status.' . $tr['status'])) ?></span>
                                <?php if ((int) $tr['is_late'] === 1): ?><br><span class="badge badge--critical"><?= e(t('fleet.late')) ?></span><?php endif; ?>
                                <?php if ((int) $tr['has_discrepancy'] === 1): ?><br><span class="badge badge--breakdown"><?= e(t('stock.discrepancy')) ?></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
