<?php
/**
 * Rapprochement EDB – commande – réception – facture – paiement, commande par commande, avec les anomalies.
 * @var array $rows  @var bool $anomaliesOnly
 */
$flagBadge = ['paid_not_received' => 'breakdown', 'invoiced_not_received' => 'breakdown', 'received_not_invoiced' => 'waiting_part', 'unpaid' => 'waiting_part', 'not_delivered' => 'maintenance'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="git-compare-arrows"></i></span>
        <div>
            <h1><?= e(t('finance.reconciliation')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('finance.reconciliation_subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions"><button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button></div>
</div>
<?= $view->partial('finance/_tabs', ['active' => 'reconciliation']) ?>

<nav class="chips" aria-label="<?= e(t('fields.status')) ?>">
    <a class="chip-btn" aria-pressed="<?= $anomaliesOnly ? 'false' : 'true' ?>" href="<?= e(url('/finance/reconciliation')) ?>"><?= e(t('common.all')) ?></a>
    <a class="chip-btn" aria-pressed="<?= $anomaliesOnly ? 'true' : 'false' ?>" href="<?= e(url('/finance/reconciliation', ['anomalies' => 1])) ?>"><?= e(t('finance.anomalies_only')) ?></a>
</nav>

<section class="card">
    <?php if ($rows === []): ?>
        <div class="empty-state empty-state--ok"><i data-lucide="check-circle-2"></i><p><?= e(t('finance.reconciliation_none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('nav.edb')) ?></th>
                        <th><?= e(t('purchases.order')) ?></th>
                        <th><?= e(t('fields.supplier_id')) ?></th>
                        <th class="num"><?= e(t('purchases.ordered')) ?></th>
                        <th class="num"><?= e(t('stock.qty_received')) ?></th>
                        <th class="num"><?= e(t('finance.invoiced')) ?></th>
                        <th class="num"><?= e(t('finance.paid')) ?></th>
                        <th><?= e(t('finance.match')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="<?= e(url('/edb/' . $row['request_id'])) ?>"><strong><?= e($row['request_reference']) ?></strong></a><br><span class="muted small"><?= e(t('edb_status.' . $row['request_status'])) ?> · <?= e($row['site']) ?></span></td>
                            <td><a href="<?= e(url('/purchases/' . $row['order_id'])) ?>"><?= e($row['order_reference']) ?></a><br><span class="muted small"><?= e(format_date($row['ordered_at'])) ?></span></td>
                            <td><?= e($row['supplier']) ?></td>
                            <td class="num"><?= e(format_money($row['ordered'])) ?></td>
                            <td class="num"><?= e(format_money($row['received'])) ?></td>
                            <td class="num"><?= e(format_money($row['invoiced'])) ?></td>
                            <td class="num"><?= e(format_money($row['paid'])) ?></td>
                            <td>
                                <?php if ($row['matched']): ?>
                                    <span class="badge badge--available"><?= e(t('finance.matched')) ?></span>
                                <?php else: ?>
                                    <?php foreach ($row['flags'] as $flag): ?><span class="badge badge--<?= e($flagBadge[$flag]) ?>"><?= e(t('finance_flag.' . $flag)) ?></span> <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="hint small"><?= e(t('finance.reconciliation_rule')) ?></p>
</section>
