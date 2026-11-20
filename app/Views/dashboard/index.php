<?php
/**
 * Tableau de bord : en-tête commun (onglets, période, site, « À traiter ») puis la vue du profil.
 * @var string $dashView  @var array $views  @var array|null $period  @var array $todo  @var array $sites  @var int|null $siteId  @var array $dash
 */
$view->share('pageScripts', ['js/dashboard-views.js']);
echo $view->partial('dashboard/_header', ['dashView' => $dashView, 'views' => $views, 'period' => $period, 'sites' => $sites, 'siteId' => $siteId, 'todo' => $todo]);
if (in_array($dashView, \App\Services\DashboardViewService::PERIOD_VIEWS, true)) {
    echo $view->partial('dashboard/' . $dashView, ['dash' => $dash, 'period' => $period, 'siteId' => $siteId]);
}
