<?php
/** Journal des mouvements : toute écriture datée, justifiée, imputée. @var array $sites  @var array $filters  @var list<string> $types */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="scroll-text"></i></span>
        <div>
            <h1><?= e(t('stock.journal')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('stock.journal_subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'stock_journal', 'exportTitle' => t('stock.journal')]) ?>
    </div>
</div>
<?= $view->partial('stock/_tabs', ['active' => 'journal']) ?>
<section class="card">
    <form class="filters" data-filters-for="stock_journal">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-type"><?= e(t('fields.type')) ?></label>
            <select id="filter-type" name="type" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= e($type) ?>"<?= $filters['type'] === $type ? ' selected' : '' ?>><?= e(t('movement.' . $type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
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
        <table class="display" style="width:100%" data-table="stock_journal" data-source="<?= e(url('/stock/journal/data')) ?>" data-row-link="<?= e(url('/parts/{part_id}')) ?>"></table>
    </div>
</section>
