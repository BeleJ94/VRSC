<?php
/** Ravitaillements en carburant. @var array $sites  @var array $filters  @var bool $canRecord */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="fuel"></i></span>
        <div>
            <h1><?= e(t('nav.fuel')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('fuel.subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'fuel_refills', 'exportTitle' => t('nav.fuel')]) ?>
        <a class="btn" href="<?= e(url('/costs')) ?>"><i data-lucide="calculator"></i><?= e(t('nav.costs')) ?></a>
        <?php if ($canRecord): ?>
            <a class="btn btn--primary" href="<?= e(url('/fuel/create')) ?>"><i data-lucide="plus"></i><?= e(t('fuel.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="fuel_refills">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <?php if ($filters['equipment_id']): ?><input type="hidden" name="equipment_id" value="<?= e($filters['equipment_id']) ?>"><?php endif; ?>
        <div class="field">
            <label for="filter-from"><?= e(t('fields.starts_on')) ?></label>
            <input id="filter-from" type="date" name="from" class="input" value="<?= e($filters['from']) ?>" data-filter>
        </div>
        <div class="field">
            <label for="filter-to"><?= e(t('fields.ends_on')) ?></label>
            <input id="filter-to" type="date" name="to" class="input" value="<?= e($filters['to']) ?>" data-filter>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="fuel_refills" data-source="<?= e(url('/fuel/data')) ?>" data-row-link="<?= e(url('/costs/{equipment_id}')) ?>"></table>
    </div>
    <p class="hint small"><?= e(t('fuel.rule')) ?></p>
</section>
