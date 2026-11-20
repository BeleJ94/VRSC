<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\DashboardDrillRepository;

/** Listes de détail des tableaux de bord (DataTables côté serveur, exportables en Excel et PDF). */
final class DashboardDrillController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly DashboardDrillRepository $drill)
    {
        parent::__construct($ctx);
    }

    public function payments(Request $request): Response
    {
        return $this->json($this->drill->payments($this->dt($request, ['x.date', 'DESC']), $this->filters($request, ['status', 'kind', 'category', 'date_field'])));
    }

    public function invoices(Request $request): Response
    {
        return $this->json($this->drill->invoices($this->dt($request, ['x.date', 'DESC']), $this->filters($request, ['unpaid', 'overdue'])));
    }

    public function commitments(Request $request): Response
    {
        return $this->json($this->drill->commitments($this->dt($request, ['x.date', 'DESC']), $this->filters($request, ['category'])));
    }

    public function costLines(Request $request): Response
    {
        return $this->json($this->drill->costLines($this->dt($request, ['x.date', 'DESC']), $this->filters($request, ['equipment_id', 'missing'])));
    }

    public function stockValues(Request $request): Response
    {
        return $this->json($this->drill->stockValues($this->dt($request, ['x.value', 'DESC']), $this->filters($request, [])));
    }

    private function dt(Request $request, array $default): DataTableRequest
    {
        return DataTableRequest::fromQuery($request->query, DashboardDrillRepository::SORTABLE, $default);
    }

    /** Site (dans le périmètre), période (dates valides) et filtres propres à la liste (valeurs simples). */
    private function filters(Request $request, array $keys): array
    {
        $date = static fn (?string $v): ?string => $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $filters = ['site_id' => $this->siteFilter($request), 'from' => $date($request->string('from')), 'to' => $date($request->string('to'))];
        foreach ($keys as $key) {
            $value = $request->string($key);
            $filters[$key] = $value !== null && preg_match('/^[a-z0-9_,]{1,80}$/', $value) ? $value : null;
        }

        return $filters;
    }
}
