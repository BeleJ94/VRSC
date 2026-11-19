<?php
/**
 * Commandes fournisseurs et reste à livrer (matériaux commandés encore chez le fournisseur, § 5.1).
 * @var array $orders  @var array $outstanding  @var string|null $status
 */
$badge = ['issued' => 'maintenance', 'partially_received' => 'high', 'received' => 'available', 'cancelled' => 'neutral'];
$outstandingValue = array_sum(array_map(static fn (array $o) => (float) $o['value_outstanding'], $outstanding));
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="receipt-text"></i></span>
        <div>
            <h1><?= e(t('nav.purchases')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('purchases.subtitle')) ?></span></div>
        </div>
    </div>
</div>

<nav class="chips" aria-label="<?= e(t('fields.status')) ?>">
    <a class="chip-btn" aria-pressed="<?= $status === null ? 'true' : 'false' ?>" href="<?= e(url('/purchases')) ?>"><?= e(t('common.all')) ?></a>
    <?php foreach (array_keys($badge) as $s): ?>
        <a class="chip-btn" aria-pressed="<?= $status === $s ? 'true' : 'false' ?>" href="<?= e(url('/purchases', ['status' => $s])) ?>"><?= e(t('order_status.' . $s)) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <?php if ($orders === []): ?>
        <div class="empty-state"><i data-lucide="receipt-text"></i><p><?= e(t('purchases.none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th><?= e(t('purchases.order')) ?></th><th><?= e(t('nav.edb')) ?></th><th><?= e(t('fields.supplier_id')) ?></th><th><?= e(t('fields.site_id')) ?></th><th><?= e(t('fields.date')) ?></th><th class="num"><?= e(t('fields.amount')) ?></th><th class="num"><?= e(t('edb.received_ordered')) ?></th><th><?= e(t('fields.status')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><a href="<?= e(url('/purchases/' . $order['id'])) ?>"><strong><?= e($order['reference']) ?></strong></a></td>
                            <td><a href="<?= e(url('/edb/' . $order['request_id'])) ?>"><?= e($order['request_reference']) ?></a></td>
                            <td><?= e($order['supplier']) ?></td>
                            <td><?= e($order['site']) ?></td>
                            <td class="nowrap"><?= e(format_date($order['ordered_at'])) ?></td>
                            <td class="num"><?= e(format_money($order['total_amount'])) ?></td>
                            <td class="num"><?= e(format_qty($order['qty_received'])) ?> / <?= e(format_qty($order['qty_ordered'])) ?></td>
                            <td><span class="badge badge--<?= e($badge[$order['status']]) ?>"><?= e(t('order_status.' . $order['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="outstanding">
    <div class="card__title"><h2><?= e(t('purchases.outstanding')) ?></h2><span class="muted small"><?= e(format_money($outstandingValue)) ?></span></div>
    <?php if ($outstanding === []): ?>
        <p class="empty"><?= e(t('purchases.outstanding_none')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('purchases.order')) ?></th><th><?= e(t('fields.supplier_id')) ?></th><th><?= e(t('fields.site_id')) ?></th><th><?= e(t('edb.need')) ?></th><th class="num"><?= e(t('purchases.ordered')) ?></th><th class="num"><?= e(t('stock.qty_received')) ?></th><th class="num"><?= e(t('purchases.at_supplier')) ?></th><th class="num"><?= e(t('js.stock.value')) ?></th><th class="num"><?= e(t('purchases.late')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($outstanding as $row): ?>
                        <tr>
                            <td><a href="<?= e(url('/purchases/' . $row['order_id'])) ?>"><strong><?= e($row['reference']) ?></strong></a><br><span class="muted small"><?= e($row['request_reference']) ?></span></td>
                            <td><?= e($row['supplier']) ?></td>
                            <td><?= e($row['site']) ?></td>
                            <td><?= e($row['description']) ?></td>
                            <td class="num"><?= e(format_qty($row['qty_ordered'])) ?> <?= e($row['unit']) ?></td>
                            <td class="num"><?= e(format_qty($row['qty_received'])) ?></td>
                            <td class="num"><strong><?= e(format_qty($row['qty_outstanding'])) ?></strong></td>
                            <td class="num"><?= e(format_money($row['value_outstanding'])) ?></td>
                            <td class="num<?= (int) $row['late_days'] > 0 && $row['expected_on'] !== null ? ' overdue' : '' ?>"><?= $row['expected_on'] !== null && (int) $row['late_days'] > 0 ? e(t('stock.days', ['n' => (int) $row['late_days']])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
