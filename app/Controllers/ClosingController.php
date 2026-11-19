<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClosingRepository;

/** Clôtures du mois : stock, coûts par équipement et caisses, par site, au même endroit. */
final class ClosingController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly ClosingRepository $closings)
    {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $lastMonth = date('Y-m', (int) strtotime(date('Y-m-01') . ' -1 month'));
        $period = (string) $request->string('period');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period > date('Y-m')) {
            $period = $lastMonth;
        }

        return $this->render('closings/index', [
            'title' => t('nav.closings'),
            'period' => $period,
            'ended' => $period < date('Y-m'),
            'rows' => $this->closings->overview($period),
        ]);
    }
}
