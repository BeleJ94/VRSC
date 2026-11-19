<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PartRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ReferenceRepository;
use App\Repositories\SiteRepository;
use App\Repositories\UserRepository;
use App\Services\ApprovalService;
use App\Services\AttachmentService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\StockPeriodService;

/** États de besoins (EDB), devis, commandes, réceptions et les trois rapports mensuels. */
final class PurchaseController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly PurchaseRepository $purchases,
        private readonly PurchaseRequestService $requests,
        private readonly PurchaseOrderService $orders,
        private readonly ApprovalService $approvals,
        private readonly AttachmentService $attachments,
        private readonly SiteRepository $sites,
        private readonly ReferenceRepository $references,
        private readonly PartRepository $parts,
        private readonly UserRepository $users,
        private readonly Database $db,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        return $this->render('edb/index', [
            'title' => t('nav.edb'),
            'sites' => $this->sites->listActive(),
            'filters' => ['site_id' => $this->siteFilter($request), 'status' => $request->string('status'), 'urgency' => $request->string('urgency'), 'category' => $request->string('category'),
                'mine' => $request->string('mine') === '1'],
            'canCreate' => $this->user()->can('edb.create'),
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, PurchaseRepository::SORTABLE, ['x.requested_at', 'DESC']);

        return $this->json($this->purchases->datatable($dt, $this->filters($request)));
    }

    /** Les trois rapports mensuels obligatoires : émis, approuvés exécutés, approuvés non intégralement exécutés. */
    public function reports(Request $request): Response
    {
        $filters = $this->filters($request);
        $report = $filters['report'] ?? 'issued';
        $period = substr((string) $filters['from'], 0, 7);

        return $this->render('edb/reports', [
            'title' => t('nav.edb_reports'),
            'report' => $report,
            'period' => $period,
            'summary' => $this->purchases->periodSummary((string) $filters['from'], (string) $filters['to'], $filters),
            'sites' => $this->sites->listActive(),
            'projects' => $this->references->options('projects'),
            'requesters' => $this->users->listActive(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();
        $sites = array_values(array_filter($this->sites->listActive(), static fn (array $s): bool => $user->canAt('edb.create', (int) $s['id'])));
        $beneficiaries = $this->parts->beneficiaries();

        return $this->render('edb/form', [
            'title' => t('edb.new'),
            'sites' => $sites,
            'departments' => $this->references->options('departments'),
            'costCenters' => $this->references->options('cost-centers'),
            'projects' => $beneficiaries['projects'],
            'equipment' => $beneficiaries['equipment'],
            'parts' => $this->db->fetchAll('SELECT id, reference, name, unit FROM parts WHERE is_active = 1 ORDER BY reference LIMIT 2000'),
            'siteId' => $this->siteFilter($request) ?? (count($sites) === 1 ? (int) $sites[0]['id'] : null),
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->requests->create($request->body);

        return $this->redirect("/edb/$id", t('flash.created'));
    }

    public function show(Request $request, array $params): Response
    {
        $edb = $this->purchases->find($this->id($params)) ?? throw HttpException::notFound();
        $id = (int) $edb['id'];
        $user = $this->user();
        $siteId = (int) $edb['site_id'];
        $lines = $this->purchases->lines($id);
        $stores = [];
        foreach ($lines as $line) {
            if ($line['part_id'] !== null && (float) $line['other_available'] > 0) {
                $stores[(int) $line['id']] = $this->purchases->otherStores((int) $line['part_id'], $siteId);
            }
        }
        $approved = $edb['status'] === 'approved';

        return $this->render('edb/show', [
            'title' => $edb['reference'],
            'edb' => $edb,
            'lines' => $lines,
            'stores' => $stores,
            'fulfilments' => $this->purchases->fulfilments($id),
            'quotes' => $this->purchases->quotes($id),
            'orders' => $this->purchases->orders($id),
            'approval' => $this->approvals->forEntity(PurchaseRequestService::ENTITY, $id),
            'suppliers' => $this->references->options('suppliers'),
            'canIssue' => $approved && $user->canAt('stock.move', $siteId),
            'canTransfer' => $approved && $user->canAt('stock.transfer', $siteId),
            'canBuy' => $approved && $user->canAt('purchase.manage', $siteId),
            'canCancel' => in_array($edb['status'], ['pending', 'approved'], true)
                && ((int) ($edb['requested_by'] ?? 0) === $user->id || $user->canAt('approvals.override', $siteId)),
        ]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->requests->cancel($id, (string) $request->string('cancel_reason'));

        return $this->redirect("/edb/$id", t('flash.updated'));
    }

    public function issue(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->requests->issueFromStock($id, $request->body);

        return $this->redirect("/edb/$id", t('flash.stock_moved'));
    }

    public function transfer(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $transferId = $this->requests->requestTransfer($id, $request->body);

        return $this->redirect("/stock/transfers/$transferId", t('flash.created'));
    }

    public function addQuote(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->orders->addQuote($id, $request->body);

        return $this->redirect("/edb/$id#quotes", t('flash.created'));
    }

    public function selectQuote(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->orders->selectQuote($id, $request->body);

        return $this->redirect("/edb/$id#quotes", t('flash.updated'));
    }

    public function createOrder(Request $request, array $params): Response
    {
        $orderId = $this->orders->createOrder($this->id($params), $request->body);

        return $this->redirect("/purchases/$orderId", t('flash.created'));
    }

    public function orders(Request $request): Response
    {
        $status = $request->string('status');
        $status = in_array($status, ['issued', 'partially_received', 'received', 'cancelled'], true) ? $status : null;

        return $this->render('edb/orders', [
            'title' => t('nav.purchases'),
            'orders' => $this->purchases->orders(null, $status),
            'outstanding' => $this->purchases->outstanding($this->siteFilter($request)),
            'status' => $status,
        ]);
    }

    public function order(Request $request, array $params): Response
    {
        $order = $this->purchases->findOrder($this->id($params)) ?? throw HttpException::notFound();
        $user = $this->user();
        $receipts = $this->purchases->receipts((int) $order['id']);
        foreach ($receipts as &$receipt) {
            $receipt['attachments'] = $this->attachments->list('goods_receipt', (int) $receipt['id']);
        }
        unset($receipt);
        $open = in_array($order['status'], ['issued', 'partially_received'], true);

        return $this->render('edb/order', [
            'title' => $order['reference'],
            'order' => $order,
            'lines' => $this->purchases->orderLines((int) $order['id']),
            'receipts' => $receipts,
            'carriers' => $this->references->options('carriers'),
            'canReceive' => $open && $user->canAt('stock.move', (int) $order['site_id']) && (int) ($order['ordered_by'] ?? 0) !== $user->id,
            'canCancel' => $order['status'] === 'issued' && $user->canAt('purchase.manage', (int) $order['site_id']),
        ]);
    }

    public function receive(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->orders->receive($id, $request->body, $request->file('delivery_file'));

        return $this->redirect("/purchases/$id", t('flash.receipt_recorded'));
    }

    public function cancelOrder(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->orders->cancelOrder($id, (string) $request->string('cancel_reason'));

        return $this->redirect("/purchases/$id", t('flash.updated'));
    }

    /** Filtres communs à la liste et aux rapports : période mensuelle, site, projet, demandeur, catégorie, statut, urgence. */
    private function filters(Request $request): array
    {
        $report = $request->string('report');
        $report = in_array($report, PurchaseRepository::REPORTS, true) ? $report : null;
        $period = (string) $request->string('period');
        $from = $to = null;
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $report !== null || $request->path === '/edb/reports') {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                $period = date('Y-m');
            }
            [$from, $to] = StockPeriodService::bounds($period);
        }
        $pick = static fn (?string $value, array $allowed): ?string => in_array($value, $allowed, true) ? $value : null;

        return [
            'report' => $report ?? ($request->path === '/edb/reports' ? 'issued' : ($from !== null ? 'issued' : null)),
            'from' => $from,
            'to' => $to,
            'site_id' => $this->siteFilter($request),
            'project_id' => $request->int('project_id'),
            'requested_by' => $request->int('requested_by'),
            'category' => $pick($request->string('category'), PurchaseRepository::CATEGORIES),
            'status' => $pick($request->string('status'), PurchaseRepository::DISPLAY_STATUSES),
            'urgency' => $pick($request->string('urgency'), PurchaseRepository::URGENCIES),
        ];
    }
}
