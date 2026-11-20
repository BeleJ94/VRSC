<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ManagementRepository;
use App\Repositories\SiteRepository;
use App\Repositories\ExecutiveDashboardRepository;
use App\Services\DashboardService;
use App\Services\DashboardViewService;

final class DashboardController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly DashboardService $dashboard,
        private readonly SiteRepository $sites,
        private readonly ManagementRepository $management,
        private readonly DashboardViewService $views,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $siteId = $this->siteFilter($request);
        $user = $this->user();
        // Vue par profil : onglets limités aux droits, vue par défaut selon le rôle.
        $views = DashboardViewService::viewsFor($user);
        $view = in_array($request->string('view'), $views, true) ? (string) $request->string('view') : (DashboardViewService::defaultView($user, $views) ?? 'maintenance');
        $periodKey = in_array($request->string('period'), ExecutiveDashboardRepository::PERIODS, true) ? (string) $request->string('period') : 'month';
        $periodViews = ['executive', 'finance', 'purchasing', 'stores'];
        $period = in_array($view, $periodViews, true) ? ExecutiveDashboardRepository::period($periodKey) : null;
        $common = [
            'title' => t('nav.dashboard'),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
            'dashView' => $view ?? 'none',
            'views' => $views,
            'period' => $period,
            'todo' => $this->views->todo($siteId),
        ];
        if (in_array($view, $periodViews, true)) {
            return $this->render('dashboard/index', $common + ['dash' => $this->views->{$view}($period, $siteId)]);
        }

        return $this->render('dashboard/index', $common + [
            'stats' => $this->dashboard->data($siteId),
            'siteCards' => $siteId === null ? $this->management->siteReport(date('Y-m-d H:i:s', strtotime('-30 days'))) : [],
        ]);
    }

    public function data(Request $request): Response
    {
        return $this->json($this->dashboard->data($this->siteFilter($request)));
    }
}
