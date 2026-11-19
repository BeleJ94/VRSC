<?php
/**
 * Transferts intermagasins : suivi par étape (à autoriser, à expédier, en transit, reçus), retards et écarts.
 * @var array $transfers  @var string|null $status  @var bool $canRequest
 */
$badge = ['requested' => 'waiting_part', 'authorized' => 'maintenance', 'shipped' => 'high', 'received' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="arrow-left-right"></i></span>
        <div>
            <h1><?= e(t('stock.transfers')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('stock.transfers_subtitle')) ?></span></div>
        </div>
    </div>
    <?php if ($canRequest): ?>
        <div class="page-header__actions">
            <a class="btn btn--primary" href="<?= e(url('/stock/transfers/create')) ?>"><i data-lucide="plus"></i><?= e(t('stock.transfer_new')) ?></a>
        </div>
    <?php endif; ?>
</div>
<?= $view->partial('stock/_tabs', ['active' => 'transfers']) ?>

<nav class="chips" aria-label="<?= e(t('fields.status')) ?>">
    <a class="chip-btn" aria-pressed="<?= $status === null ? 'true' : 'false' ?>" href="<?= e(url('/stock/transfers')) ?>"><?= e(t('common.all')) ?></a>
    <?php foreach (\App\Controllers\StockTransferController::STATUSES as $s): ?>
        <a class="chip-btn" aria-pressed="<?= $status === $s ? 'true' : 'false' ?>" href="<?= e(url('/stock/transfers', ['status' => $s])) ?>"><?= e(t('transfer_status.' . $s)) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <?php if ($transfers === []): ?>
        <div class="empty-state"><i data-lucide="arrow-left-right"></i><p><?= e(t('stock.transfers_none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('stock.voucher')) ?></th>
                        <th><?= e(t('stock.from_site')) ?></th>
                        <th><?= e(t('stock.to_site')) ?></th>
                        <th><?= e(t('fields.reason')) ?></th>
                        <th><?= e(t('approvals.requester')) ?></th>
                        <th class="num"><?= e(t('stock.age')) ?></th>
                        <th><?= e(t('fields.status')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transfers as $tr): ?>
                        <tr>
                            <td><a href="<?= e(url('/stock/transfers/' . $tr['id'])) ?>"><strong><?= e($tr['reference']) ?></strong></a><br><span class="muted small"><?= e(t('stock.line_count', ['count' => $tr['line_count']])) ?></span></td>
                            <td><?= e($tr['from_site']) ?></td>
                            <td><?= e($tr['to_site']) ?></td>
                            <td><?= e($tr['reason']) ?></td>
                            <td><?= e($tr['requester']) ?><br><span class="muted small"><?= e(format_date($tr['requested_at'], true)) ?></span></td>
                            <td class="num"><?= e(t('stock.days', ['n' => (int) $tr['age_days']])) ?></td>
                            <td>
                                <span class="badge badge--<?= e($badge[$tr['status']]) ?>"><?= e(t('transfer_status.' . $tr['status'])) ?></span>
                                <?php if ((int) $tr['has_discrepancy'] === 1): ?><br><span class="badge badge--breakdown"><?= e(t('stock.discrepancy')) ?></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
