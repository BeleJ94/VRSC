<?php
/** Liste des états de besoins (EDB). @var array $sites  @var array $filters  @var bool $canCreate */
use App\Repositories\PurchaseRepository;
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="file-pen-line"></i></span>
        <div>
            <h1><?= e(t('nav.edb')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('edb.subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'purchase_requests', 'exportTitle' => t('nav.edb')]) ?>
        <a class="btn" href="<?= e(url('/edb/reports')) ?>"><i data-lucide="bar-chart-3"></i><?= e(t('nav.edb_reports')) ?></a>
        <?php if ($canCreate): ?>
            <a class="btn btn--primary" href="<?= e(url('/edb/create')) ?>"><i data-lucide="plus"></i><?= e(t('edb.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<section class="card">
    <form class="filters" data-filters-for="purchase_requests">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-status"><?= e(t('fields.status')) ?></label>
            <select id="filter-status" name="status" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (PurchaseRepository::DISPLAY_STATUSES as $s): ?>
                    <option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(t('edb_status.' . $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-urgency"><?= e(t('fields.urgency')) ?></label>
            <select id="filter-urgency" name="urgency" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (PurchaseRepository::URGENCIES as $u): ?>
                    <option value="<?= e($u) ?>"<?= $filters['urgency'] === $u ? ' selected' : '' ?>><?= e(t('urgency.' . $u)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-category"><?= e(t('fields.category')) ?></label>
            <select id="filter-category" name="category" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (PurchaseRepository::CATEGORIES as $c): ?>
                    <option value="<?= e($c) ?>"<?= $filters['category'] === $c ? ' selected' : '' ?>><?= e(t('edb_category.' . $c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="purchase_requests" data-source="<?= e(url('/edb/data')) ?>" data-row-link="<?= e(url('/edb/{id}')) ?>"></table>
    </div>
</section>
