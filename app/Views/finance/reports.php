<?php
/**
 * État mensuel des dépenses : demandé, approuvé, engagé, payé, restant, avances à justifier,
 * par site, projet, centre de coûts ou catégorie (maintenance, carburant…).
 * @var string $period  @var string $group  @var array $totals  @var array $sites  @var int|null $siteId
 */
use App\Repositories\FinanceRepository;

$columnLabels = ['requested' => 'finance.col_requested', 'approved' => 'finance.col_approved', 'engaged' => 'finance.col_engaged',
    'paid' => 'finance.col_paid', 'remaining' => 'finance.col_remaining', 'advances' => 'finance.col_advances'];
$groupLabels = ['site' => 'finance.group_site', 'project' => 'finance.group_project', 'cost_center' => 'finance.group_cost_center', 'category' => 'finance.group_category'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="pie-chart"></i></span>
        <div>
            <h1><?= e(t('finance.reports')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('finance.reports_subtitle')) ?> — <?= e($period) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'finance_summary', 'exportTitle' => t('finance.reports') . ' ' . $period]) ?>
    </div>
</div>
<?= $view->partial('finance/_tabs', ['active' => 'reports']) ?>

<div class="kpis kpis--compact">
    <?php foreach (['requested', 'approved', 'engaged', 'paid', 'remaining', 'advances'] as $key): ?>
        <div class="kpi<?= $key === 'paid' ? ' kpi--hero' : '' ?>"><div class="kpi__label"><?= e(t($columnLabels[$key])) ?></div><div class="kpi__value"><?= e(format_money($totals[$key] ?? 0)) ?></div></div>
    <?php endforeach; ?>
</div>

<section class="card">
    <form class="filters" data-filters-for="finance_summary">
        <div class="field">
            <label for="filter-period"><?= e(t('fields.period')) ?></label>
            <input id="filter-period" type="month" name="period" class="input" value="<?= e($period) ?>" max="<?= e(date('Y-m')) ?>" data-filter>
        </div>
        <div class="field">
            <label for="filter-group"><?= e(t('finance.group_by')) ?></label>
            <select id="filter-group" name="group" class="input" data-filter>
                <?php foreach (FinanceRepository::GROUPS as $g): ?>
                    <option value="<?= e($g) ?>"<?= $g === $group ? ' selected' : '' ?>><?= e(t($groupLabels[$g])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $siteId]) ?>
        <div class="field"><a class="btn" data-apply-filters href="<?= e(url('/finance/reports')) ?>"><i data-lucide="refresh-cw"></i><?= e(t('edb_report.refresh')) ?></a></div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="finance_summary" data-source="<?= e(url('/finance/reports/data')) ?>"></table>
    </div>
    <p class="hint small"><?= e(t('finance.reports_rule')) ?></p>
</section>
