<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/**
 * États de stock (cahier des charges § 5) calculés depuis le grand livre des mouvements :
 * fiche de magasin sur une période, état consolidé, stock en transit, journal, périodes clôturées.
 * Tout est filtré par le périmètre de sites.
 */
final class StockLedgerRepository extends Repository
{
    public const SORTABLE = [
        'reference' => 'x.reference',
        'name' => 'x.name',
        'site' => 'x.site',
        'qty_open' => 'x.qty_open',
        'receipts' => 'x.receipts',
        'returns' => 'x.returns',
        'issues' => 'x.issues',
        'transfers' => 'x.transfers',
        'adjustments' => 'x.adjustments',
        'qty_close' => 'x.qty_close',
        'in_transit' => 'x.in_transit',
        'available' => 'x.available',
        'value_close' => 'x.value_close',
        // Journal des mouvements
        'moved_on' => 'm.moved_on',
        'document_ref' => 'm.document_ref',
        'type' => 'm.type',
        'delta' => 'm.delta',
        'value' => 'm.value',
        'part' => 'p.reference',
    ];

    public const LEVELS = ['out', 'low', 'surplus'];

    /**
     * Fiche de magasin sur [$from, $to] : une ligne par article et par site, ou par article tous sites confondus
     * (état consolidé : le transit n'est compté qu'une fois, en plus des stocks des magasins).
     *
     * @param array{site_id?: ?int, consolidated?: bool, level?: ?string} $filters
     */
    public function statement(DataTableRequest $dt, string $from, string $to, array $filters): array
    {
        [$source, $params] = $this->statementSource($from, $to, !empty($filters['consolidated']));
        $where = [];
        $fp = [];
        if (!empty($filters['site_id']) && empty($filters['consolidated'])) {
            $where[] = 'x.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        $levelSql = [
            'out' => 'x.qty_close <= 0',
            'low' => 'x.qty_close > 0 AND x.min_quantity > 0 AND x.qty_close < x.min_quantity',
            'surplus' => 'x.min_quantity > 0 AND x.qty_close > x.min_quantity * 3',
        ];
        if (isset($levelSql[$filters['level'] ?? ''])) {
            $where[] = $levelSql[$filters['level']];
        }

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.reference', 'x.name']);
    }

    /** Totaux de l'état (valeur connue, lignes dont la valeur manque, ruptures, sous le seuil). */
    public function statementSummary(string $from, string $to, ?int $siteId): array
    {
        [$source, $params] = $this->statementSource($from, $to, false);
        $where = '1=1';
        if ($siteId !== null) {
            $where = 'x.site_id = :f_site';
            $params['f_site'] = $siteId;
        }

        return $this->db->fetchOne(
            "SELECT COUNT(*) AS line_count,
                    COALESCE(SUM(x.value_close), 0) AS value_close,
                    COALESCE(SUM(x.qty_close > 0 AND x.value_close IS NULL), 0) AS value_missing,
                    COALESCE(SUM(x.qty_close <= 0), 0) AS out_count,
                    COALESCE(SUM(x.qty_close > 0 AND x.min_quantity > 0 AND x.qty_close < x.min_quantity), 0) AS low_count,
                    COALESCE(SUM(x.in_transit), 0) AS in_transit
               FROM $source WHERE $where",
            $params
        ) ?? [];
    }

    /**
     * Sous-requête de l'état. Le stock à la date $to se déduit du stock actuel en retirant les mouvements postérieurs ;
     * pour une période clôturée, la valeur figée à la clôture prime.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function statementSource(string $from, string $to, bool $consolidated): array
    {
        [$scopeSql, $params] = $this->scope->clause('ps.site_id');
        [$transitSql, $transitParams] = $this->scope->clause('t.to_site_id', 'tscope');
        $params += $transitParams + ['from1' => $from, 'from2' => $from, 'to1' => $to, 'to2' => $to, 'period' => substr($to, 0, 7)];
        $sum = static fn (string $types, string $sign = ''): string => "{$sign}COALESCE(SUM(CASE WHEN m.moved_on <= :to1 AND m.type IN ($types) THEN m.delta END), 0)";
        $rows = "SELECT p.id, p.reference, p.name, p.unit, p.part_type, s.id AS site_id, s.name AS site,
                        ps.min_quantity, ps.location, ps.reserved,
                        ps.quantity - COALESCE(mv.after_delta, 0) AS qty_close,
                        ps.quantity - COALESCE(mv.after_delta, 0) - COALESCE(mv.period_delta, 0) AS qty_open,
                        COALESCE(mv.receipts, 0) AS receipts, COALESCE(mv.returns, 0) AS returns, COALESCE(mv.issues, 0) AS issues,
                        COALESCE(mv.transfers, 0) AS transfers, COALESCE(mv.adjustments, 0) AS adjustments,
                        COALESCE(tr.in_transit, 0) AS in_transit,
                        COALESCE(b.unit_cost, ps.avg_cost, NULLIF(p.unit_cost, 0)) AS unit_cost,
                        COALESCE(b.value_close, ROUND((ps.quantity - COALESCE(mv.after_delta, 0)) * COALESCE(ps.avg_cost, NULLIF(p.unit_cost, 0)), 2)) AS value_close
                   FROM part_stocks ps
                   JOIN parts p ON p.id = ps.part_id
                   JOIN sites s ON s.id = ps.site_id
                   LEFT JOIN (
                        SELECT m.part_id, m.site_id,
                               SUM(CASE WHEN m.moved_on > :to2 THEN m.delta ELSE 0 END) AS after_delta,
                               SUM(CASE WHEN m.moved_on <= :to1 THEN m.delta ELSE 0 END) AS period_delta,
                               " . $sum("'in','opening','receipt'") . " AS receipts,
                               " . $sum("'return'") . " AS returns,
                               " . $sum("'out'", '-') . " AS issues,
                               " . $sum("'transfer_in','transfer_out'") . " AS transfers,
                               " . $sum("'inventory','adjust'") . " AS adjustments
                          FROM stock_movements m WHERE m.moved_on >= :from1 GROUP BY m.part_id, m.site_id
                   ) mv ON mv.part_id = ps.part_id AND mv.site_id = ps.site_id
                   LEFT JOIN (
                        SELECT l.part_id, t.to_site_id, SUM(l.qty_shipped) AS in_transit
                          FROM stock_transfers t JOIN stock_transfer_lines l ON l.transfer_id = t.id
                         WHERE t.status = 'shipped' AND $transitSql GROUP BY l.part_id, t.to_site_id
                   ) tr ON tr.part_id = ps.part_id AND tr.to_site_id = ps.site_id
                   LEFT JOIN stock_period_balances b ON b.site_id = ps.site_id AND b.part_id = ps.part_id AND b.period = :period
                        AND :from2 = CONCAT(b.period, '-01')
                  WHERE $scopeSql";
        // :to1 est utilisé plusieurs fois dans la même sous-requête : PDO natif exige des noms distincts.
        $rows = self::distinctPlaceholders($rows, 'to1', $params);
        if (!$consolidated) {
            return ["($rows) x", $params + []] ;
        }
        $sql = "(SELECT r.id, r.reference, r.name, r.unit, r.part_type, 0 AS site_id, CONCAT(COUNT(*), '') AS site,
                        SUM(r.min_quantity) AS min_quantity, NULL AS location, SUM(r.reserved) AS reserved,
                        SUM(r.qty_close) AS qty_close, SUM(r.qty_open) AS qty_open, SUM(r.receipts) AS receipts, SUM(r.returns) AS returns,
                        SUM(r.issues) AS issues, SUM(r.transfers) AS transfers, SUM(r.adjustments) AS adjustments,
                        SUM(r.in_transit) AS in_transit,
                        CASE WHEN SUM(r.qty_close) > 0 AND SUM(r.value_close IS NULL AND r.qty_close > 0) = 0 THEN ROUND(SUM(r.value_close) / SUM(r.qty_close), 4) END AS unit_cost,
                        CASE WHEN SUM(r.value_close IS NULL AND r.qty_close > 0) = 0 THEN SUM(r.value_close) END AS value_close
                   FROM ($rows) r GROUP BY r.id, r.reference, r.name, r.unit, r.part_type) x";

        return [$sql, $params];
    }

    /** Renomme chaque occurrence de :name en :name_1, :name_2… (préparations natives : un nom = un emplacement). */
    private static function distinctPlaceholders(string $sql, string $name, array &$params): string
    {
        $value = $params[$name];
        unset($params[$name]);
        $i = 0;

        return (string) preg_replace_callback('/:' . preg_quote($name, '/') . '\b/', static function () use (&$i, &$params, $name, $value): string {
            $i++;
            $params[$name . '_' . $i] = $value;

            return ':' . $name . '_' . $i;
        }, $sql);
    }

    /** Journal des mouvements d'un magasin (fiche opérationnelle). */
    public function journal(DataTableRequest $dt, array $filters): array
    {
        [$scopeSql, $params] = $this->scope->clause('m.site_id');
        $where = [];
        $fp = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'm.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'm.type = :f_type';
            $fp['f_type'] = (string) $filters['type'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'm.moved_on >= :f_from';
            $fp['f_from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'm.moved_on <= :f_to';
            $fp['f_to'] = $filters['to'];
        }
        if (!empty($filters['part_id'])) {
            $where[] = 'm.part_id = :f_part';
            $fp['f_part'] = (int) $filters['part_id'];
        }

        return $this->paginate(
            $dt,
            "m.id, m.moved_on, m.created_at, m.type, m.delta, m.stock_after, m.unit_cost, m.value, m.document_ref, m.comment,
             p.id AS part_id, p.reference AS part, p.name AS part_name, p.unit, s.name AS site,
             COALESCE(e.code, pr.code, d.code, sup.name) AS beneficiary,
             w.reference AS work_order, CONCAT(u.first_name, ' ', u.last_name) AS user",
            'stock_movements m
             JOIN parts p ON p.id = m.part_id
             JOIN sites s ON s.id = m.site_id
             LEFT JOIN equipment e ON e.id = m.equipment_id
             LEFT JOIN projects pr ON pr.id = m.project_id
             LEFT JOIN departments d ON d.id = m.department_id
             LEFT JOIN suppliers sup ON sup.id = m.supplier_id
             LEFT JOIN work_orders w ON w.id = m.work_order_id
             LEFT JOIN users u ON u.id = m.user_id',
            [$scopeSql],
            $params,
            $where,
            $fp,
            ['p.reference', 'p.name', 'm.document_ref', 'm.comment'],
        );
    }

    /** Quantité en transit (expédiée, pas encore réceptionnée) vers les sites du périmètre, par article. */
    public function inTransit(int $partId): float
    {
        [$scopeSql, $params] = $this->scope->clause('t.to_site_id');

        return (float) $this->db->fetchValue(
            "SELECT COALESCE(SUM(l.qty_shipped), 0) FROM stock_transfers t JOIN stock_transfer_lines l ON l.transfer_id = t.id
              WHERE t.status = 'shipped' AND l.part_id = :part AND $scopeSql",
            ['part' => $partId] + $params
        );
    }

    // --- Périodes ---------------------------------------------------------------

    /** Dernières périodes par site du périmètre : [site, période, clôturée ?]. */
    public function periods(int $months = 6): array
    {
        [$scopeSql, $params] = $this->scope->clause('s.id');
        $sites = $this->db->fetchAll("SELECT s.id, s.name FROM sites s WHERE s.is_active = 1 AND $scopeSql ORDER BY s.name", $params);
        $closed = [];
        foreach ($this->db->fetchAll(
            "SELECT sp.site_id, sp.period, sp.closed_at, CONCAT(u.first_name, ' ', u.last_name) AS closed_by_name
               FROM stock_periods sp LEFT JOIN users u ON u.id = sp.closed_by"
        ) as $row) {
            $closed[$row['site_id'] . '|' . $row['period']] = $row;
        }
        $out = [];
        foreach ($sites as $site) {
            for ($i = 1; $i <= $months; $i++) {
                $period = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
                $row = $closed[$site['id'] . '|' . $period] ?? null;
                $out[] = [
                    'site_id' => (int) $site['id'], 'site' => $site['name'], 'period' => $period,
                    'closed_at' => $row['closed_at'] ?? null, 'closed_by_name' => $row['closed_by_name'] ?? null,
                ];
            }
        }

        return $out;
    }

    /** Lignes à figer pour la clôture d'une période (tous les articles du magasin). */
    public function closingBalances(int $siteId, string $from, string $to): array
    {
        $this->scope->assertAllows($siteId);
        [$source, $params] = $this->statementSource($from, $to, false);

        return $this->db->fetchAll(
            "SELECT x.id AS part_id, x.qty_open, x.qty_close, x.unit_cost, x.value_close,
                    x.receipts + x.returns + GREATEST(x.transfers, 0) + GREATEST(x.adjustments, 0) AS qty_in,
                    x.issues - LEAST(x.transfers, 0) - LEAST(x.adjustments, 0) AS qty_out
               FROM $source WHERE x.site_id = :f_site",
            $params + ['f_site' => $siteId]
        );
    }

    public function closePeriod(int $siteId, string $period, ?int $userId, array $balances): void
    {
        $this->scope->assertAllows($siteId);
        $this->db->execute('INSERT INTO stock_periods (site_id, period, closed_by) VALUES (:s, :p, :u)', ['s' => $siteId, 'p' => $period, 'u' => $userId]);
        foreach ($balances as $b) {
            $this->db->execute(
                'INSERT INTO stock_period_balances (site_id, period, part_id, qty_open, qty_in, qty_out, qty_close, unit_cost, value_close)
                 VALUES (:s, :p, :part, :open, :in, :out, :close, :cost, :value)',
                ['s' => $siteId, 'p' => $period, 'part' => $b['part_id'], 'open' => $b['qty_open'], 'in' => $b['qty_in'], 'out' => $b['qty_out'],
                    'close' => $b['qty_close'], 'cost' => $b['unit_cost'], 'value' => $b['value_close']]
            );
        }
    }

    /** Documents encore ouverts qui empêchent la clôture : transferts expédiés / reçus et inventaires soumis sur la période. */
    public function openDocuments(int $siteId, string $to): array
    {
        return [
            'transfers' => (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM stock_transfers WHERE status = 'shipped' AND DATE(shipped_at) <= :to AND (from_site_id = :s1 OR to_site_id = :s2)",
                ['to' => $to, 's1' => $siteId, 's2' => $siteId]
            ),
            'inventories' => (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM stock_inventories WHERE status IN ('draft','submitted') AND counted_on <= :to AND site_id = :s",
                ['to' => $to, 's' => $siteId]
            ),
        ];
    }
}
