<?php
/**
 * Rapport mensuel des coûts par équipement, classé du plus coûteux au moins coûteux, avec validation mensuelle par site.
 * @var string $period  @var array $sites  @var array $categories  @var array $totals  @var array $filters  @var array|null $closing  @var bool $canClose
 */
$kindLabels = ['fuel' => 'cost_kind.fuel', 'parts' => 'cost_kind.parts', 'lubricants' => 'cost_kind.lubricants', 'tyres_filters' => 'cost_kind.tyres_filters',
    'labor' => 'cost_kind.labor', 'external' => 'cost_kind.external', 'other' => 'cost_kind.other'];
$variation = (float) ($totals['total'] ?? 0) - (float) ($totals['prev_total'] ?? 0);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="calculator"></i></span>
        <div>
            <h1><?= e(t('nav.costs')) ?></h1>
            <div class="page-title__meta">
                <span><?= e(t('costs.subtitle')) ?> — <?= e($period) ?></span>
                <?php if ($closing !== null): ?><span class="badge badge--neutral"><?= e(t('costs.closed_by', ['name' => $closing['closed_by_name'], 'date' => format_date($closing['closed_at'])])) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'equipment_costs', 'exportTitle' => t('nav.costs') . ' ' . $period]) ?>
        <a class="btn" href="<?= e(url('/fuel')) ?>"><i data-lucide="fuel"></i><?= e(t('nav.fuel')) ?></a>
    </div>
</div>

<?php foreach (['period', 'site_id'] as $errorField): ?>
    <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
<?php endforeach; ?>

<div class="kpis kpis--compact">
    <div class="kpi kpi--hero">
        <div class="kpi__label"><?= e(t('costs.total')) ?></div>
        <div class="kpi__value"><?= e(format_money($totals['total'] ?? 0)) ?></div>
        <div class="kpi__hint"><?= e(t('costs.vs_previous', ['amount' => ($variation >= 0 ? '+' : '') . format_money($variation)])) ?></div>
    </div>
    <div class="kpi"><div class="kpi__label"><?= e(t('costs.ytd')) ?></div><div class="kpi__value"><?= e(format_money($totals['ytd_total'] ?? 0)) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('cost_kind.fuel')) ?></div><div class="kpi__value"><?= e(format_money($totals['fuel'] ?? 0)) ?></div><div class="kpi__hint"><?= e(format_number($totals['litres'] ?? 0)) ?> L</div></div>
    <button type="button" class="kpi kpi--button<?= (int) ($totals['incomplete_count'] ?? 0) > 0 ? ' kpi--warn' : '' ?>" data-set-filter="flag" data-value="missing">
        <div class="kpi__label"><?= e(t('costs.incomplete')) ?></div>
        <div class="kpi__value"><?= e((int) ($totals['incomplete_count'] ?? 0)) ?><small>/ <?= e((int) ($totals['equipment_count'] ?? 0)) ?></small></div>
        <div class="kpi__hint"><?= e(t('costs.missing_lines', ['count' => (int) ($totals['missing_count'] ?? 0)])) ?></div>
    </button>
    <button type="button" class="kpi kpi--button<?= (int) ($totals['alert_count'] ?? 0) > 0 ? ' kpi--alert' : '' ?>" data-set-filter="flag" data-value="alert">
        <div class="kpi__label"><?= e(t('costs.alerts')) ?></div>
        <div class="kpi__value"><?= e((int) ($totals['alert_count'] ?? 0)) ?></div>
        <div class="kpi__hint"><?= e(t('costs.alerts_hint')) ?></div>
    </button>
</div>

<section class="card">
    <ul class="bars">
        <?php $max = max(0.01, ...array_map(static fn (string $k): float => (float) ($totals[$k] ?? 0), array_keys($kindLabels))); ?>
        <?php foreach ($kindLabels as $kind => $label): ?>
            <li><span class="bars__label"><?= e(t($label)) ?></span><span class="bars__track"><span style="width: <?= e(round((float) ($totals[$kind] ?? 0) / $max * 100)) ?>%"></span></span><span class="bars__value"><?= e(format_money($totals[$kind] ?? 0)) ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>

<section class="card">
    <form class="filters" data-filters-for="equipment_costs">
        <div class="field">
            <label for="filter-period"><?= e(t('fields.period')) ?></label>
            <input id="filter-period" type="month" name="period" class="input" value="<?= e($period) ?>" max="<?= e(date('Y-m')) ?>" data-filter>
        </div>
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-category"><?= e(t('fields.category_id')) ?></label>
            <select id="filter-category" name="category_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category['id']) ?>"<?= (int) $filters['category_id'] === (int) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-project"><?= e(t('fields.project_id')) ?></label>
            <select id="filter-project" name="project_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= e($project['id']) ?>"<?= (int) $filters['project_id'] === (int) $project['id'] ? ' selected' : '' ?>><?= e($project['code'] . ' – ' . $project['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-flag"><?= e(t('costs.show')) ?></label>
            <select id="filter-flag" name="flag" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <option value="missing"<?= $filters['flag'] === 'missing' ? ' selected' : '' ?>><?= e(t('costs.incomplete')) ?></option>
                <option value="alert"<?= $filters['flag'] === 'alert' ? ' selected' : '' ?>><?= e(t('costs.alerts')) ?></option>
            </select>
        </div>
        <div class="field"><a class="btn" data-apply-filters href="<?= e(url('/costs')) ?>"><i data-lucide="refresh-cw"></i><?= e(t('edb_report.refresh')) ?></a></div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="equipment_costs" data-source="<?= e(url('/costs/data')) ?>" data-row-link="<?= e(url('/costs/{id}', ['period' => $period])) ?>"></table>
    </div>
    <p class="hint small"><?= e(t('costs.rule')) ?></p>
</section>

<?php if ($canClose && $closing === null): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('costs.close_title', ['period' => $period])) ?></h2></div>
    <form method="post" action="<?= e(url('/costs/close')) ?>" class="filters">
        <?= csrf_field() ?>
        <input type="hidden" name="site_id" value="<?= e($filters['site_id']) ?>">
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'type' => 'text', 'value' => old('note'), 'attrs' => ['maxlength' => 500],
            'hint' => (int) ($totals['incomplete_count'] ?? 0) > 0 ? t('costs.close_warning', ['count' => (int) $totals['incomplete_count']]) : t('costs.close_hint')]) ?>
        <div class="field"><button type="submit" class="btn btn--primary"><i data-lucide="lock"></i><?= e(t('costs.close')) ?></button></div>
    </form>
</section>
<?php elseif ($closing === null && $filters['site_id'] === null): ?>
    <p class="hint small"><?= e(t('costs.close_pick_site')) ?></p>
<?php endif; ?>
