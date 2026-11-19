<?php
/**
 * Tableau de bord financier : à vérifier, à payer, impayés fournisseurs, engagements, avances à justifier, caisses.
 * @var array $summary  @var array $toPay  @var array $unpaid  @var array $advances  @var array $registers
 */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="landmark"></i></span>
        <div>
            <h1><?= e(t('nav.finance')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('finance.subtitle')) ?></span></div>
        </div>
    </div>
    <?php if ($currentUser->can('finance.request')): ?>
        <div class="page-header__actions">
            <a class="btn btn--primary" href="<?= e(url('/finance/payments/create')) ?>"><i data-lucide="plus"></i><?= e(t('finance.payment_new')) ?></a>
        </div>
    <?php endif; ?>
</div>
<?= $view->partial('finance/_tabs', ['active' => 'index']) ?>

<div class="kpis kpis--compact">
    <a class="kpi<?= (int) $summary['pending_count'] > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/finance/payments', ['status' => 'pending'])) ?>">
        <div class="kpi__label"><?= e(t('finance.kpi_pending')) ?></div>
        <div class="kpi__value"><?= e((int) $summary['pending_count']) ?></div>
        <div class="kpi__hint"><?= e(format_money($summary['pending_amount'])) ?></div>
    </a>
    <a class="kpi<?= (int) $summary['to_pay_count'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/finance/payments', ['status' => 'approved'])) ?>">
        <div class="kpi__label"><?= e(t('finance.kpi_to_pay')) ?></div>
        <div class="kpi__value"><?= e((int) $summary['to_pay_count']) ?></div>
        <div class="kpi__hint"><?= e(format_money($summary['to_pay_amount'])) ?></div>
    </a>
    <a class="kpi" href="<?= e(url('/finance/invoices', ['unpaid' => 1])) ?>">
        <div class="kpi__label"><?= e(t('finance.kpi_unpaid')) ?></div>
        <div class="kpi__value"><?= e(format_money($summary['unpaid_invoices'])) ?></div>
    </a>
    <a class="kpi" href="<?= e(url('/finance/reconciliation')) ?>">
        <div class="kpi__label"><?= e(t('finance.kpi_engaged')) ?></div>
        <div class="kpi__value"><?= e(format_money($summary['engaged_unpaid'])) ?></div>
        <div class="kpi__hint"><?= e(t('finance.kpi_edb', ['pending' => $summary['edb_pending'], 'open' => $summary['edb_open']])) ?></div>
    </a>
    <a class="kpi<?= (float) $summary['advances_to_justify'] > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/finance/payments', ['to_justify' => 1])) ?>">
        <div class="kpi__label"><?= e(t('finance.kpi_advances')) ?></div>
        <div class="kpi__value"><?= e(format_money($summary['advances_to_justify'])) ?></div>
    </a>
    <a class="kpi kpi--hero" href="<?= e(url('/finance/cash')) ?>">
        <div class="kpi__label"><?= e(t('finance.kpi_cash')) ?></div>
        <div class="kpi__value"><?= e(format_money($summary['cash'])) ?></div>
        <div class="kpi__hint"><?= e(t('finance.kpi_paid_month', ['amount' => format_money($summary['paid_month'])])) ?></div>
    </a>
</div>

<div class="grid grid--2">
    <section class="card">
        <div class="card__title"><h2><?= e(t('finance.to_pay')) ?></h2></div>
        <?php if ($toPay === []): ?>
            <p class="empty"><?= e(t('finance.to_pay_none')) ?></p>
        <?php else: ?>
            <table class="table table--compact">
                <tbody>
                    <?php foreach ($toPay as $p): ?>
                        <tr>
                            <td><a href="<?= e(url('/finance/payments/' . $p['id'])) ?>"><strong><?= e($p['reference']) ?></strong></a><br><span class="muted small"><?= e($p['site']) ?></span></td>
                            <td><?= e($p['beneficiary']) ?></td>
                            <td class="num"><strong><?= e(format_money($p['amount'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('finance.unpaid_suppliers')) ?></h2></div>
        <?php if ($unpaid === []): ?>
            <p class="empty"><?= e(t('finance.unpaid_none')) ?></p>
        <?php else: ?>
            <table class="table table--compact">
                <tbody>
                    <?php foreach ($unpaid as $i): ?>
                        <tr>
                            <td><strong><?= e($i['supplier']) ?></strong><br><span class="muted small"><?= e($i['invoice_number']) ?> · <?= e($i['order_reference']) ?></span></td>
                            <td class="num<?= (int) $i['overdue_days'] > 0 && $i['due_date'] !== null ? ' overdue' : '' ?>"><?= e($i['due_date'] ? format_date($i['due_date']) : format_date($i['invoice_date'])) ?></td>
                            <td class="num"><strong><?= e(format_money($i['unpaid'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('finance.kpi_advances')) ?></h2></div>
        <?php if ($advances === []): ?>
            <p class="empty"><?= e(t('finance.advances_none')) ?></p>
        <?php else: ?>
            <table class="table table--compact">
                <tbody>
                    <?php foreach ($advances as $p): ?>
                        <tr>
                            <td><a href="<?= e(url('/finance/payments/' . $p['id'])) ?>"><strong><?= e($p['reference']) ?></strong></a><br><span class="muted small"><?= e(format_date($p['paid_at'])) ?></span></td>
                            <td><?= e($p['beneficiary']) ?></td>
                            <td class="num overdue"><strong><?= e(format_money($p['to_justify'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= e(t('finance.cash')) ?></h2></div>
        <?php if ($registers === []): ?>
            <p class="empty"><?= e(t('finance.cash_none')) ?></p>
        <?php else: ?>
            <table class="table table--compact">
                <tbody>
                    <?php foreach ($registers as $r): ?>
                        <tr>
                            <td><a href="<?= e(url('/finance/cash', ['register_id' => $r['id']])) ?>"><strong><?= e($r['name']) ?></strong></a><br><span class="muted small"><?= e($r['site']) ?></span></td>
                            <td class="muted small"><?= $r['last_closing'] ? e(t('finance.last_closing', ['date' => format_date($r['last_closing'])])) : e(t('finance.never_closed')) ?></td>
                            <td class="num"><strong><?= e(format_money($r['balance'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
