<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EquipmentCostRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\SiteRepository;
use App\Services\EquipmentCostService;
use App\Services\StockPeriodService;

/** Ravitaillements en carburant et rapport mensuel des coûts par équipement. */
final class EquipmentCostController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly EquipmentCostRepository $costs,
        private readonly EquipmentCostService $service,
        private readonly EquipmentRepository $equipment,
        private readonly SiteRepository $sites,
        private readonly Database $db,
    ) {
        parent::__construct($ctx);
    }

    // --- Carburant ------------------------------------------------------------------

    public function fuel(Request $request): Response
    {
        return $this->render('costs/fuel', [
            'title' => t('nav.fuel'),
            'sites' => $this->sites->listActive(),
            'filters' => ['site_id' => $this->siteFilter($request), 'from' => self::date($request->string('from')), 'to' => self::date($request->string('to')),
                'equipment_id' => $request->int('equipment_id')],
            'canRecord' => $this->user()->can('fuel.record'),
        ]);
    }

    public function fuelData(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, EquipmentCostRepository::SORTABLE, ['f.refill_date', 'DESC']);

        return $this->json($this->costs->refills($dt, [
            'site_id' => $this->siteFilter($request), 'equipment_id' => $request->int('equipment_id'),
            'from' => self::date($request->string('from')), 'to' => self::date($request->string('to')),
        ]));
    }

    public function createRefill(Request $request): Response
    {
        $scope = $this->user()->scopeFor('fuel.record');
        $equipment = array_values(array_filter(
            $this->db->fetchAll(
                "SELECT e.id, e.code, e.name, e.site_id, e.hour_meter, e.odometer_km, s.name AS site, COALESCE(e.meter_basis, c.meter_basis, 'hours') AS basis
                   FROM equipment e JOIN sites s ON s.id = e.site_id LEFT JOIN equipment_categories c ON c.id = e.category_id
                  WHERE e.is_archived = 0 ORDER BY s.name, e.code LIMIT 3000"
            ),
            static fn (array $e): bool => $scope->allows((int) $e['site_id'])
        ));

        return $this->render('costs/refill_form', [
            'title' => t('fuel.new'),
            'equipment' => $equipment,
            'equipmentId' => $request->int('equipment_id'),
            'fuels' => $this->db->fetchAll("SELECT id, reference, name, unit FROM parts WHERE part_type = 'fuel' AND is_active = 1 ORDER BY reference"),
        ]);
    }

    public function storeRefill(Request $request): Response
    {
        $this->service->refill($request->body);

        return $this->redirect('/fuel', t('flash.fuel_refill'));
    }

    // --- Coût mensuel par équipement ----------------------------------------------------

    public function index(Request $request): Response
    {
        $period = self::period($request);
        $siteId = $this->siteFilter($request);
        $user = $this->user();

        return $this->render('costs/index', [
            'title' => t('nav.costs'),
            'period' => $period,
            'sites' => $this->sites->listActive(),
            'categories' => $this->equipment->categories(),
            'totals' => $this->costs->monthlyTotals($period, $siteId),
            'filters' => ['site_id' => $siteId, 'category_id' => $request->int('category_id'), 'flag' => $request->string('flag')],
            'closing' => $siteId !== null ? $this->costs->closing($siteId, $period) : null,
            'canClose' => $siteId !== null && $period < date('Y-m') && $user->canAt('costs.close', $siteId),
        ]);
    }

    public function data(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, EquipmentCostRepository::SORTABLE, ['x.total', 'DESC']);
        $flag = $request->string('flag');

        return $this->json($this->costs->monthly($dt, self::period($request), [
            'site_id' => $this->siteFilter($request),
            'category_id' => $request->int('category_id'),
            'flag' => in_array($flag, ['missing', 'alert'], true) ? $flag : null,
        ]));
    }

    public function show(Request $request, array $params): Response
    {
        $period = self::period($request);
        $row = $this->costs->monthlyRow($this->id($params), $period) ?? throw HttpException::notFound();
        [$from, $to] = StockPeriodService::bounds($period);
        $siteId = (int) $row['site_id'];

        return $this->render('costs/show', [
            'title' => $row['code'] . ' — ' . $period,
            'row' => $row,
            'period' => $period,
            'lines' => $this->costs->detail((int) $row['id'], $from, $to),
            'history' => $this->costs->history((int) $row['id'], $period),
            'closing' => $this->costs->closing($siteId, $period),
            'canCharge' => $this->user()->canAt('costs.charge', $siteId) && !$this->costs->isClosed($siteId, $period),
        ]);
    }

    public function storeCharge(Request $request): Response
    {
        $this->service->addCharge($request->body);
        $id = (int) $request->int('equipment_id');
        $period = substr((string) $request->string('charge_date'), 0, 7);

        return $this->redirect("/costs/$id?period=" . rawurlencode($period), t('flash.created'));
    }

    public function note(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->saveNote($id, $request->body);

        return $this->redirect("/costs/$id?period=" . rawurlencode((string) $request->string('period')), t('flash.updated'));
    }

    public function close(Request $request): Response
    {
        $this->service->close($request->body);

        return $this->redirect('/costs?site_id=' . (int) $request->int('site_id') . '&period=' . rawurlencode((string) $request->string('period')), t('flash.cost_closed'));
    }

    private static function period(Request $request): string
    {
        $period = (string) $request->string('period');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : date('Y-m');
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
