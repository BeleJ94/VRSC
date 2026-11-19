<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\StockLedgerRepository;
use App\Security\Auth;

/**
 * Clôture mensuelle d'un magasin (cahier des charges § 5) : les soldes sont figés, plus aucune écriture ne peut
 * être datée dans la période. Une régularisation ultérieure se passe dans la période ouverte et reste journalisée.
 */
final class StockPeriodService
{
    public function __construct(
        private readonly Database $db,
        private readonly StockLedgerRepository $ledger,
        private readonly StockService $stock,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** @return array{0: string, 1: string} premier et dernier jour d'une période « AAAA-MM » */
    public static function bounds(string $period): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw Validator::fail('period', 'date');
        }

        return [$period . '-01', date('Y-m-t', (int) strtotime($period . '-01'))];
    }

    public function close(array $input): void
    {
        $data = Validator::validate($input, ['site_id' => 'required|int', 'period' => 'required|string|max:7']);
        [$from, $to] = self::bounds($data['period']);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        if (!$user->canAt('stock.close', $data['site_id'])) {
            throw HttpException::forbidden();
        }
        if ($data['period'] >= date('Y-m')) {
            throw Validator::fail('period', 'period_not_ended');
        }
        $this->db->transaction(function () use ($data, $from, $to, $user): void {
            if ($this->stock->isClosed($data['site_id'], $from)) {
                throw Validator::fail('period', 'period_already_closed');
            }
            // Les périodes se clôturent dans l'ordre : pas de mouvement antérieur dans une période encore ouverte.
            $earlier = $this->db->fetchValue(
                "SELECT MIN(m.moved_on) FROM stock_movements m
                  WHERE m.site_id = :s AND m.moved_on < :from
                    AND NOT EXISTS (SELECT 1 FROM stock_periods sp WHERE sp.site_id = m.site_id AND sp.period = DATE_FORMAT(m.moved_on, '%Y-%m'))",
                ['s' => $data['site_id'], 'from' => $from]
            );
            if ($earlier !== null) {
                throw Validator::fail('period', 'period_previous_open', ['period' => substr((string) $earlier, 0, 7)]);
            }
            $open = $this->ledger->openDocuments($data['site_id'], $to);
            if ($open['transfers'] > 0 || $open['inventories'] > 0) {
                throw Validator::fail('period', 'period_open_documents', ['transfers' => $open['transfers'], 'inventories' => $open['inventories']]);
            }
            $balances = $this->ledger->closingBalances($data['site_id'], $from, $to);
            $this->ledger->closePeriod($data['site_id'], $data['period'], $user->id, $balances);
            $this->logger->log('stock_close', 'stock_period', null, $data['site_id'], null, [
                'period' => $data['period'], 'lines' => count($balances),
                'value' => array_sum(array_map(static fn (array $b): float => (float) ($b['value_close'] ?? 0), $balances)),
            ], $data['period']);
        });
    }
}
