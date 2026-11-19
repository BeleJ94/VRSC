<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Role;
use App\Repositories\DelegationRepository;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use App\Services\DelegationService;

final class DelegationController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly DelegationRepository $delegations,
        private readonly DelegationService $service,
        private readonly UserRepository $users,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('delegations/index', [
            'title' => t('nav.delegations'),
            'delegations' => $this->delegations->all(),
            'users' => $this->users->listActive(),
            'sites' => $this->sites->listActive(),
            'roles' => array_values(array_filter(Role::cases(), static fn (Role $r): bool => $r !== Role::Admin)),
            'allSites' => $this->delegations->scope()->isAll(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->service->create($request->body);

        return $this->redirect('/delegations', t('flash.created'));
    }

    public function revoke(Request $request, array $params): Response
    {
        $this->service->revoke($this->id($params));

        return $this->redirect('/delegations', t('flash.delegation_revoked'));
    }
}
