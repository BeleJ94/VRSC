<?php
/**
 * En-tête commun des tableaux de bord : titre, onglets des vues accessibles, période, site, bandeau « À traiter ».
 * @var string $dashView  @var list<string> $views  @var array|null $period  @var array $sites  @var int|null $siteId  @var array $todo
 */
$viewIcons = ['executive' => 'presentation', 'finance' => 'landmark', 'cash' => 'wallet', 'purchasing' => 'shopping-bag', 'stores' => 'warehouse', 'maintenance' => 'wrench', 'requests' => 'inbox'];
$viewLabels = ['executive' => 'dashboard.view_executive', 'finance' => 'dashboard.view_finance', 'purchasing' => 'dashboard.view_purchasing', 'stores' => 'dashboard.view_stores', 'maintenance' => 'dashboard.view_maintenance', 'cash' => 'dashboard.view_cash', 'requests' => 'dashboard.view_requests'];
$periodLabels = ['month' => 'dashboard.period_month', 'last_month' => 'dashboard.period_last_month', 'quarter' => 'dashboard.period_quarter', 'year' => 'dashboard.period_year'];
$sq = ($siteId ?? null) !== null ? ['site_id' => $siteId] : [];
$todoDrills = [
    'payments' => drill_spec('drill_payments', '/dashboard/drill/payments', t('dashboard.todo_payments_title'), ['status' => 'approved'] + $sq, '/finance/payments', '{!link}', [], false),
    'edb' => drill_spec('purchase_requests', '/edb/data', t('dashboard.todo_edb_title'), ['status' => 'approved'] + $sq, '/edb', '/edb/{id}'),
    'documents' => drill_spec('documents', '/documents/data', t('dashboard.alert_documents_title'), ['state' => 'expired'] + $sq, '/documents', '/equipment/{equipment_id}'),
];
$todoLabels = [
    'approvals' => 'dashboard.todo_approvals', 'payments' => 'dashboard.todo_payments', 'edb' => 'dashboard.todo_edb', 'stock_transfers' => 'dashboard.todo_stock_transfers',
    'fleet_transfers' => 'dashboard.todo_fleet_transfers', 'closings' => 'dashboard.todo_closings', 'cash_days' => 'dashboard.todo_cash_days', 'documents' => 'dashboard.todo_documents',
];
?>
<div class="xdash-head" data-auto-refresh="300">
    <div class="xdash-head__title">
        <h1><?= e(t('nav.dashboard')) ?></h1>
        <span class="muted small"><?= e(t('dashboard.updated_at', ['time' => date('H:i')])) ?><?= $period !== null ? ' · ' . e(format_date($period['from'])) . ' → ' . e(format_date($period['to'])) : '' ?></span>
    </div>
    <?php if (count($views) > 1): ?>
        <nav class="xdash-tabs" aria-label="<?= e(t('dashboard.views')) ?>">
            <?php foreach ($views as $v): ?>
                <a href="<?= e(url('/', ['view' => $v] + ($period !== null ? ['period' => $period['key']] : []))) ?>"<?= $v === $dashView ? ' aria-current="page"' : '' ?>><i data-lucide="<?= e($viewIcons[$v]) ?>"></i><?= e(t($viewLabels[$v])) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <form method="get" action="<?= e(url('/')) ?>" class="xdash-filters">
        <input type="hidden" name="view" value="<?= e($dashView) ?>">
        <?php if ($period !== null): ?>
            <label class="sr-only" for="dash-period"><?= e(t('fields.period')) ?></label>
            <select id="dash-period" name="period" class="input input--sm" data-autosubmit>
                <?php foreach ($periodLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $period['key'] === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <?php if (count($sites) > 1): ?>
            <label class="sr-only" for="dash-site"><?= e(t('fields.site_id')) ?></label>
            <select id="dash-site" name="site_id" class="input input--sm" data-autosubmit>
                <option value=""><?= e(t('common.all_sites')) ?></option>
                <?php foreach ($sites as $site): ?>
                    <option value="<?= e($site['id']) ?>"<?= (int) $site['id'] === (int) ($siteId ?? 0) ? ' selected' : '' ?>><?= e($site['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </form>
</div>

<?php if ($todo !== []): ?>
    <section class="xdash-todo" aria-label="<?= e(t('dashboard.todo')) ?>">
        <span class="xdash-todo__title"><i data-lucide="list-checks"></i><?= e(t('dashboard.todo')) ?></span>
        <?php foreach ($todo as $item): ?>
            <a class="xdash-todo__item xdash-todo__item--<?= e($item['level']) ?>" href="<?= e(url($item['url'])) ?>"<?= isset($todoDrills[$item['key']]) ? ' data-drill="' . e(json_encode($todoDrills[$item['key']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"' : '' ?>>
                <i data-lucide="<?= e($item['icon']) ?>"></i><strong><?= e($item['count']) ?></strong><span><?= e(t($todoLabels[$item['key']])) ?></span>
            </a>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="xdash-todo xdash-todo--clear"><i data-lucide="check-circle-2"></i><?= e(t('dashboard.todo_none')) ?></section>
<?php endif; ?>
