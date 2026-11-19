<?php
/**
 * Bon de transfert : étapes, lignes (demandé / expédié / reçu / écart), autorisation, expédition, réception.
 * @var array $transfer  @var array $lines  @var array|null $approval  @var bool $canShip  @var bool $canReceive  @var bool $canCancel  @var array $carriers
 */
$badge = ['requested' => 'waiting_part', 'authorized' => 'maintenance', 'shipped' => 'high', 'received' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
$status = $transfer['status'];
$shipped = in_array($status, ['shipped', 'received'], true);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="arrow-left-right"></i></span>
        <div>
            <h1><?= e(t('stock.voucher')) ?> <?= e($transfer['reference']) ?></h1>
            <div class="page-title__meta">
                <span><?= e($transfer['from_site']) ?> → <?= e($transfer['to_site']) ?></span>
                <span class="badge badge--<?= e($badge[$status]) ?>"><?= e(t('transfer_status.' . $status)) ?></span>
                <?php if ($status === 'shipped'): ?><span class="badge badge--high"><?= e(t('stock.in_transit')) ?></span><?php endif; ?>
                <?php if ((int) $transfer['has_discrepancy'] === 1): ?><span class="badge badge--breakdown"><?= e(t('stock.discrepancy')) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/stock/transfers')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button>
    </div>
</div>

<section class="card">
    <div class="grid grid--3">
        <div><span class="muted small"><?= e(t('fields.reason')) ?></span><br><strong><?= e($transfer['reason']) ?></strong></div>
        <div><span class="muted small"><?= e(t('approvals.requester')) ?></span><br><?= e($transfer['requester']) ?> · <?= e(format_date($transfer['requested_at'], true)) ?></div>
        <div><span class="muted small"><?= e(t('fields.carrier_id')) ?></span><br><?= e($transfer['carrier'] ?? '—') ?></div>
        <div><span class="muted small"><?= e(t('transfer_status.authorized')) ?></span><br><?= e($transfer['authorized_at'] ? format_date($transfer['authorized_at'], true) : '—') ?></div>
        <div><span class="muted small"><?= e(t('stock.shipped_by')) ?></span><br><?= $transfer['shipped_at'] ? e($transfer['shipper']) . ' · ' . e(format_date($transfer['shipped_at'], true)) : '—' ?></div>
        <div><span class="muted small"><?= e(t('stock.received_by')) ?></span><br><?= $transfer['received_at'] ? e($transfer['receiver']) . ' · ' . e(format_date($transfer['received_at'], true)) : '—' ?></div>
    </div>
    <?php if ($transfer['discrepancy_note']): ?>
        <p><span class="badge badge--breakdown"><?= e(t('stock.discrepancy')) ?></span> <?= e($transfer['discrepancy_note']) ?></p>
    <?php endif; ?>
</section>

<?php $formAction = $canShip ? 'ship' : ($canReceive ? 'receive' : null); ?>
<?php if ($formAction !== null): ?><form method="post" action="<?= e(url('/stock/transfers/' . $transfer['id'] . '/' . $formAction)) ?>"><?= csrf_field() ?><?php endif; ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('stock.lines')) ?></h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('stock.article')) ?></th>
                    <th class="num"><?= e(t('stock.qty_requested')) ?></th>
                    <?php if ($status === 'requested' || $status === 'authorized'): ?><th class="num"><?= e(t('stock.source_stock')) ?></th><?php endif; ?>
                    <th class="num"><?= e(t('stock.qty_shipped')) ?></th>
                    <th class="num"><?= e(t('stock.qty_received')) ?></th>
                    <th class="num"><?= e(t('stock.gap')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php $gap = $line['qty_received'] !== null ? (float) $line['qty_received'] - (float) $line['qty_shipped'] : null; ?>
                    <tr>
                        <td><a href="<?= e(url('/parts/' . $line['part_id'])) ?>"><strong><?= e($line['reference']) ?></strong></a><br><span class="muted"><?= e($line['name']) ?></span></td>
                        <td class="num"><?= e(format_qty($line['qty_requested'])) ?> <?= e($line['unit']) ?></td>
                        <?php if ($status === 'requested' || $status === 'authorized'): ?>
                            <td class="num"><?= e(format_qty($line['source_quantity'])) ?><br><span class="muted small"><?= e(t('stock.reserved_short', ['qty' => format_qty($line['source_reserved'])])) ?></span></td>
                        <?php endif; ?>
                        <td class="num">
                            <?php if ($canShip): ?>
                                <input type="number" class="input" name="shipped[<?= e($line['part_id']) ?>]" min="0" max="<?= e((float) $line['qty_requested']) ?>" step="0.01" value="<?= e((float) $line['qty_requested']) ?>" aria-label="<?= e(t('stock.qty_shipped')) ?>">
                            <?php else: ?>
                                <?= $line['qty_shipped'] !== null ? e(format_qty($line['qty_shipped'])) : '—' ?>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ($canReceive): ?>
                                <input type="number" class="input" name="received[<?= e($line['part_id']) ?>]" min="0" max="<?= e((float) $line['qty_shipped']) ?>" step="0.01" value="<?= e((float) $line['qty_shipped']) ?>" aria-label="<?= e(t('stock.qty_received')) ?>">
                            <?php else: ?>
                                <?= $line['qty_received'] !== null ? e(format_qty($line['qty_received'])) : '—' ?>
                            <?php endif; ?>
                        </td>
                        <td class="num<?= $gap !== null && abs($gap) > 0.0001 ? ' overdue' : '' ?>"><?= $gap !== null ? e(format_qty($gap)) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($canShip): ?>
        <div class="form-grid">
            <?= $view->partial('partials/field', ['name' => 'carrier_id', 'labelKey' => 'fields.carrier_id', 'type' => 'select', 'value' => $transfer['carrier_id'], 'options' => ['' => '—'] + $carriers]) ?>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="truck"></i><?= e(t('stock.ship')) ?></button></div>
    <?php elseif ($canReceive): ?>
        <div class="form-grid">
            <?= $view->partial('partials/field', ['name' => 'discrepancy_note', 'labelKey' => 'fields.discrepancy_note', 'type' => 'text', 'value' => old('discrepancy_note'),
                'class' => 'span-2', 'attrs' => ['maxlength' => 500], 'hint' => t('stock.discrepancy_hint')]) ?>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="package-check"></i><?= e(t('stock.receive')) ?></button></div>
    <?php elseif ($status === 'shipped'): ?>
        <p class="muted small"><?= e(t('stock.receive_other')) ?></p>
    <?php endif; ?>
</section>
<?php if ($formAction !== null): ?></form><?php endif; ?>

<?= $view->partial('partials/approval', ['approval' => $approval]) ?>

<?php if ($canCancel): ?>
    <section class="card">
        <form method="post" action="<?= e(url('/stock/transfers/' . $transfer['id'] . '/cancel')) ?>" class="filters">
            <?= csrf_field() ?>
            <div class="field">
                <label for="cancel-reason"><?= e(t('stock.cancel_reason')) ?></label>
                <input id="cancel-reason" name="comment" class="input" maxlength="255" required>
            </div>
            <div class="field"><button type="submit" class="btn"><i data-lucide="ban"></i><?= e(t('stock.cancel_transfer')) ?></button></div>
        </form>
    </section>
<?php endif; ?>

<section class="card print-only">
    <div class="grid grid--3">
        <div><strong><?= e(t('stock.sign_requester')) ?></strong><br><br><br>____________________</div>
        <div><strong><?= e(t('stock.sign_shipper')) ?></strong><br><br><br>____________________</div>
        <div><strong><?= e(t('stock.sign_receiver')) ?></strong><br><br><br>____________________</div>
    </div>
</section>
