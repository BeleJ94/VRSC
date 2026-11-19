<?php
/**
 * Bon de commande : lignes (commandé / reçu / refusé / reste chez le fournisseur), réceptions et saisie d'un bon de réception.
 * @var array $order  @var array $lines  @var array $receipts  @var array $carriers  @var bool $canReceive  @var bool $canCancel
 */
$badge = ['issued' => 'maintenance', 'partially_received' => 'high', 'received' => 'available', 'cancelled' => 'neutral'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="receipt-text"></i></span>
        <div>
            <h1><?= e(t('purchases.order')) ?> <?= e($order['reference']) ?></h1>
            <div class="page-title__meta">
                <span><?= e($order['supplier']) ?> · <?= e($order['site']) ?></span>
                <span class="badge badge--<?= e($badge[$order['status']]) ?>"><?= e(t('order_status.' . $order['status'])) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/edb/' . $order['request_id'])) ?>"><i data-lucide="arrow-left"></i><?= e($order['request_reference']) ?></a>
        <button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button>
    </div>
</div>

<section class="card">
    <div class="grid grid--3">
        <div><span class="muted small"><?= e(t('nav.edb')) ?></span><br><a href="<?= e(url('/edb/' . $order['request_id'])) ?>"><strong><?= e($order['request_reference']) ?></strong></a> — <?= e($order['request_subject']) ?></div>
        <div><span class="muted small"><?= e(t('purchases.buyer')) ?></span><br><?= e($order['buyer']) ?> · <?= e(format_date($order['ordered_at'], true)) ?></div>
        <div><span class="muted small"><?= e(t('fields.expected_on')) ?></span><br><?= e($order['expected_on'] ? format_date($order['expected_on']) : '—') ?></div>
        <div><span class="muted small"><?= e(t('fields.amount')) ?></span><br><strong><?= e(format_money($order['total_amount'])) ?></strong></div>
        <div><span class="muted small"><?= e(t('fields.note')) ?></span><br><?= e($order['note'] ?? '—') ?></div>
        <?php if ($order['status'] === 'cancelled'): ?><div><span class="muted small"><?= e(t('stock.cancel_reason')) ?></span><br><?= e($order['cancel_reason']) ?></div><?php endif; ?>
    </div>
</section>

<?php if ($canReceive): ?><form method="post" action="<?= e(url('/purchases/' . $order['id'] . '/receive')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><?php endif; ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('stock.lines')) ?></h2></div>
    <?php foreach (['accepted', 'reject_reason', 'status', 'delivery_note', 'attachment'] as $errorField): ?>
        <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
    <?php endforeach; ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('edb.need')) ?></th>
                    <th class="num"><?= e(t('purchases.ordered')) ?></th>
                    <th class="num"><?= e(t('edb.unit_price')) ?></th>
                    <th class="num"><?= e(t('stock.qty_received')) ?></th>
                    <th class="num"><?= e(t('purchases.at_supplier')) ?></th>
                    <?php if ($canReceive): ?><th class="num"><?= e(t('purchases.accept')) ?></th><th class="num"><?= e(t('purchases.reject')) ?></th><th><?= e(t('purchases.reject_reason')) ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><strong><?= e($line['description']) ?></strong><?php if ($line['part_id'] === null): ?><br><span class="muted small"><?= e(t('edb.not_stocked')) ?></span><?php endif; ?></td>
                        <td class="num"><?= e(format_qty($line['qty_ordered'])) ?> <?= e($line['unit']) ?></td>
                        <td class="num"><?= e(format_money($line['unit_price'])) ?></td>
                        <td class="num"><?= e(format_qty($line['qty_received'])) ?><?php if ((float) $line['qty_rejected'] > 0): ?><br><span class="muted small"><?= e(t('purchases.rejected_short', ['qty' => format_qty($line['qty_rejected'])])) ?></span><?php endif; ?></td>
                        <td class="num<?= (float) $line['qty_outstanding'] > 0 ? ' overdue' : '' ?>"><?= e(format_qty($line['qty_outstanding'])) ?></td>
                        <?php if ($canReceive): ?>
                            <td class="num"><input type="number" class="input" name="accepted[<?= e($line['id']) ?>]" min="0" max="<?= e((float) $line['qty_outstanding']) ?>" step="0.01" value="<?= e((float) $line['qty_outstanding']) ?>" aria-label="<?= e(t('purchases.accept')) ?>"></td>
                            <td class="num"><input type="number" class="input" name="rejected[<?= e($line['id']) ?>]" min="0" step="0.01" value="0" aria-label="<?= e(t('purchases.reject')) ?>"></td>
                            <td><input type="text" class="input" name="reject_reason[<?= e($line['id']) ?>]" maxlength="255" aria-label="<?= e(t('purchases.reject_reason')) ?>"></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($canReceive): ?>
        <h2 class="section-title"><i data-lucide="package-check"></i><?= e(t('purchases.new_receipt')) ?></h2>
        <div class="form-grid">
            <?= $view->partial('partials/field', ['name' => 'delivery_note', 'labelKey' => 'fields.delivery_note', 'type' => 'text', 'required' => true, 'value' => old('delivery_note'), 'attrs' => ['maxlength' => 60]]) ?>
            <?= $view->partial('partials/field', ['name' => 'carrier_id', 'labelKey' => 'fields.carrier_id', 'type' => 'select', 'value' => old('carrier_id'), 'options' => ['' => '—'] + $carriers]) ?>
            <?= $view->partial('partials/field', ['name' => 'vehicle_plate', 'labelKey' => 'fields.vehicle_plate', 'type' => 'text', 'value' => old('vehicle_plate'), 'attrs' => ['maxlength' => 30]]) ?>
            <?= $view->partial('partials/field', ['name' => 'driver_name', 'labelKey' => 'fields.driver_name', 'type' => 'text', 'value' => old('driver_name'), 'attrs' => ['maxlength' => 120]]) ?>
            <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'type' => 'text', 'value' => old('note'), 'attrs' => ['maxlength' => 255]]) ?>
            <div class="field">
                <label for="f-delivery-file"><?= e(t('purchases.delivery_file')) ?></label>
                <input id="f-delivery-file" type="file" name="delivery_file" class="input" accept="application/pdf,image/jpeg,image/png,image/webp">
            </div>
        </div>
        <p class="hint small"><?= e(t('purchases.receipt_rule')) ?></p>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="package-check"></i><?= e(t('purchases.record_receipt')) ?></button></div>
    <?php elseif (in_array($order['status'], ['issued', 'partially_received'], true)): ?>
        <p class="muted small"><?= e(t('purchases.receive_other')) ?></p>
    <?php endif; ?>
</section>
<?php if ($canReceive): ?></form><?php endif; ?>

<section class="card">
    <div class="card__title"><h2><?= e(t('purchases.receipts')) ?></h2></div>
    <?php if ($receipts === []): ?>
        <p class="empty"><?= e(t('purchases.receipts_none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('purchases.receipt')) ?></th><th><?= e(t('fields.date')) ?></th><th><?= e(t('fields.delivery_note')) ?></th><th><?= e(t('purchases.transport')) ?></th><th class="num"><?= e(t('purchases.accept')) ?></th><th class="num"><?= e(t('purchases.reject')) ?></th><th><?= e(t('stock.received_by')) ?></th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($receipts as $receipt): ?>
                        <tr>
                            <td class="mono"><strong><?= e($receipt['reference']) ?></strong></td>
                            <td class="nowrap"><?= e(format_date($receipt['received_at'], true)) ?></td>
                            <td><?= e($receipt['delivery_note']) ?></td>
                            <td><?= e(implode(' · ', array_filter([$receipt['carrier'], $receipt['vehicle_plate'], $receipt['driver_name']]))) ?></td>
                            <td class="num"><?= e(format_qty($receipt['qty_accepted'])) ?></td>
                            <td class="num"><?= e(format_qty($receipt['qty_rejected'])) ?></td>
                            <td><?= e($receipt['receiver']) ?></td>
                            <td>
                                <?php foreach ($receipt['attachments'] as $attachment): ?>
                                    <a class="icon-btn" href="<?= e(url('/attachments/' . $attachment['id'])) ?>" target="_blank" rel="noopener" title="<?= e($attachment['original_name']) ?>" aria-label="<?= e($attachment['original_name']) ?>"><i data-lucide="paperclip"></i></a>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($canCancel): ?>
    <section class="card">
        <?php if (field_error('cancel_reason')): ?><p class="error"><?= e(field_error('cancel_reason')) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('/purchases/' . $order['id'] . '/cancel')) ?>" class="filters">
            <?= csrf_field() ?>
            <div class="field">
                <label for="cancel-reason"><?= e(t('stock.cancel_reason')) ?></label>
                <input id="cancel-reason" name="cancel_reason" class="input" maxlength="255" required>
            </div>
            <div class="field"><button type="submit" class="btn"><i data-lucide="ban"></i><?= e(t('purchases.cancel_order')) ?></button></div>
        </form>
    </section>
<?php endif; ?>
