<?php
/**
 * Fiche de magasin (par site) et état consolidé (tous sites) sur une période mensuelle.
 * @var array $sites  @var array $summary  @var array $filters  @var bool $closed
 */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="warehouse"></i></span>
        <div>
            <h1><?= e(t('nav.stock')) ?></h1>
            <div class="page-title__meta">
                <span><?= e(t('stock.subtitle')) ?></span>
                <?php if ($closed): ?><span class="badge badge--neutral"><?= e(t('stock.period_closed')) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'stock_statement', 'exportTitle' => t('stock.statement') . ' ' . $filters['period']]) ?>
        <?php if ($currentUser->can('stock.transfer')): ?>
            <a class="btn btn--primary" href="<?= e(url('/stock/transfers/create')) ?>"><i data-lucide="arrow-left-right"></i><?= e(t('stock.transfer_new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<?= $view->partial('stock/_tabs', ['active' => 'statement']) ?>

<div class="kpis kpis--compact">
    <div class="kpi kpi--hero">
        <div class="kpi__label"><?= e(t('stock.kpi_value')) ?></div>
        <div class="kpi__value"><?= e(format_money($summary['value_close'] ?? 0)) ?></div>
        <div class="kpi__hint"><?= (int) ($summary['value_missing'] ?? 0) > 0 ? e(t('stock.kpi_value_missing', ['count' => (int) $summary['value_missing']])) : e(t('stock.kpi_value_hint')) ?></div>
    </div>
    <button type="button" class="kpi kpi--button<?= (int) ($summary['out_count'] ?? 0) > 0 ? ' kpi--alert' : '' ?>" data-set-filter="level" data-value="out">
        <div class="kpi__label"><?= e(t('stock_level.out')) ?></div>
        <div class="kpi__value"><?= e((int) ($summary['out_count'] ?? 0)) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= (int) ($summary['low_count'] ?? 0) > 0 ? ' kpi--warn' : '' ?>" data-set-filter="level" data-value="low">
        <div class="kpi__label"><?= e(t('stock_level.low')) ?></div>
        <div class="kpi__value"><?= e((int) ($summary['low_count'] ?? 0)) ?></div>
    </button>
    <a class="kpi" href="<?= e(url('/stock/transfers', ['status' => 'shipped'])) ?>">
        <div class="kpi__label"><?= e(t('stock.kpi_transit')) ?></div>
        <div class="kpi__value"><?= e(format_qty($summary['in_transit'] ?? 0)) ?></div>
        <div class="kpi__hint"><?= e(t('stock.kpi_transit_hint')) ?></div>
    </a>
</div>

<section class="card">
    <form class="filters" data-filters-for="stock_statement">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-period"><?= e(t('fields.period')) ?></label>
            <input id="filter-period" type="month" name="period" class="input" value="<?= e($filters['period']) ?>" max="<?= e(date('Y-m')) ?>" data-filter>
        </div>
        <div class="field">
            <label for="filter-view"><?= e(t('stock.view')) ?></label>
            <select id="filter-view" name="consolidated" class="input" data-filter>
                <option value=""><?= e(t('stock.view_by_site')) ?></option>
                <option value="1"<?= $filters['consolidated'] ? ' selected' : '' ?>><?= e(t('stock.view_consolidated')) ?></option>
            </select>
        </div>
        <div class="field">
            <label for="filter-level"><?= e(t('stock.level')) ?></label>
            <select id="filter-level" name="level" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (\App\Repositories\StockLedgerRepository::LEVELS as $level): ?>
                    <option value="<?= e($level) ?>"<?= $filters['level'] === $level ? ' selected' : '' ?>><?= e(t('stock_level.' . $level)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="stock_statement" data-source="<?= e(url('/stock/data')) ?>" data-row-link="<?= e(url('/parts/{id}')) ?>"></table>
    </div>
    <p class="hint small"><?= e(t('stock.rule')) ?></p>
</section>
