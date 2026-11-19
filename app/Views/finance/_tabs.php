<?php
/** Onglets du module Finance. @var string $active */
$tabs = [
    'index' => ['/finance', 'finance.overview'],
    'payments' => ['/finance/payments', 'finance.payments'],
    'invoices' => ['/finance/invoices', 'finance.invoices'],
    'cash' => ['/finance/cash', 'finance.cash'],
    'reconciliation' => ['/finance/reconciliation', 'finance.reconciliation'],
    'reports' => ['/finance/reports', 'finance.reports'],
];
?>
<nav class="chips" aria-label="<?= e(t('nav.finance')) ?>">
    <?php foreach ($tabs as $key => [$path, $label]): ?>
        <a class="chip-btn" aria-pressed="<?= $key === $active ? 'true' : 'false' ?>" href="<?= e(url($path)) ?>"><?= e(t($label)) ?></a>
    <?php endforeach; ?>
</nav>
