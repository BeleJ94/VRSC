<?php
/**
 * Factures fournisseurs : enregistrement contre une commande (numéro unique par fournisseur) et suivi des impayés.
 * @var array $invoices  @var array $orders  @var int|null $orderId  @var bool $unpaidOnly  @var bool $canRecord  @var bool $canRequest
 */
$orderOptions = ['' => '—'];
foreach ($orders as $order) {
    $orderOptions[$order['id']] = $order['reference'] . ' – ' . $order['supplier'] . ' (' . format_money($order['total_amount']) . ')';
}
$f = static fn (string $name, string $type, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $extra['default'] ?? null),
]);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="file-text"></i></span>
        <div>
            <h1><?= e(t('finance.invoices')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('finance.invoices_subtitle')) ?></span></div>
        </div>
    </div>
</div>
<?= $view->partial('finance/_tabs', ['active' => 'invoices']) ?>

<?php if ($canRecord): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('finance.invoice_new')) ?></h2></div>
    <form method="post" action="<?= e(url('/finance/invoices')) ?>" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= $f('order_id', 'select', ['required' => true, 'default' => $orderId, 'options' => $orderOptions]) ?>
            <?= $f('invoice_number', 'text', ['required' => true, 'attrs' => ['maxlength' => 60]]) ?>
            <?= $f('invoice_date', 'date', ['required' => true, 'default' => date('Y-m-d'), 'attrs' => ['max' => date('Y-m-d')]]) ?>
            <?= $f('due_date', 'date') ?>
            <?= $f('amount', 'number', ['required' => true, 'attrs' => ['min' => '0.01', 'step' => '0.01']]) ?>
            <?= $f('note', 'text', ['attrs' => ['maxlength' => 255]]) ?>
            <div class="field">
                <label for="f-invoice-file"><?= e(t('finance.invoice_file')) ?></label>
                <input id="f-invoice-file" type="file" name="invoice_file" class="input" accept="application/pdf,image/jpeg,image/png,image/webp">
            </div>
        </div>
        <p class="hint small"><?= e(t('finance.invoice_rule')) ?></p>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('finance.invoice_record')) ?></button></div>
    </form>
</section>
<?php endif; ?>

<nav class="chips" aria-label="<?= e(t('fields.status')) ?>">
    <a class="chip-btn" aria-pressed="<?= $unpaidOnly ? 'false' : 'true' ?>" href="<?= e(url('/finance/invoices')) ?>"><?= e(t('common.all')) ?></a>
    <a class="chip-btn" aria-pressed="<?= $unpaidOnly ? 'true' : 'false' ?>" href="<?= e(url('/finance/invoices', ['unpaid' => 1])) ?>"><?= e(t('finance.unpaid_only')) ?></a>
</nav>

<section class="card">
    <?php if (field_error('cancel_reason')): ?><p class="error"><?= e(field_error('cancel_reason')) ?></p><?php endif; ?>
    <?php if ($invoices === []): ?>
        <div class="empty-state"><i data-lucide="file-text"></i><p><?= e(t('finance.invoices_none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('fields.invoice_number')) ?></th>
                        <th><?= e(t('fields.supplier_id')) ?></th>
                        <th><?= e(t('purchases.order')) ?></th>
                        <th><?= e(t('fields.due_date')) ?></th>
                        <th class="num"><?= e(t('fields.amount')) ?></th>
                        <th class="num"><?= e(t('finance.paid')) ?></th>
                        <th class="num"><?= e(t('finance.unpaid')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $i): ?>
                        <?php $cancelled = $i['status'] === 'cancelled'; ?>
                        <tr>
                            <td><strong><?= e($i['invoice_number']) ?></strong><br><span class="muted small"><?= e(format_date($i['invoice_date'])) ?> · <?= e($i['site']) ?></span>
                                <?php if ($cancelled): ?><br><span class="badge badge--neutral"><?= e(t('order_status.cancelled')) ?></span> <span class="muted small"><?= e($i['cancel_reason']) ?></span><?php endif; ?>
                            </td>
                            <td><?= e($i['supplier']) ?></td>
                            <td><a href="<?= e(url('/purchases/' . $i['order_id'])) ?>"><?= e($i['order_reference']) ?></a><br><a class="muted small" href="<?= e(url('/edb/' . $i['request_id'])) ?>"><?= e($i['request_reference']) ?></a></td>
                            <td class="nowrap<?= !$cancelled && (float) $i['unpaid'] > 0 && $i['due_date'] !== null && (int) $i['overdue_days'] > 0 ? ' overdue' : '' ?>"><?= e($i['due_date'] ? format_date($i['due_date']) : '—') ?></td>
                            <td class="num"><?= e(format_money($i['amount'])) ?></td>
                            <td class="num"><?= e(format_money($i['paid'])) ?><?php if ((float) $i['in_progress'] > 0): ?><br><span class="muted small"><?= e(t('finance.in_progress', ['amount' => format_money($i['in_progress'])])) ?></span><?php endif; ?></td>
                            <td class="num"><strong><?= $cancelled ? '—' : e(format_money($i['unpaid'])) ?></strong></td>
                            <td class="num">
                                <?php if (!$cancelled && $canRequest && (float) $i['payable'] > 0): ?>
                                    <a class="btn btn--sm btn--primary" href="<?= e(url('/finance/payments/create', ['invoice_id' => $i['id']])) ?>"><i data-lucide="banknote"></i><?= e(t('finance.request_payment')) ?></a>
                                <?php endif; ?>
                                <?php if (!$cancelled && $canRecord && (float) $i['paid'] == 0.0 && (float) $i['in_progress'] == 0.0): ?>
                                    <form method="post" action="<?= e(url('/finance/invoices/' . $i['id'] . '/cancel')) ?>" class="filters">
                                        <?= csrf_field() ?>
                                        <input type="text" name="cancel_reason" class="input" maxlength="255" placeholder="<?= e(t('stock.cancel_reason')) ?>" aria-label="<?= e(t('stock.cancel_reason')) ?>" required>
                                        <button type="submit" class="btn btn--sm"><i data-lucide="ban"></i><?= e(t('common.cancel')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
