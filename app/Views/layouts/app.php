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
// Barre horizontale : une entrée par section, menu déroulant à deux colonnes (pages + raccourcis).
$sectionIcons = ['work' => 'house', 'purchasing' => 'shopping-bag', 'stores' => 'warehouse', 'fleet' => 'truck', 'maintenance' => 'wrench',
    'finance' => 'landmark', 'reports' => 'bar-chart-3', 'admin' => 'settings'];
$shortcutSection = ['/edb/create' => 'purchasing', '/work-orders/create' => 'maintenance', '/fuel/create' => 'fleet',
    '/stock/transfers/create' => 'stores', '/fleet-transfers/create' => 'fleet', '/finance/payments/create' => 'finance'];
$visible = static fn (array $l): bool => $currentUser->can($l[3]) && !(isset($l['unless']) && $currentUser->can($l['unless']));
$bar = [];
foreach (array_merge($order, ['admin']) as $key) {
    [$label, $links] = $key === 'admin' ? ['nav.section_admin', $settings] : $sections[$key];
    $links = array_values(array_filter($links, $visible));
    if ($links === []) {
        continue;
    }
    $count = 0;
    $badges = [];
    $active = false;
    foreach ($links as $l) {
        $count += isset($l['count']) ? (int) ($navCounts[$l['count']] ?? 0) : 0;
        if (!empty($l['badge'])) {
            $badges[] = $l['badge'];
        }
        $active = $active || $l[0] === $activePath;
    }
    $bar[] = ['key' => $key, 'label' => $label, 'links' => $links, 'count' => $count, 'badges' => implode(' ', $badges), 'active' => $active,
        'shortcuts' => array_values(array_filter($quick, static fn (array $q) => ($shortcutSection[$q[0]] ?? null) === $key))];
}
// Fil d'Ariane : section › page active.
$crumb = null;
foreach ($bar as $b) {
    foreach ($b['links'] as $l) {
        if ($l[0] === $activePath) {
            $crumb = [$b['label'], $l[2], $l[0]];
        }
    }
}
?>
<!doctype html>
<html lang="<?= e(\App\Core\I18n::current()->locale()) ?>">
<head>
    <?= $view->partial('partials/head', ['title' => $title ?? null]) ?>
</head>
<body<?= $currentUser->role->value === 'technician' && $currentPath === '/' ? ' data-field-home="' . e(url('/terrain')) . '"' : '' ?>>
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
<div class="layout layout--top">
    <header class="appbar">
        <div class="appbar__top">
            <button type="button" class="appbar__burger" data-toggle-sidebar aria-controls="appnav" aria-expanded="false" aria-label="<?= e(t('nav.menu')) ?>"><i data-lucide="menu"></i></button>
            <a class="appbar__brand" href="<?= e(url('/')) ?>" title="ETHAN VRSC — VRSC SARL">
                <img src="<?= e(asset('img/logo-vrsc-white.svg')) ?>" alt="VRSC" width="104" height="33">
                <span class="appbar__product">ETHAN VRSC</span>
            </a>

            <?php if (count($navSites ?? []) > 1): ?>
            <form method="post" action="<?= e(url('/context/site')) ?>" class="site-switch appbar__site">
                <?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e($currentPath) ?>">
                <label for="site-switch" class="sr-only"><?= e(t('nav.work_site')) ?></label>
                <div class="site-switch__control" title="<?= e(t('nav.work_site')) ?> : <?= e($contextSite['name'] ?? t('common.all_sites')) ?>">
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

            <?php if ($currentUser->can('dashboard.view')): ?>
            <?php /* Recherche rapide : engins, OT et pages du menu (Ctrl + K) — js/pm-command.js */ ?>
            <button type="button" class="topbar__search appbar__search" data-cmdk-open data-url="<?= e(url('/search')) ?>" aria-label="<?= e(t('search.open')) ?>">
                <i data-lucide="search"></i><span><?= e(t('search.placeholder')) ?></span><kbd>Ctrl K</kbd>
            </button>
            <?php endif; ?>

            <div class="appbar__tools">
                <?php if ($quick !== []): ?>
                <div class="menu appbar__new" data-menu>
                    <button type="button" class="appbar__new-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false"><i data-lucide="plus"></i><span><?= e(t('nav.new')) ?></span></button>
                    <div class="menu__list" role="menu" hidden>
                        <?php foreach ($quick as $q): ?>
                            <a role="menuitem" href="<?= e(url($q[0])) ?>"><i data-lucide="<?= e($q[1]) ?>"></i><?= e(t($q[2])) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($currentUser->can('dashboard.view')): ?>
                <div class="notif" data-notifications data-url="<?= e(url('/notifications')) ?>" data-user="<?= e($currentUser->id) ?>"
                     data-scope="<?= e($contextSite['name'] ?? t('notify.all_my_sites')) ?>">
                    <button type="button" class="appbar__icon notif__btn" data-notif-toggle aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('notify.title')) ?>">
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

                <div class="menu appbar__user" data-menu>
                    <button type="button" class="appbar__user-btn" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('nav.user_menu')) ?>">
                        <span class="avatar" aria-hidden="true"><?= e($currentUser->initials()) ?></span>
                        <span class="appbar__user-name"><?= e($currentUser->name()) ?><small><?= e(t($currentUser->role->labelKey())) ?></small></span>
                        <i data-lucide="chevron-down" class="appbar__chev"></i>
                    </button>
                    <div class="menu__list" role="menu" hidden>
                        <?php if ($currentUser->can('field.use')): ?>
                            <a role="menuitem" href="<?= e(url('/terrain')) ?>"><i data-lucide="smartphone"></i><?= e(t('nav.field_mode')) ?></a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/logout')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" role="menuitem" class="is-danger"><i data-lucide="log-out"></i><?= e(t('auth.logout')) ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <nav class="appnav" id="appnav" aria-label="<?= e(t('nav.main')) ?>">
            <ul class="appnav__list" data-appnav>
                <?php foreach ($bar as $i => $b): ?>
                    <li class="appnav__item<?= $b['active'] ? ' is-active' : '' ?><?= $b['key'] === 'admin' ? ' appnav__item--end' : '' ?>">
                        <button type="button" class="appnav__trigger" data-nav-trigger aria-expanded="false" aria-controls="appnav-<?= e($b['key']) ?>"<?= $b['key'] === 'admin' ? ' title="' . e(t($b['label'])) . '"' : '' ?>>
                            <i data-lucide="<?= e($sectionIcons[$b['key']] ?? 'circle') ?>"></i>
                            <span class="appnav__label"><?= e(t($b['label'])) ?></span>
                            <?php if ($b['count'] > 0): ?><span class="nav__badge nav__badge--warn" title="<?= e(t('nav.to_process', ['count' => $b['count']])) ?>"><?= e($b['count'] > 99 ? '99+' : (string) $b['count']) ?></span><?php endif; ?>
                            <?php if ($b['badges'] !== ''): ?><span class="nav__badge appnav__alarm" data-nav-badge="<?= e($b['badges']) ?>" hidden></span><?php endif; ?>
                            <i data-lucide="chevron-down" class="appnav__chev"></i>
                        </button>
                        <div class="appnav__panel<?= $b['shortcuts'] !== [] ? ' appnav__panel--wide' : '' ?>" id="appnav-<?= e($b['key']) ?>" hidden>
                            <div class="appnav__col">
                                <span class="appnav__heading"><?= e(t($b['label'])) ?></span>
                                <ul class="nav appnav__links">
                                    <?php foreach ($b['links'] as $link): ?><?= $navLink($link) ?><?php endforeach; ?>
                                </ul>
                            </div>
                            <?php if ($b['shortcuts'] !== []): ?>
                                <div class="appnav__col appnav__col--side">
                                    <span class="appnav__heading"><?= e(t('nav.shortcuts')) ?></span>
                                    <ul class="appnav__shortcuts">
                                        <?php foreach ($b['shortcuts'] as $q): ?>
                                            <li><a href="<?= e(url($q[0])) ?>"><i data-lucide="plus-circle"></i><?= e(t('nav.new')) ?> : <?= e(mb_strtolower(t($q[2]))) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </header>

    <main id="main" class="content">
        <?php if ($crumb !== null && $crumb[2] !== '/'): ?>
            <nav class="crumbs" aria-label="<?= e(t('nav.breadcrumb')) ?>"><span><?= e(t($crumb[0])) ?></span><i data-lucide="chevron-right"></i><a href="<?= e(url($crumb[2])) ?>"><?= e(t($crumb[1])) ?></a></nav>
        <?php endif; ?>
        <?= $view->partial('partials/flash', ['flashSuccess' => $flashSuccess ?? null, 'flashError' => $flashError ?? null]) ?>
        <?= $content ?>
    </main>
</div>
<?= $view->partial('partials/scripts', ['pageScripts' => $pageScripts ?? []]) ?>
</body>
</html>
