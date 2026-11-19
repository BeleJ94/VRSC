<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ReferenceRepository;
use App\Repositories\StockDocumentRepository;
use App\Services\ApprovalService;
use App\Services\StockTransferService;

/** Transferts intermagasins : demande, suivi, expédition, réception contradictoire. */
final class StockTransferController extends Controller
{
    public const STATUSES = ['requested', 'authorized', 'shipped', 'received', 'rejected', 'cancelled'];

    public function __construct(
        ControllerContext $ctx,
        private readonly StockDocumentRepository $documents,
        private readonly StockTransferService $service,
        private readonly ApprovalService $approvals,
        private readonly ReferenceRepository $references,
        private readonly \App\Core\Database $db,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $status = $request->string('status');
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        return $this->render('stock/transfers', [
            'title' => t('stock.transfers'),
            'transfers' => $this->documents->transfers($status, $this->siteFilter($request)),
            'status' => $status,
            'canRequest' => $this->user()->can('stock.transfer'),
        ]);
    }

    public function create(Request $request): Response
    {
        $partId = $request->int('part_id');

        return $this->render('stock/transfer_form', [
            'title' => t('stock.transfer_new'),
            // Tous les sites actifs : le bénéficiaire doit pouvoir désigner un magasin d'origine hors de son périmètre.
            'sites' => $this->db->fetchAll('SELECT id, name FROM sites WHERE is_active = 1 ORDER BY name'),
            'ownSites' => $this->user()->scopeFor('stock.transfer'),
            'parts' => $this->db->fetchAll('SELECT id, reference, name, unit FROM parts WHERE is_active = 1 ORDER BY reference LIMIT 2000'),
            'carriers' => $this->references->options('carriers'),
            'partId' => $partId,
            'availability' => $partId !== null ? $this->availabilityEverywhere($partId) : [],
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->request($request->body);

        return $this->redirect("/stock/transfers/$id", t('flash.created'));
    }

    public function show(Request $request, array $params): Response
    {
        $transfer = $this->documents->findTransfer($this->id($params)) ?? throw HttpException::notFound();
        $user = $this->user();

        return $this->render('stock/transfer_show', [
            'title' => $transfer['reference'],
            'transfer' => $transfer,
            'lines' => $this->documents->transferLines((int) $transfer['id']),
            'approval' => $this->approvals->forEntity(StockTransferService::ENTITY, (int) $transfer['id']),
            'canShip' => $transfer['status'] === 'authorized' && $user->canAt('stock.move', (int) $transfer['from_site_id']),
            'canReceive' => $transfer['status'] === 'shipped' && $user->canAt('stock.move', (int) $transfer['to_site_id'])
                && (int) ($transfer['shipped_by'] ?? 0) !== $user->id,
            'canCancel' => in_array($transfer['status'], ['requested', 'authorized'], true)
                && ((int) ($transfer['requested_by'] ?? 0) === $user->id || $user->can('approvals.override')),
            'carriers' => $this->references->options('carriers'),
            'print' => $request->string('print') === '1',
        ]);
    }

    public function ship(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->ship($id, $request->body);

        return $this->redirect("/stock/transfers/$id", t('flash.transfer_shipped'));
    }

    public function receive(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->receive($id, $request->body);

        return $this->redirect("/stock/transfers/$id", t('flash.transfer_received'));
    }

    public function cancel(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->cancel($id, (string) $request->string('comment'));

        return $this->redirect("/stock/transfers/$id", t('flash.updated'));
    }

    /** Disponibilité d'un article dans tous les magasins (recherche « stock local puis autres sites »). */
    private function availabilityEverywhere(int $partId): array
    {
        return $this->db->fetchAll(
            'SELECT s.id AS site_id, s.name AS site, ps.quantity - ps.reserved AS available
               FROM part_stocks ps JOIN sites s ON s.id = ps.site_id AND s.is_active = 1
              WHERE ps.part_id = :p AND ps.quantity - ps.reserved > 0 ORDER BY available DESC',
            ['p' => $partId]
        );
    }
}
