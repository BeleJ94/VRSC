<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SiteRepository;
use App\Repositories\StockDocumentRepository;
use App\Services\ApprovalService;
use App\Services\StockInventoryService;

/** Inventaires physiques : ouverture, comptage, soumission des écarts, suivi de l'approbation. */
final class StockInventoryController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly StockDocumentRepository $documents,
        private readonly StockInventoryService $service,
        private readonly ApprovalService $approvals,
        private readonly SiteRepository $sites,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $sites = array_values(array_filter($this->sites->listActive(), static fn (array $s): bool => $user->canAt('stock.inventory', (int) $s['id'])));

        return $this->render('stock/inventories', [
            'title' => t('stock.inventories'),
            'inventories' => $this->documents->inventories($this->siteFilter($request)),
            'sites' => $sites,
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->open($request->body);

        return $this->redirect("/stock/inventories/$id", t('flash.created'));
    }

    public function show(Request $request, array $params): Response
    {
        $inventory = $this->documents->findInventory($this->id($params)) ?? throw HttpException::notFound();

        return $this->render('stock/inventory_show', [
            'title' => $inventory['reference'],
            'inventory' => $inventory,
            'lines' => $this->documents->inventoryLines((int) $inventory['id']),
            'approval' => $this->approvals->forEntity(StockInventoryService::ENTITY, (int) $inventory['id']),
            'editable' => $inventory['status'] === 'draft' && $this->user()->canAt('stock.inventory', (int) $inventory['site_id']),
        ]);
    }

    public function counts(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->saveCounts($id, $request->body);
        if ($request->string('submit') === '1') {
            $status = $this->service->submit($id);

            return $this->redirect("/stock/inventories/$id", t($status === 'approved' ? 'flash.inventory_approved' : 'flash.inventory_submitted'));
        }

        return $this->redirect("/stock/inventories/$id", t('flash.updated'));
    }

    public function cancel(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->cancel($id);

        return $this->redirect('/stock/inventories', t('flash.updated'));
    }
}
