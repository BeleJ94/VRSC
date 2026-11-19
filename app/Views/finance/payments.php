<?php
/**
 * Demandes de décaissement : à vérifier / approuver, à payer, payées, avances à justifier.
 * @var array $payments  @var string|null $status  @var bool $toJustify  @var bool $canRequest
 */
$badge = ['pending' => 'waiting_part', 'approved' => 'maintenance', 'paid' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="banknote"></i></span>
        <div>
            <h1><?= e(t('finance.payments')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('finance.payments_subtitle')) ?></span></div>
        </div>
    </div>
    <?php if ($canRequest): ?>
        <div class="page-header__actions">
            <a class="btn btn--primary" href="<?= e(url('/finance/payments/create')) ?>"><i data-lucide="plus"></i><?= e(t('finance.payment_new')) ?></a>
        </div>
    <?php endif; ?>
</div>
<?= $view->partial('finance/_tabs', ['active' => 'payments']) ?>

<nav class="chips" aria-label="<?= e(t('fields.status')) ?>">
    <a class="chip-btn" aria-pressed="<?= $status === null && !$toJustify ? 'true' : 'false' ?>" href="<?= e(url('/finance/payments')) ?>"><?= e(t('common.all')) ?></a>
    <?php foreach (\App\Repositories\FinanceRepository::STATUSES as $s): ?>
        <a class="chip-btn" aria-pressed="<?= $status === $s ? 'true' : 'false' ?>" href="<?= e(url('/finance/payments', ['status' => $s])) ?>"><?= e(t('payment_status.' . $s)) ?></a>
    <?php endforeach; ?>
    <a class="chip-btn" aria-pressed="<?= $toJustify ? 'true' : 'false' ?>" href="<?= e(url('/finance/payments', ['to_justify' => 1])) ?>"><?= e(t('finance.kpi_advances')) ?></a>
</nav>

<section class="card">
    <?php if ($payments === []): ?>
        <div class="empty-state"><i data-lucide="banknote"></i><p><?= e(t('finance.payments_none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('fields.reference')) ?></th>
                        <th><?= e(t('fields.beneficiary')) ?></th>
                        <th><?= e(t('finance.linked_to')) ?></th>
                        <th><?= e(t('finance.allocation')) ?></th>
                        <th class="num"><?= e(t('fields.amount')) ?></th>
                        <th><?= e(t('fields.status')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><a href="<?= e(url('/finance/payments/' . $p['id'])) ?>"><strong><?= e($p['reference']) ?></strong></a><br><span class="muted small"><?= e(t('payment_kind.' . $p['kind'])) ?> · <?= e(format_date($p['requested_at'])) ?></span></td>
                            <td><?= e($p['beneficiary']) ?><br><span class="muted small"><?= e($p['requester']) ?></span></td>
                            <td class="small"><?= e(implode(' · ', array_filter([$p['request_reference'], $p['order_reference'], $p['invoice_number']])) ?: t('finance.direct_expense')) ?></td>
                            <td class="small"><?= e(implode(' · ', array_filter([$p['site'], $p['project'], $p['cost_center']]))) ?></td>
                            <td class="num"><strong><?= e(format_money($p['amount'])) ?></strong>
                                <?php if ((float) $p['to_justify'] > 0): ?><br><span class="badge badge--waiting_part"><?= e(t('finance.to_justify', ['amount' => format_money($p['to_justify'])])) ?></span><?php endif; ?>
                            </td>
                            <td><span class="badge badge--<?= e($badge[$p['status']]) ?>"><?= e(t('payment_status.' . $p['status'])) ?></span>
                                <?php if ($p['status'] === 'paid'): ?><br><span class="muted small"><?= e($p['payment_method']) ?> · <?= e(format_date($p['paid_at'])) ?></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
