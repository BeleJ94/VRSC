<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\StockLedgerRepository;
use App\Repositories\SiteRepository;
use App\Services\StockPeriodService;
use App\Services\StockService;

/** États de stock : fiche de magasin, état consolidé, journal des mouvements, clôtures mensuelles. */
final class StockController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly StockLedgerRepository $ledger,
        private readonly SiteRepository $sites,
        private readonly StockPeriodService $periods,
        private readonly StockService $stock,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $siteId = $this->siteFilter($request);
        [$period, $from, $to] = self::period($request);

        return $this->render('stock/index', [
            'title' => t('nav.stock'),
            'sites' => $this->sites->listActive(),
            'summary' => $this->ledger->statementSummary($from, $to, $siteId),
            'filters' => ['site_id' => $siteId, 'period' => $period, 'consolidated' => $request->string('consolidated') === '1', 'level' => $request->string('level')],
            'closed' => $siteId !== null && $this->stock->isClosed($siteId, $from),
        ]);
    }

    public function data(Request $request): Response
    {
        [, $from, $to] = self::period($request);
        $dt = DataTableRequest::fromQuery($request->query, StockLedgerRepository::SORTABLE, ['x.reference', 'ASC']);
        $level = $request->string('level');

        return $this->json($this->ledger->statement($dt, $from, $to, [
            'site_id' => $this->siteFilter($request),
            'consolidated' => $request->string('consolidated') === '1',
            'level' => in_array($level, StockLedgerRepository::LEVELS, true) ? $level : null,
        ]));
    }

    public function journal(Request $request): Response
    {
        return $this->render('stock/journal', [
            'title' => t('stock.journal'),
            'sites' => $this->sites->listActive(),
            'filters' => ['site_id' => $this->siteFilter($request), 'type' => $request->string('type'), 'from' => self::date($request->string('from')), 'to' => self::date($request->string('to'))],
            'types' => array_merge(StockService::INBOUND, StockService::OUTBOUND, StockService::SIGNED),
        ]);
    }

    public function journalData(Request $request): Response
    {
        $dt = DataTableRequest::fromQuery($request->query, StockLedgerRepository::SORTABLE, ['m.moved_on', 'DESC']);
        $types = array_merge(StockService::INBOUND, StockService::OUTBOUND, StockService::SIGNED);

        return $this->json($this->ledger->journal($dt, [
            'site_id' => $this->siteFilter($request),
            'type' => in_array($request->string('type'), $types, true) ? $request->string('type') : null,
            'from' => self::date($request->string('from')),
            'to' => self::date($request->string('to')),
            'part_id' => $request->int('part_id'),
        ]));
    }

    public function periods(Request $request): Response
    {
        return $this->render('stock/periods', [
            'title' => t('stock.periods'),
            'periods' => $this->ledger->periods(),
            'canClose' => $this->user()->can('stock.close'),
        ]);
    }

    public function closePeriod(Request $request): Response
    {
        $this->periods->close($request->body);

        return $this->redirect('/stock/periods', t('flash.period_closed'));
    }

    /** @return array{0: string, 1: string, 2: string} [période AAAA-MM, premier jour, dernier jour] */
    private static function period(Request $request): array
    {
        $period = (string) $request->string('period');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = date('Y-m');
        }
        [$from, $to] = StockPeriodService::bounds($period);

        return [$period, $from, $to];
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
