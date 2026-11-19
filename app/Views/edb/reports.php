<?php
/**
 * Les trois rapports mensuels EDB obligatoires (cahier des charges § 6.1) : émis, approuvés intégralement exécutés,
 * approuvés non intégralement exécutés. Chacun reprend les soldes d'ouverture / clôture, les nouveaux dossiers,
 * les annulations, le taux d'exécution en nombre et en valeur, et s'exporte en Excel / PDF.
 * @var string $report  @var string $period  @var array $summary  @var array $sites  @var array $projects  @var array $requesters  @var array $filters
 */
use App\Repositories\PurchaseRepository;

$table = $report === 'issued' ? 'purchase_requests' : 'purchase_execution';
$query = array_filter(['period' => $period, 'site_id' => $filters['site_id'], 'project_id' => $filters['project_id'], 'requested_by' => $filters['requested_by'],
    'category' => $filters['category']], static fn ($v) => $v !== null && $v !== '');
$rate = static fn ($value): string => $value === null ? '—' : format_number($value, 1) . ' %';
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="bar-chart-3"></i></span>
        <div>
            <h1><?= e(t('nav.edb_reports')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('edb_report.' . $report)) ?> — <?= e($period) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => $table, 'exportTitle' => t('edb_report.' . $report) . ' ' . $period]) ?>
    </div>
</div>

<nav class="chips" aria-label="<?= e(t('nav.edb_reports')) ?>">
    <?php foreach (PurchaseRepository::REPORTS as $i => $key): ?>
        <a class="chip-btn" aria-pressed="<?= $key === $report ? 'true' : 'false' ?>" href="<?= e(url('/edb/reports', $query + ['report' => $key])) ?>"><?= e(($i + 1) . '. ' . t('edb_report.' . $key)) ?></a>
    <?php endforeach; ?>
</nav>

<div class="kpis kpis--compact">
    <div class="kpi"><div class="kpi__label"><?= e(t('edb_report.opening')) ?></div><div class="kpi__value"><?= e((int) $summary['opening_count']) ?></div><div class="kpi__hint"><?= e(format_money($summary['opening_value'])) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('edb_report.new')) ?></div><div class="kpi__value"><?= e((int) $summary['new_count']) ?></div><div class="kpi__hint"><?= e(format_money($summary['new_value'])) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('edb_report.executed_kpi')) ?></div><div class="kpi__value"><?= e((int) $summary['executed_count']) ?></div><div class="kpi__hint"><?= e(format_money($summary['executed_value'])) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('edb_report.cancelled')) ?></div><div class="kpi__value"><?= e((int) $summary['cancelled_count']) ?></div><div class="kpi__hint"><?= e(format_money($summary['cancelled_value'])) ?></div></div>
    <div class="kpi<?= (int) $summary['closing_count'] > 0 ? ' kpi--warn' : '' ?>"><div class="kpi__label"><?= e(t('edb_report.closing')) ?></div><div class="kpi__value"><?= e((int) $summary['closing_count']) ?></div><div class="kpi__hint"><?= e(format_money($summary['closing_value'])) ?> · <?= e(t('edb_report.partial', ['count' => (int) $summary['partial_count']])) ?></div></div>
    <div class="kpi kpi--hero"><div class="kpi__label"><?= e(t('edb_report.rate')) ?></div><div class="kpi__value"><?= e($rate($summary['rate_count'])) ?></div><div class="kpi__hint"><?= e(t('edb_report.rate_value', ['rate' => $rate($summary['rate_value'])])) ?></div></div>
</div>

<?php if ($report === 'issued'): ?>
<div class="grid grid--3">
    <?php foreach (['site' => 'fields.site_id', 'category' => 'fields.category', 'cost_center' => 'fields.cost_center_id'] as $key => $label): ?>
        <section class="card">
            <div class="card__title"><h2><?= e(t($label)) ?></h2></div>
            <?php if ($summary['breakdown'][$key] === []): ?>
                <p class="empty"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <table class="table table--compact">
                    <tbody>
                        <?php foreach ($summary['breakdown'][$key] as $row): ?>
                            <tr><td><?= e($key === 'category' ? t('edb_category.' . $row['label']) : $row['label']) ?></td><td class="num"><?= e($row['request_count']) ?></td><td class="num"><?= e(format_money($row['amount'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card">
    <form class="filters" data-filters-for="<?= e($table) ?>">
        <input type="hidden" name="report" value="<?= e($report) ?>">
        <div class="field">
            <label for="filter-period"><?= e(t('fields.period')) ?></label>
            <input id="filter-period" type="month" name="period" class="input" value="<?= e($period) ?>" max="<?= e(date('Y-m')) ?>" data-filter data-reload>
        </div>
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <div class="field">
            <label for="filter-project"><?= e(t('fields.project_id')) ?></label>
            <select id="filter-project" name="project_id" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($projects as $id => $label): ?><option value="<?= e($id) ?>"<?= (int) $filters['project_id'] === (int) $id ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-requester"><?= e(t('approvals.requester')) ?></label>
            <select id="filter-requester" name="requested_by" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($requesters as $user): ?><option value="<?= e($user['id']) ?>"<?= (int) $filters['requested_by'] === (int) $user['id'] ? ' selected' : '' ?>><?= e($user['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="filter-category"><?= e(t('fields.category')) ?></label>
            <select id="filter-category" name="category" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (PurchaseRepository::CATEGORIES as $c): ?><option value="<?= e($c) ?>"<?= $filters['category'] === $c ? ' selected' : '' ?>><?= e(t('edb_category.' . $c)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php if ($report !== 'executed'): ?>
        <div class="field">
            <label for="filter-status"><?= e(t('fields.status')) ?></label>
            <select id="filter-status" name="status" class="input" data-filter>
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (PurchaseRepository::DISPLAY_STATUSES as $s): ?><option value="<?= e($s) ?>"><?= e(t('edb_status.' . $s)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="field"><a class="btn" data-apply-filters href="<?= e(url('/edb/reports', ['report' => $report])) ?>"><i data-lucide="refresh-cw"></i><?= e(t('edb_report.refresh')) ?></a></div>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="<?= e($table) ?>" data-source="<?= e(url('/edb/data')) ?>" data-row-link="<?= e(url('/edb/{id}')) ?>"></table>
    </div>
    <p class="hint small"><?= e(t('edb_report.rule')) ?></p>
</section>
