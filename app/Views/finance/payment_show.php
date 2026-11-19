<?php
/**
 * Demande de décaissement : rattachement, circuit (vérification, approbation), paiement avec preuve, justification d'avance.
 * @var array $payment  @var array|null $approval  @var array $attachments  @var array $invoiceFiles  @var array $methods  @var array $registers
 * @var bool $canPay  @var bool $payBlocked  @var bool $canJustify  @var bool $canCancel
 */
$p = $payment;
$badge = ['pending' => 'waiting_part', 'approved' => 'maintenance', 'paid' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
$registerOptions = ['' => '—'];
foreach ($registers as $r) {
    $registerOptions[$r['id']] = $r['name'] . ' (' . format_money($r['balance']) . ')';
}
$methodOptions = ['' => '—'] + array_column($methods, 'name', 'id');
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="banknote"></i></span>
        <div>
            <h1><?= e(t('finance.payment')) ?> <?= e($p['reference']) ?></h1>
            <div class="page-title__meta">
                <span><?= e(t('payment_kind.' . $p['kind'])) ?> · <?= e($p['site']) ?></span>
                <span class="badge badge--<?= e($badge[$p['status']]) ?>"><?= e(t('payment_status.' . $p['status'])) ?></span>
                <?php if ((float) $p['to_justify'] > 0): ?><span class="badge badge--waiting_part"><?= e(t('finance.to_justify', ['amount' => format_money($p['to_justify'])])) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/finance/payments')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button>
    </div>
</div>

<?php foreach (['status', 'attachment', 'payment_reference', 'cash_register_id', 'amount', 'justified_amount', 'cancel_reason'] as $errorField): ?>
    <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
<?php endforeach; ?>

<div class="kpis kpis--compact">
    <div class="kpi kpi--hero"><div class="kpi__label"><?= e(t('fields.amount')) ?></div><div class="kpi__value"><?= e(format_money($p['amount'])) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('fields.beneficiary')) ?></div><div class="kpi__value" style="font-size:1.1rem"><?= e($p['beneficiary']) ?></div></div>
    <?php if ($p['kind'] === 'advance' && $p['status'] === 'paid'): ?>
        <div class="kpi"><div class="kpi__label"><?= e(t('finance.justified')) ?></div><div class="kpi__value"><?= e(format_money($p['justified_amount'])) ?></div><div class="kpi__hint"><?= e(t('finance.returned', ['amount' => format_money($p['returned_amount'])])) ?></div></div>
    <?php endif; ?>
</div>

<section class="card">
    <div class="grid grid--3">
        <div><span class="muted small"><?= e(t('fields.reason')) ?></span><br><?= e($p['reason']) ?></div>
        <div><span class="muted small"><?= e(t('approvals.requester')) ?></span><br><?= e($p['requester']) ?> · <?= e(format_date($p['requested_at'], true)) ?></div>
        <div><span class="muted small"><?= e(t('finance.allocation')) ?></span><br><?= e(implode(' · ', array_filter([$p['site'], $p['project'], $p['cost_center'], $p['department']]))) ?><br><span class="muted small"><?= e(t('finance_category.' . $p['category'])) ?></span></div>
        <div><span class="muted small"><?= e(t('finance.linked_to')) ?></span><br>
            <?php if ($p['request_id']): ?><a href="<?= e(url('/edb/' . $p['request_id'])) ?>"><?= e($p['request_reference']) ?></a><?php endif; ?>
            <?php if ($p['order_id']): ?> · <a href="<?= e(url('/purchases/' . $p['order_id'])) ?>"><?= e($p['order_reference']) ?></a><?php endif; ?>
            <?php if ($p['invoice_number']): ?> · <?= e(t('finance.invoice')) ?> <?= e($p['invoice_number']) ?><?php endif; ?>
            <?php if (!$p['request_id'] && !$p['order_id']): ?><?= e(t('finance.direct_expense')) ?><?php endif; ?>
            <?php foreach ($invoiceFiles as $file): ?>
                <a class="icon-btn" href="<?= e(url('/attachments/' . $file['id'])) ?>" target="_blank" rel="noopener" title="<?= e($file['original_name']) ?>" aria-label="<?= e($file['original_name']) ?>"><i data-lucide="paperclip"></i></a>
            <?php endforeach; ?>
        </div>
        <?php if ($p['status'] === 'paid'): ?>
            <div><span class="muted small"><?= e(t('finance.paid_by')) ?></span><br><?= e($p['payer']) ?> · <?= e(format_date($p['paid_at'], true)) ?></div>
            <div><span class="muted small"><?= e(t('fields.payment_method_id')) ?></span><br><?= e($p['payment_method']) ?><?= $p['cash_register'] ? ' · ' . e($p['cash_register']) : '' ?><br><span class="mono small"><?= e($p['payment_reference']) ?></span></div>
        <?php endif; ?>
        <?php if ($p['cancel_reason']): ?><div><span class="muted small"><?= e(t('stock.cancel_reason')) ?></span><br><?= e($p['cancel_reason']) ?></div><?php endif; ?>
    </div>
    <?php if ($attachments !== []): ?>
        <p><span class="muted small"><?= e(t('finance.proofs')) ?></span>
            <?php foreach ($attachments as $file): ?>
                <a class="tag" href="<?= e(url('/attachments/' . $file['id'])) ?>" target="_blank" rel="noopener"><i data-lucide="paperclip"></i><?= e(t('attachment_category.' . $file['category'])) ?> — <?= e($file['original_name']) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
    <?php if ($p['status'] === 'paid' && $p['request_id']): ?><p class="hint small"><?= e(t('finance.payment_rule')) ?></p><?php endif; ?>
</section>

<?= $view->partial('partials/approval', ['approval' => $approval]) ?>

<?php if ($canPay): ?>
<section class="card" id="pay">
    <div class="card__title"><h2><?= e(t('finance.pay')) ?></h2></div>
    <form method="post" action="<?= e(url('/finance/payments/' . $p['id'] . '/pay')) ?>" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= $view->partial('partials/field', ['name' => 'payment_method_id', 'labelKey' => 'fields.payment_method_id', 'type' => 'select', 'required' => true, 'value' => old('payment_method_id'), 'options' => $methodOptions]) ?>
            <?= $view->partial('partials/field', ['name' => 'cash_register_id', 'labelKey' => 'fields.cash_register_id', 'type' => 'select', 'value' => old('cash_register_id'), 'options' => $registerOptions, 'hint' => t('finance.cash_hint')]) ?>
            <?= $view->partial('partials/field', ['name' => 'payment_reference', 'labelKey' => 'fields.payment_reference', 'type' => 'text', 'value' => old('payment_reference'), 'attrs' => ['maxlength' => 80], 'hint' => t('finance.reference_hint')]) ?>
            <div class="field">
                <label for="f-proof" class="required"><?= e(t('finance.proof')) ?></label>
                <input id="f-proof" type="file" name="proof" class="input" accept="application/pdf,image/jpeg,image/png,image/webp" required>
            </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="banknote"></i><?= e(t('finance.confirm_payment', ['amount' => format_money($p['amount'])])) ?></button></div>
    </form>
</section>
<?php elseif ($payBlocked): ?>
    <section class="card"><p class="muted"><?= e(t('validation.payment_same_actor')) ?></p></section>
<?php endif; ?>

<?php if ($canJustify): ?>
<section class="card" id="justify">
    <div class="card__title"><h2><?= e(t('finance.justify')) ?></h2><span class="muted small"><?= e(t('finance.to_justify', ['amount' => format_money($p['to_justify'])])) ?></span></div>
    <form method="post" action="<?= e(url('/finance/payments/' . $p['id'] . '/justify')) ?>" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= $view->partial('partials/field', ['name' => 'justified_amount', 'labelKey' => 'fields.justified_amount', 'type' => 'number', 'value' => old('justified_amount'), 'attrs' => ['min' => '0', 'step' => '0.01']]) ?>
            <div class="field">
                <label for="f-justify-proof"><?= e(t('finance.advance_proof')) ?></label>
                <input id="f-justify-proof" type="file" name="proof" class="input" accept="application/pdf,image/jpeg,image/png,image/webp">
            </div>
            <?= $view->partial('partials/field', ['name' => 'returned_amount', 'labelKey' => 'fields.returned_amount', 'type' => 'number', 'value' => old('returned_amount'), 'attrs' => ['min' => '0', 'step' => '0.01']]) ?>
            <?= $view->partial('partials/field', ['name' => 'cash_register_id', 'labelKey' => 'fields.cash_register_id', 'type' => 'select', 'value' => old('cash_register_id'), 'options' => $registerOptions, 'hint' => t('finance.returned_hint')]) ?>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="check"></i><?= e(t('finance.record_justification')) ?></button></div>
    </form>
</section>
<?php endif; ?>

<?php if ($canCancel): ?>
    <section class="card">
        <form method="post" action="<?= e(url('/finance/payments/' . $p['id'] . '/cancel')) ?>" class="filters">
            <?= csrf_field() ?>
            <div class="field">
                <label for="cancel-reason"><?= e(t('stock.cancel_reason')) ?></label>
                <input id="cancel-reason" name="cancel_reason" class="input" maxlength="500" required>
            </div>
            <div class="field"><button type="submit" class="btn"><i data-lucide="ban"></i><?= e(t('finance.cancel_payment')) ?></button></div>
        </form>
    </section>
<?php endif; ?>
