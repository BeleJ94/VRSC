<?php
/**
 * Onglets des compteurs : supervision, saisie des index (écran de l'encodeur), écran mural, répertoire des relevés.
 * @var string $tab meters|readings  @var int|null $siteId
 */
$siteId ??= null;
?>
<nav class="tabs" aria-label="<?= e(t('nav.meters')) ?>">
    <?php if ($currentUser->can('equipment.view')): ?>
        <a class="tabs__item" href="<?= e(url('/meters')) ?>"<?= $tab === 'meters' ? ' aria-current="page"' : '' ?>><i data-lucide="gauge"></i><?= e(t('meters.tab_supervision')) ?></a>
    <?php endif; ?>
    <?php if ($currentUser->can('meters.entry')): ?>
        <a class="tabs__item" href="<?= e(url('/saisie')) ?>"><i data-lucide="pencil"></i><?= e(t('nav.meter_entry')) ?></a>
    <?php endif; ?>
    <?php if ($currentUser->can('readings.manage')): ?>
        <a class="tabs__item" href="<?= e(url('/readings')) ?>"<?= $tab === 'readings' ? ' aria-current="page"' : '' ?>><i data-lucide="book-open"></i><?= e(t('nav.readings')) ?></a>
    <?php endif; ?>
    <?php if ($currentUser->can('equipment.view')): ?>
        <a class="tabs__item" href="<?= e(url('/meters/screen', $siteId !== null ? ['site_id' => $siteId] : [])) ?>" target="_blank" rel="noopener"><i data-lucide="monitor"></i><?= e(t('nav.meters_screen')) ?><i data-lucide="external-link" class="tabs__ext"></i></a>
    <?php endif; ?>
</nav>
