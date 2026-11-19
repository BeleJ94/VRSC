<?php
/** Onglets du module Stocks. @var string $active */
$tabs = [
    'statement' => ['/stock', 'stock.statement'],
    'journal' => ['/stock/journal', 'stock.journal'],
    'transfers' => ['/stock/transfers', 'stock.transfers'],
    'inventories' => ['/stock/inventories', 'stock.inventories'],
    'periods' => ['/stock/periods', 'stock.periods'],
];
?>
<nav class="chips" aria-label="<?= e(t('nav.stock')) ?>">
    <?php foreach ($tabs as $key => [$path, $label]): ?>
        <a class="chip-btn" aria-pressed="<?= $key === $active ? 'true' : 'false' ?>" href="<?= e(url($path)) ?>"><?= e(t($label)) ?></a>
    <?php endforeach; ?>
</nav>
