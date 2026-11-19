<?php
/** @var \App\Security\CurrentUser $currentUser  @var string $content  @var string $currentPath  @var array $navSites  @var int|null $siteContext  @var array<string, int> $navCounts */
$siteIcons = ['office' => 'building-2', 'earthmoving' => 'mountain', 'transport' => 'bus'];
$siteContext ??= null;
$contextSite = null;
foreach ($navSites ?? [] as $navSite) {
    if ((int) $navSite['id'] === $siteContext) {
        $contextSite = $navSite;
    }
}
// Menu organisé selon le flux du cahier des charges : mon travail → besoins & achats → stocks → flotte → maintenance
// → finance → rapports. Lien : [chemin, icône, libellé, permission] + options
//   badge (clés d'alarmes, remplies par js), count (compteur « à traiter » calculé côté serveur), sub (sous-lien), blank (nouvel onglet).
$navCounts ??= [];
$sections = [
    'work' => ['nav.section_work', [
        [$contextSite ? '/sites/' . $contextSite['id'] : '/', 'layout-dashboard', $contextSite ? 'nav.site_home' : 'nav.dashboard', 'dashboard.view'],
        ['/approvals', 'stamp', 'nav.approvals', 'approvals.use', 'count' => 'approvals'],
        ['/edb?mine=1', 'inbox', 'nav.my_requests', 'edb.create'],
    ]],
    'purchasing' => ['nav.section_purchasing', [
        ['/edb', 'file-pen-line', 'nav.edb', 'edb.view', 'count' => 'edb'],
        ['/purchases', 'receipt-text', 'nav.purchases', 'edb.view'],
        ['/references/suppliers', 'handshake', 'nav.suppliers', 'reference.view'],
    ]],
    'stores' => ['nav.section_stores', [
        ['/stock', 'warehouse', 'nav.stock', 'stock.view'],
        ['/parts', 'package', 'nav.parts', 'parts.view'],
        ['/stock/transfers', 'arrow-left-right', 'nav.stock_transfers', 'stock.view', 'count' => 'stock_transfers'],
        ['/stock/inventories', 'clipboard-check', 'nav.stock_inventories', 'stock.view'],
        ['/procurement', 'shopping-cart', 'nav.procurement', 'parts.view', 'badge' => 'stock_out stock_low'],
    ]],
    'fleet' => ['nav.section_fleet', [
        ['/equipment', 'truck', 'nav.equipment', 'equipment.view', 'badge' => 'down'],
        ['/fleet-transfers', 'route', 'nav.fleet_transfers', 'fleet.transfer.view', 'count' => 'fleet_transfers'],
        ['/documents', 'file-badge', 'nav.documents', 'equipment.view', 'badge' => 'documents_expired documents_soon'],
        ['/fuel', 'fuel', 'nav.fuel', 'fuel.view'],
        ['/teams', 'users-round', 'nav.teams', 'personnel.view'],
    ]],
    'maintenance' => ['nav.section_maintenance', [
        ['/work-orders', 'clipboard-list', 'nav.work_orders', 'workorder.view', 'badge' => 'overdue'],
        ['/preventive', 'calendar-clock', 'nav.preventive', 'preventive.view', 'badge' => 'preventive'],
        ['/planning', 'calendar-range', 'nav.planning', 'workorder.view'],
        // Un seul lien : supervision, saisie, répertoire et écran mural sont des onglets de la page.
        ['/meters', 'gauge', 'nav.meters', 'equipment.view', 'badge' => 'meter_soon meter_stale', 'also' => ['/readings']],
        // L'encodeur, qui ne voit pas la supervision, garde son accès direct à la saisie.
        ['/saisie', 'pencil', 'nav.meter_entry', 'meters.entry', 'unless' => 'equipment.view'],
    ]],
    'finance' => ['nav.section_finance', [
        ['/finance', 'landmark', 'nav.finance', 'finance.view'],
        ['/finance/payments', 'banknote', 'nav.finance_payments', 'finance.view', 'count' => 'payments'],
        ['/finance/invoices', 'file-text', 'nav.finance_invoices', 'finance.view'],
        ['/finance/cash', 'wallet', 'nav.finance_cash', 'finance.view'],
    ]],
    'reports' => ['nav.section_reports', [
        ['/reports', 'presentation', 'nav.reports', 'reports.view'],
        ['/edb/reports', 'bar-chart-3', 'nav.edb_reports', 'edb.view'],
        ['/costs', 'calculator', 'nav.costs', 'costs.view'],
        ['/finance/reports', 'pie-chart', 'nav.finance_reports', 'finance.view'],
        ['/closings', 'lock', 'nav.closings', 'closings.view'],
        ['/alerts', 'siren', 'nav.alerts', 'costs.view', 'badge' => 'wear overuse'],
    ]],
];
// Ordre adapté au métier : chacun trouve d'abord ce qu'il traite tous les jours.
$orders = [
    'technician' => ['work', 'maintenance', 'fleet', 'stores', 'purchasing', 'reports', 'finance'],
    'manager' => ['work', 'maintenance', 'fleet', 'purchasing', 'stores', 'reports', 'finance'],
    'encoder' => ['work', 'maintenance', 'fleet', 'stores', 'purchasing', 'reports', 'finance'],
    'storekeeper' => ['work', 'stores', 'purchasing', 'fleet', 'maintenance', 'reports', 'finance'],
    'buyer' => ['work', 'purchasing', 'stores', 'finance', 'reports', 'fleet', 'maintenance'],
    'cashier' => ['work', 'finance', 'purchasing', 'reports', 'stores', 'fleet', 'maintenance'],
    'finance' => ['work', 'finance', 'reports', 'purchasing', 'stores', 'fleet', 'maintenance'],
    'director' => ['work', 'reports', 'finance', 'purchasing', 'fleet', 'stores', 'maintenance'],
];
$order = $orders[$currentUser->role->value] ?? ['work', 'purchasing', 'stores', 'fleet', 'maintenance', 'finance', 'reports'];
$settings = [
    ['/sites', 'building-2', 'nav.sites_admin', 'sites.manage'],
    ['/users', 'users', 'nav.users', 'users.manage'],
    ['/references', 'library', 'nav.references', 'reference.view'],
    ['/catalog', 'tags', 'nav.catalog', 'equipment.manage'],
    ['/delegations', 'user-check', 'nav.delegations', 'delegations.manage'],
    ['/settings/mail', 'mail', 'nav.mail_settings', 'settings.manage'],
    ['/activity', 'history', 'nav.activity', 'activity.view'],
    ['/backups', 'database-backup', 'nav.backups', 'backups.manage'],
];
$settings = array_values(array_filter($settings, static fn (array $l) => $currentUser->can($l[3])));
// Bouton « Nouveau » : les saisies les plus fréquentes, selon les droits.
$quick = array_values(array_filter([
    ['/edb/create', 'file-pen-line', 'nav.new_edb', 'edb.create'],
    ['/work-orders/create', 'clipboard-list', 'nav.new_work_order', 'workorder.create'],
    ['/fuel/create', 'fuel', 'nav.new_refill', 'fuel.record'],
    ['/stock/transfers/create', 'arrow-left-right', 'nav.new_stock_transfer', 'stock.transfer'],
    ['/fleet-transfers/create', 'route', 'nav.new_fleet_transfer', 'fleet.transfer'],
    ['/finance/payments/create', 'banknote', 'nav.new_payment', 'finance.request'],
], static fn (array $l) => $currentUser->can($l[3])));
// Un seul lien allumé : celui dont le chemin est le plus précis (« /stock/transfers » plutôt que « /stock »).
$navPaths = [];
$aliases = [];
foreach ($sections as [, $sectionLinks]) {
    foreach ($sectionLinks as $l) {
        $navPaths[] = $l[0];
        foreach ($l['also'] ?? [] as $alias) { $navPaths[] = $alias; $aliases[$alias] = $l[0]; }
    }
}
foreach ($settings as $l) { $navPaths[] = $l[0]; }
$activePath = '';
foreach ($navPaths as $path) {
    if (!str_contains($path, '?') && nav_current($currentPath, $path) !== '' && strlen($path) > strlen($activePath)) {
        $activePath = $path;
    }
}
$activePath = $aliases[$activePath] ?? $activePath;
if (preg_match('#^/sites/\d+$#', $currentPath)) {
    $activePath = $contextSite ? '/sites/' . $contextSite['id'] : '';
}
$navLink = static function (array $l) use ($activePath, $navCounts): string {
    $current = $l[0] === $activePath ? ' aria-current="page"' : '';
    $count = isset($l['count']) ? (int) ($navCounts[$l['count']] ?? 0) : 0;
    [$path, $query] = array_pad(explode('?', $l[0], 2), 2, '');
    parse_str($query, $params);

    return '<li' . (!empty($l['sub']) ? ' class="nav__sub"' : '') . '><a href="' . e(url($path, $params)) . '"' . $current . (!empty($l['blank']) ? ' target="_blank" rel="noopener"' : '') . ' title="' . e(t($l[2])) . '"><i data-lucide="' . e($l[1]) . '"></i>'
        . '<span class="nav__text">' . e(t($l[2])) . '</span>'
        . ($count > 0 ? '<span class="nav__badge nav__badge--warn" title="' . e(t('nav.to_process', ['count' => $count])) . '">' . e($count > 99 ? '99+' : (string) $count) . '</span>' : '')
        . (!empty($l['badge']) ? '<span class="nav__badge" data-nav-badge="' . e($l['badge']) . '" hidden></span>' : '') . '</a></li>';
};
?>
<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body<?= $currentUser->role->value === 'technician' && $currentPath === '/' ? ' data-field-home="' . e(url('/terrain')) . '"' : '' ?>>
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
<div class="layout">
    <aside class="sidebar" id="sidebar" aria-label="<?= e(t('nav.main')) ?>">
        <div class="sidebar__brand">
            <i data-lucide="wrench"></i>
            <div class="nav__text">ETHAN VRSC<small>VRSC SARL</small></div>
            <button type="button" class="sidebar__collapse" data-sidebar-mini aria-pressed="false" title="<?= e(t('nav.collapse')) ?>" aria-label="<?= e(t('nav.collapse')) ?>"><i data-lucide="panel-left-close"></i></button>
        </div>

        <?php if (count($navSites ?? []) > 1): ?>
        <form method="post" action="<?= e(url('/context/site')) ?>" class="site-switch">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= e($currentPath) ?>">
            <label for="site-switch" class="site-switch__label"><?= e(t('nav.work_site')) ?></label>
            <div class="site-switch__control" title="<?= e($contextSite['name'] ?? t('common.all_sites')) ?>">
                <i data-lucide="<?= e($contextSite ? ($siteIcons[$contextSite['activity']] ?? 'map-pin') : 'globe-2') ?>"></i>
                <select id="site-switch" name="site_id" data-autosubmit>
                    <option value=""><?= e(t('common.all_sites')) ?></option>
                    <?php foreach ($navSites as $navSite): ?>
                        <option value="<?= e($navSite['id']) ?>"<?= (int) $navSite['id'] === $siteContext ? ' selected' : '' ?>><?= e($navSite['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i data-lucide="chevrons-up-down" class="site-switch__chevron"></i>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($quick !== []): ?>
        <div class="menu sidebar__new" data-menu>
            <button type="button" class="sidebar__new-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" title="<?= e(t('nav.new')) ?>"><i data-lucide="plus"></i><span class="nav__text"><?= e(t('nav.new')) ?></span></button>
            <div class="menu__list" role="menu" hidden>
                <?php foreach ($quick as $q): ?>
                    <a role="menuitem" href="<?= e(url($q[0])) ?>"><i data-lucide="<?= e($q[1]) ?>"></i><?= e(t($q[2])) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <ul class="nav">
            <?php foreach ($order as $key): ?>
                <?php [$sectionLabel, $links] = $sections[$key]; $links = array_filter($links, static fn (array $l) => $currentUser->can($l[3]) && !(isset($l['unless']) && $currentUser->can($l['unless']))); ?>
                <?php if ($links !== []): ?>
                    <li class="nav__section"><span class="nav__text"><?= e(t($sectionLabel)) ?></span></li>
                    <?php foreach ($links as $link): ?><?= $navLink($link) ?><?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($settings !== []): /* Administration toujours visible : référentiel, utilisateurs, journal… */ ?>
                <li class="nav__section"><span class="nav__text"><?= e(t('nav.section_admin')) ?></span></li>
                <?php foreach ($settings as $link): ?><?= $navLink($link) ?><?php endforeach; ?>
            <?php endif; ?>
        </ul>

        <?php if ($currentUser->can('field.use')): ?>
            <div class="sidebar__foot">
                <a class="sidebar__field" href="<?= e(url('/terrain')) ?>" title="<?= e(t('nav.field_mode')) ?>"><i data-lucide="smartphone"></i><span class="nav__text"><?= e(t('nav.field_mode')) ?></span></a>
            </div>
        <?php endif; ?>
    </aside>
    <div class="main">
        <header class="topbar">
            <button type="button" class="btn btn--ghost topbar__menu" data-toggle-sidebar aria-controls="sidebar" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>">
                <i data-lucide="menu"></i>
            </button>
            <p class="topbar__title"><?= e($title ?? '') ?></p>
            <?php if ($currentUser->can('dashboard.view')): ?>
            <?php /* Recherche rapide : engins, OT et pages du menu (Ctrl + K) — js/pm-command.js */ ?>
            <button type="button" class="topbar__search" data-cmdk-open data-url="<?= e(url('/search')) ?>" aria-label="<?= e(t('search.open')) ?>">
                <i data-lucide="search"></i><span><?= e(t('search.placeholder')) ?></span><kbd>Ctrl K</kbd>
            </button>
            <div class="notif" data-notifications data-url="<?= e(url('/notifications')) ?>" data-user="<?= e($currentUser->id) ?>"
                 data-scope="<?= e($contextSite['name'] ?? t('notify.all_my_sites')) ?>">
                <button type="button" class="btn btn--ghost notif__btn" data-notif-toggle aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('notify.title')) ?>">
                    <i data-lucide="bell"></i><span class="notif__badge" data-notif-badge hidden></span>
                </button>
                <div class="notif__panel" data-notif-panel hidden>
                    <div class="notif__head">
                        <div><strong><?= e(t('notify.title')) ?></strong><small class="muted" data-notif-scope></small></div>
                        <button type="button" class="link-btn small" data-notif-seen><?= e(t('notify.mark_seen')) ?></button>
                    </div>
                    <div class="notif__body" data-notif-list><p class="notif__empty"><?= e(t('js.dt.loading')) ?></p></div>
                    <a class="notif__foot" href="<?= e(url('/alerts')) ?>"><?= e(t('notify.see_all')) ?><i data-lucide="arrow-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
            <?= $view->partial('partials/lang_switch', ['currentPath' => $currentPath]) ?>
            <div class="topbar__user">
                <span class="avatar" aria-hidden="true"><?= e($currentUser->initials()) ?></span>
                <span class="name"><?= e($currentUser->name()) ?><br><small class="muted"><?= e(t($currentUser->role->labelKey())) ?></small></span>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--ghost" title="<?= e(t('auth.logout')) ?>" aria-label="<?= e(t('auth.logout')) ?>"><i data-lucide="log-out"></i></button>
                </form>
            </div>
        </header>
        <main id="main" class="content">
            <?= $view->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
            <?= $content ?>
        </main>
    </div>
</div>
<?= $view->partial('partials/scripts', ['pageScripts' => $pageScripts ?? []]) ?>
</body>
</html>
