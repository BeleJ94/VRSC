<?php
/**
 * Demande de décaissement : règlement d'une facture, avance à justifier sur un EDB approuvé, ou dépense directe.
 * @var string $kind  @var array|null $invoice  @var array $invoices  @var array $requests  @var int|null $requestId
 * @var array $sites  @var array $departments  @var array $costCenters  @var array $projects
 */
use App\Repositories\FinanceRepository;

$kinds = array_combine(FinanceRepository::KINDS, array_map(static fn (string $k) => t('payment_kind.' . $k), FinanceRepository::KINDS));
$f = static fn (string $name, string $type, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $extra['default'] ?? null),
]);
$invoiceOptions = ['' => '—'];
foreach ($invoices as $i) {
    $invoiceOptions[$i['id']] = $i['invoice_number'] . ' – ' . $i['supplier'] . ' (' . t('finance.payable', ['amount' => format_money($i['payable'])]) . ')';
}
$requestOptions = ['' => '—'];
foreach ($requests as $r) {
    $requestOptions[$r['id']] = $r['reference'] . ' – ' . $r['subject'];
}
$projectOptions = ['' => '—'];
foreach ($projects as $options) {
    $projectOptions += $options;
}
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<nav class="chips" aria-label="<?= e(t('fields.kind')) ?>">
    <?php foreach ($kinds as $key => $label): ?>
        <a class="chip-btn" aria-pressed="<?= $key === $kind ? 'true' : 'false' ?>" href="<?= e(url('/finance/payments/create', ['kind' => $key])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<form method="post" action="<?= e(url('/finance/payments')) ?>" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <p class="muted"><?= e(t('payment_kind_help.' . $kind)) ?></p>
    <div class="form-grid">
        <?php if ($kind === 'invoice'): ?>
            <?= $f('invoice_id', 'select', ['required' => true, 'default' => $invoice['id'] ?? null, 'options' => $invoiceOptions, 'class' => 'span-2']) ?>
            <?= $f('amount', 'number', ['required' => true, 'default' => $invoice !== null ? (float) $invoice['payable'] : null, 'attrs' => ['min' => '0.01', 'step' => '0.01']]) ?>
        <?php elseif ($kind === 'advance'): ?>
            <?= $f('request_id', 'select', ['required' => true, 'default' => $requestId, 'options' => $requestOptions, 'class' => 'span-2']) ?>
            <?= $f('beneficiary', 'text', ['required' => true, 'attrs' => ['maxlength' => 170]]) ?>
            <?= $f('amount', 'number', ['required' => true, 'attrs' => ['min' => '0.01', 'step' => '0.01']]) ?>
        <?php else: ?>
            <?= $f('site_id', 'select', ['required' => true, 'default' => count($sites) === 1 ? $sites[0]['id'] : null, 'options' => (count($sites) === 1 ? [] : ['' => '—']) + array_column($sites, 'name', 'id')]) ?>
            <?= $f('beneficiary', 'text', ['required' => true, 'attrs' => ['maxlength' => 170]]) ?>
            <?= $f('amount', 'number', ['required' => true, 'attrs' => ['min' => '0.01', 'step' => '0.01']]) ?>
            <?= $f('category', 'select', ['default' => 'other', 'options' => array_combine(FinanceRepository::CATEGORIES, array_map(static fn (string $c) => t('finance_category.' . $c), FinanceRepository::CATEGORIES))]) ?>
            <?= $f('project_id', 'select', ['options' => $projectOptions]) ?>
            <?= $f('cost_center_id', 'select', ['options' => ['' => '—'] + $costCenters]) ?>
            <?= $f('department_id', 'select', ['options' => ['' => '—'] + $departments]) ?>
        <?php endif; ?>
        <?= $f('reason', 'textarea', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 500, 'rows' => 2]]) ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/finance/payments')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="send"></i><?= e(t('finance.payment_submit')) ?></button>
    </div>
</form>
