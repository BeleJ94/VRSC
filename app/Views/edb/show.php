<?php
/**
 * Fiche d'un EDB : besoin, avancement ligne par ligne (stock local, autres magasins, commandes), approbation,
 * devis comparés, commandes et preuves d'exécution.
 * @var array $edb  @var array $lines  @var array $stores  @var array $fulfilments  @var array $quotes  @var array $orders
 * @var array|null $approval  @var array $suppliers  @var bool $canIssue  @var bool $canTransfer  @var bool $canBuy  @var bool $canCancel
 */
$totalRequested = array_sum(array_map(static fn (array $l) => (float) $l['qty_requested'], $lines));
$totalSatisfied = array_sum(array_map(static fn (array $l) => (float) $l['qty_satisfied'], $lines));
$display = $edb['status'] === 'approved' && $totalSatisfied > 0 ? 'partial' : $edb['status'];
$badge = ['pending' => 'waiting_part', 'approved' => 'maintenance', 'partial' => 'high', 'executed' => 'available', 'cancelled' => 'neutral'];
$orderBadge = ['issued' => 'maintenance', 'partially_received' => 'high', 'received' => 'available', 'cancelled' => 'neutral'];
$received = array_sum(array_map(static fn (array $l) => (float) $l['value_satisfied'], $lines));
$engaged = array_sum(array_map(static fn (array $o) => $o['status'] !== 'cancelled' ? (float) $o['total_amount'] : 0.0, $orders));
$selected = null;
foreach ($quotes as $quote) {
    if ((int) $quote['is_selected'] === 1) {
        $selected = $quote;
    }
}
$toSource = array_values(array_filter($lines, static fn (array $l): bool => (float) $l['qty_to_source'] > 0));
$kindLabel = ['stock' => 'edb.kind_stock', 'transfer' => 'edb.kind_transfer', 'purchase' => 'edb.kind_purchase'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="file-pen-line"></i></span>
        <div>
            <h1><?= e($edb['reference']) ?> — <?= e($edb['subject']) ?></h1>
            <div class="page-title__meta">
                <span><?= e($edb['site']) ?> · <?= e($edb['requester']) ?> · <?= e(format_date($edb['requested_at'], true)) ?></span>
                <span class="badge badge--<?= e($badge[$display]) ?>"><?= e(t('edb_status.' . $display)) ?></span>
                <?php if ($edb['urgency'] !== 'normal'): ?><span class="badge badge--<?= $edb['urgency'] === 'critical' ? 'critical' : 'high' ?>"><?= e(t('urgency.' . $edb['urgency'])) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/edb')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button>
    </div>
</div>

<div class="kpis kpis--compact">
    <div class="kpi"><div class="kpi__label"><?= e(t('edb.estimated')) ?></div><div class="kpi__value"><?= $edb['estimated_amount'] !== null ? e(format_money($edb['estimated_amount'])) : e(t('edb.missing')) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('edb.engaged')) ?></div><div class="kpi__value"><?= e(format_money($engaged)) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('edb.received_value')) ?></div><div class="kpi__value"><?= e(format_money($received)) ?></div></div>
    <div class="kpi<?= $totalSatisfied < $totalRequested && $edb['status'] === 'approved' ? ' kpi--warn' : '' ?>">
        <div class="kpi__label"><?= e(t('edb.progress')) ?></div>
        <div class="kpi__value"><?= e(format_qty($totalSatisfied)) ?><small>/ <?= e(format_qty($totalRequested)) ?></small></div>
        <div class="meter meter--good"><span style="width: <?= e($totalRequested > 0 ? (int) round($totalSatisfied / $totalRequested * 100) : 0) ?>%"></span></div>
    </div>
</div>

<section class="card">
    <div class="grid grid--3">
        <div><span class="muted small"><?= e(t('fields.reason')) ?></span><br><?= e($edb['reason']) ?></div>
        <div><span class="muted small"><?= e(t('fields.category')) ?></span><br><?= e(t('edb_category.' . $edb['category'])) ?> · <?= e(t('urgency.' . $edb['urgency'])) ?></div>
        <div><span class="muted small"><?= e(t('fields.department_id')) ?></span><br><?= e($edb['department'] ?? '—') ?></div>
        <div><span class="muted small"><?= e(t('fields.project_id')) ?></span><br><?= e($edb['project'] ?? '—') ?></div>
        <div><span class="muted small"><?= e(t('fields.cost_center_id')) ?></span><br><?= e($edb['cost_center'] ?? '—') ?></div>
        <div><span class="muted small"><?= e(t('fields.equipment_id')) ?></span><br><?= e($edb['equipment'] ?? '—') ?></div>
    </div>
    <?php if ($edb['status'] === 'cancelled'): ?>
        <p><span class="badge badge--neutral"><?= e(t($edb['is_rejected'] ? 'edb.rejected' : 'edb_status.cancelled')) ?></span> <?= e($edb['cancel_reason']) ?> · <?= e(format_date($edb['cancelled_at'], true)) ?></p>
    <?php elseif ($edb['status'] === 'pending'): ?>
        <p class="muted"><?= e(t('edb.pending_note')) ?></p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card__title"><h2><?= e(t('edb.lines')) ?></h2></div>
    <?php foreach (['quantity', 'line_id', 'status'] as $errorField): ?>
        <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
    <?php endforeach; ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('edb.need')) ?></th>
                    <th class="num"><?= e(t('stock.qty_requested')) ?></th>
                    <th class="num"><?= e(t('edb.satisfied')) ?></th>
                    <th class="num"><?= e(t('edb.in_progress')) ?></th>
                    <th class="num"><?= e(t('edb.remaining')) ?></th>
                    <th><?= e(t('edb.sourcing')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php $toSourceQty = (float) $line['qty_to_source']; ?>
                    <tr>
                        <td><strong><?= e($line['description']) ?></strong>
                            <?php if ($line['est_unit_cost'] !== null): ?><br><span class="muted small"><?= e(t('edb.est_unit_cost')) ?> : <?= e(format_money($line['est_unit_cost'])) ?></span><?php endif; ?>
                        </td>
                        <td class="num"><?= e(format_qty($line['qty_requested'])) ?> <?= e($line['unit']) ?></td>
                        <td class="num"><?= e(format_qty($line['qty_satisfied'])) ?>
                            <?php if ((float) $line['qty_satisfied'] > 0): ?><br><span class="muted small"><?= e(t('edb.split', ['stock' => format_qty($line['qty_from_stock']), 'transfer' => format_qty($line['qty_transferred']), 'purchase' => format_qty($line['qty_purchased'])])) ?></span><?php endif; ?>
                        </td>
                        <td class="num"><?= e(format_qty((float) $line['qty_on_order'] + (float) $line['qty_in_transfer'])) ?></td>
                        <td class="num<?= (float) $line['qty_remaining'] > 0 ? ' overdue' : '' ?>"><?= e(format_qty($line['qty_remaining'])) ?></td>
                        <td>
                            <?php if ($line['part_id'] !== null): ?>
                                <span class="muted small"><?= e(t('edb.local_stock', ['qty' => format_qty($line['local_available'])])) ?> · <?= e(t('edb.other_stock', ['qty' => format_qty($line['other_available'])])) ?></span>
                            <?php else: ?>
                                <span class="muted small"><?= e(t('edb.not_stocked')) ?></span>
                            <?php endif; ?>
                            <?php if ($toSourceQty > 0 && $line['part_id'] !== null): ?>
                                <?php if ($canIssue && (float) $line['local_available'] > 0): ?>
                                    <form method="post" action="<?= e(url('/edb/' . $edb['id'] . '/issue')) ?>" class="filters">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="line_id" value="<?= e($line['id']) ?>">
                                        <input type="number" name="quantity" class="input" min="0.01" step="0.01" max="<?= e(min($toSourceQty, (float) $line['local_available'])) ?>" value="<?= e(min($toSourceQty, (float) $line['local_available'])) ?>" aria-label="<?= e(t('fields.quantity')) ?>">
                                        <button type="submit" class="btn btn--sm"><i data-lucide="package-minus"></i><?= e(t('edb.issue')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canTransfer && isset($stores[(int) $line['id']])): ?>
                                    <form method="post" action="<?= e(url('/edb/' . $edb['id'] . '/transfer')) ?>" class="filters">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="line_id" value="<?= e($line['id']) ?>">
                                        <select name="from_site_id" class="input" aria-label="<?= e(t('fields.from_site_id')) ?>">
                                            <?php foreach ($stores[(int) $line['id']] as $store): ?>
                                                <option value="<?= e($store['site_id']) ?>"><?= e($store['site']) ?> (<?= e(format_qty($store['available'])) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="number" name="quantity" class="input" min="0.01" step="0.01" max="<?= e($toSourceQty) ?>" value="<?= e(min($toSourceQty, (float) $stores[(int) $line['id']][0]['available'])) ?>" aria-label="<?= e(t('fields.quantity')) ?>">
                                        <button type="submit" class="btn btn--sm"><i data-lucide="arrow-left-right"></i><?= e(t('edb.transfer')) ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint small"><?= e(t('edb.rule')) ?></p>
</section>

<?= $view->partial('partials/approval', ['approval' => $approval]) ?>

<?php if ($edb['status'] !== 'pending' && ($quotes !== [] || $canBuy)): ?>
<section class="card" id="quotes">
    <div class="card__title"><h2><?= e(t('edb.quotes')) ?></h2><span class="muted small"><?= e(t('edb.quotes_count', ['count' => count($quotes)])) ?></span></div>
    <?php foreach (['selection_reason', 'quote_id', 'supplier_id', 'amount'] as $errorField): ?>
        <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($quotes !== []): ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('fields.supplier_id')) ?></th><th><?= e(t('fields.reference')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th><th class="num"><?= e(t('edb.delivery_days')) ?></th><th><?= e(t('fields.note')) ?></th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($quotes as $i => $quote): ?>
                        <tr>
                            <td><strong><?= e($quote['supplier']) ?></strong><?php if ($i === 0 && count($quotes) > 1): ?> <span class="badge badge--available"><?= e(t('edb.cheapest')) ?></span><?php endif; ?></td>
                            <td><?= e($quote['reference']) ?></td>
                            <td class="num"><?= e(format_money($quote['amount'])) ?></td>
                            <td class="num"><?= $quote['delivery_days'] !== null ? e($quote['delivery_days']) : '—' ?></td>
                            <td><?= e($quote['note']) ?><?php if ($quote['selection_reason']): ?><br><span class="muted small"><?= e(t('edb.selection_reason')) ?> : <?= e($quote['selection_reason']) ?></span><?php endif; ?></td>
                            <td class="num">
                                <?php if ((int) $quote['is_selected'] === 1): ?>
                                    <span class="badge badge--maintenance"><?= e(t('edb.selected')) ?></span>
                                <?php elseif ($canBuy): ?>
                                    <form method="post" action="<?= e(url('/edb/' . $edb['id'] . '/quotes/select')) ?>" class="filters">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="quote_id" value="<?= e($quote['id']) ?>">
                                        <?php if ($i > 0): ?><input type="text" name="selection_reason" class="input" maxlength="255" placeholder="<?= e(t('edb.selection_reason')) ?>" aria-label="<?= e(t('edb.selection_reason')) ?>"><?php endif; ?>
                                        <button type="submit" class="btn btn--sm"><i data-lucide="check"></i><?= e(t('edb.select')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($canBuy): ?>
        <form method="post" action="<?= e(url('/edb/' . $edb['id'] . '/quotes')) ?>" class="filters">
            <?= csrf_field() ?>
            <?= $view->partial('partials/field', ['name' => 'supplier_id', 'labelKey' => 'fields.supplier_id', 'type' => 'select', 'required' => true, 'value' => null, 'options' => ['' => '—'] + $suppliers]) ?>
            <?= $view->partial('partials/field', ['name' => 'reference', 'labelKey' => 'fields.reference', 'type' => 'text', 'value' => null, 'attrs' => ['maxlength' => 60]]) ?>
            <?= $view->partial('partials/field', ['name' => 'amount', 'labelKey' => 'fields.amount', 'type' => 'number', 'required' => true, 'value' => null, 'attrs' => ['min' => '0.01', 'step' => '0.01']]) ?>
            <?= $view->partial('partials/field', ['name' => 'delivery_days', 'labelKey' => 'fields.delivery_days', 'type' => 'number', 'value' => null, 'attrs' => ['min' => '0', 'step' => '1']]) ?>
            <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'type' => 'text', 'value' => null, 'attrs' => ['maxlength' => 255]]) ?>
            <div class="field"><button type="submit" class="btn"><i data-lucide="plus"></i><?= e(t('edb.add_quote')) ?></button></div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canBuy && $toSource !== []): ?>
<section class="card" id="order">
    <div class="card__title"><h2><?= e(t('edb.new_order')) ?></h2></div>
    <?php if ($selected === null): ?>
        <p class="muted"><?= e(t('validation.order_needs_quote')) ?></p>
    <?php else: ?>
        <?php if (field_error('lines')): ?><p class="error"><?= e(field_error('lines')) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('/edb/' . $edb['id'] . '/orders')) ?>">
            <?= csrf_field() ?>
            <p><?= e(t('edb.order_supplier', ['supplier' => $selected['supplier'], 'amount' => format_money($selected['amount'])])) ?></p>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th><?= e(t('edb.need')) ?></th><th class="num"><?= e(t('edb.to_source')) ?></th><th class="num"><?= e(t('edb.qty_to_order')) ?></th><th class="num"><?= e(t('edb.unit_price')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($toSource as $line): ?>
                            <tr>
                                <td><?= e($line['description']) ?></td>
                                <td class="num"><?= e(format_qty($line['qty_to_source'])) ?> <?= e($line['unit']) ?></td>
                                <td class="num"><input type="number" class="input" name="lines[<?= e($line['id']) ?>][quantity]" min="0" max="<?= e((float) $line['qty_to_source']) ?>" step="0.01" value="<?= e((float) $line['qty_to_source']) ?>" aria-label="<?= e(t('edb.qty_to_order')) ?>"></td>
                                <td class="num"><input type="number" class="input" name="lines[<?= e($line['id']) ?>][unit_price]" min="0" step="0.0001" value="<?= e($line['est_unit_cost'] !== null ? (float) $line['est_unit_cost'] : '') ?>" aria-label="<?= e(t('edb.unit_price')) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-grid">
                <?= $view->partial('partials/field', ['name' => 'expected_on', 'labelKey' => 'fields.expected_on', 'type' => 'date', 'value' => null]) ?>
                <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'type' => 'text', 'value' => null, 'attrs' => ['maxlength' => 255]]) ?>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="receipt-text"></i><?= e(t('edb.create_order')) ?></button></div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($orders !== []): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('nav.purchases')) ?></h2></div>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead><tr><th><?= e(t('fields.reference')) ?></th><th><?= e(t('fields.supplier_id')) ?></th><th><?= e(t('fields.date')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th><th class="num"><?= e(t('edb.received_ordered')) ?></th><th><?= e(t('fields.status')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><a href="<?= e(url('/purchases/' . $order['id'])) ?>"><strong><?= e($order['reference']) ?></strong></a></td>
                        <td><?= e($order['supplier']) ?></td>
                        <td class="nowrap"><?= e(format_date($order['ordered_at'])) ?></td>
                        <td class="num"><?= e(format_money($order['total_amount'])) ?></td>
                        <td class="num"><?= e(format_qty($order['qty_received'])) ?> / <?= e(format_qty($order['qty_ordered'])) ?></td>
                        <td><span class="badge badge--<?= e($orderBadge[$order['status']]) ?>"><?= e(t('order_status.' . $order['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($fulfilments !== []): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('edb.proofs')) ?></h2></div>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead><tr><th><?= e(t('fields.date')) ?></th><th><?= e(t('edb.need')) ?></th><th><?= e(t('edb.source')) ?></th><th class="num"><?= e(t('fields.quantity')) ?></th><th class="num"><?= e(t('js.stock.value')) ?></th><th><?= e(t('js.stock.document')) ?></th><th><?= e(t('approvals.actor')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($fulfilments as $f): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($f['done_at'], true)) ?></td>
                        <td><?= e($f['description']) ?></td>
                        <td><?= e(t($kindLabel[$f['kind']])) ?></td>
                        <td class="num"><?= e(format_qty($f['quantity'])) ?> <?= e($f['unit']) ?></td>
                        <td class="num"><?= $f['value'] !== null ? e(format_money($f['value'])) : e(t('edb.missing')) ?></td>
                        <td class="mono"><?= e($f['document_ref']) ?></td>
                        <td><?= e($f['user']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($canCancel): ?>
    <section class="card">
        <?php if (field_error('cancel_reason')): ?><p class="error"><?= e(field_error('cancel_reason')) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('/edb/' . $edb['id'] . '/cancel')) ?>" class="filters">
            <?= csrf_field() ?>
            <div class="field">
                <label for="cancel-reason"><?= e(t('stock.cancel_reason')) ?></label>
                <input id="cancel-reason" name="cancel_reason" class="input" maxlength="500" required>
            </div>
            <div class="field"><button type="submit" class="btn"><i data-lucide="ban"></i><?= e(t('edb.cancel')) ?></button></div>
        </form>
    </section>
<?php endif; ?>
