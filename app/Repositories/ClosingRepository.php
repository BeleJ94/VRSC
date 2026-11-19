<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Clôtures du mois, vues ensemble par site : stock (période), coûts par équipement (validation) et caisses (arrêtés journaliers).
 * Lecture seule : chaque clôture reste faite par son propre service, avec ses contrôles.
 */
final class ClosingRepository extends Repository
{
    /**
     * @return list<array{site_id: int, site: string, stock: array, costs: array, registers: list<array>}>
     */
    public function overview(string $period): array
    {
        $from = $period . '-01';
        $to = date('Y-m-t', (int) strtotime($from));
        [$scopeSql, $params] = $this->scope->clause('s.id');
        $sites = $this->db->fetchAll("SELECT s.id, s.name FROM sites s WHERE s.is_active = 1 AND $scopeSql ORDER BY s.name", $params);

        $out = [];
        foreach ($sites as $site) {
            $siteId = (int) $site['id'];
            $stock = $this->db->fetchOne(
                "SELECT sp.closed_at, CONCAT(u.first_name, ' ', u.last_name) AS closed_by_name
                   FROM stock_periods sp LEFT JOIN users u ON u.id = sp.closed_by WHERE sp.site_id = :s AND sp.period = :p",
                ['s' => $siteId, 'p' => $period]
            );
            $costs = $this->db->fetchOne(
                "SELECT cp.closed_at, cp.note, CONCAT(u.first_name, ' ', u.last_name) AS closed_by_name
                   FROM equipment_cost_periods cp LEFT JOIN users u ON u.id = cp.closed_by WHERE cp.site_id = :s AND cp.period = :p",
                ['s' => $siteId, 'p' => $period]
            );
            $registers = $this->db->fetchAll(
                "SELECT c.id, c.name,
                        (SELECT COUNT(DISTINCT e.entry_date) FROM cash_entries e WHERE e.register_id = c.id AND e.entry_date BETWEEN :f1 AND :t1
                            AND NOT EXISTS (SELECT 1 FROM cash_closings cl WHERE cl.register_id = c.id AND cl.closing_date = e.entry_date)) AS open_days,
                        (SELECT MIN(e.entry_date) FROM cash_entries e WHERE e.register_id = c.id AND e.entry_date <= :t2
                            AND NOT EXISTS (SELECT 1 FROM cash_closings cl WHERE cl.register_id = c.id AND cl.closing_date = e.entry_date)) AS first_open_day,
                        (SELECT MAX(cl.closing_date) FROM cash_closings cl WHERE cl.register_id = c.id) AS last_closing,
                        (SELECT COALESCE(SUM(cl.gap), 0) FROM cash_closings cl WHERE cl.register_id = c.id AND cl.closing_date BETWEEN :f3 AND :t3) AS month_gap
                   FROM cash_registers c WHERE c.site_id = :s AND c.is_active = 1 ORDER BY c.name",
                ['s' => $siteId, 'f1' => $from, 't1' => $to, 't2' => $to, 'f3' => $from, 't3' => $to]
            );
            $out[] = [
                'site_id' => $siteId,
                'site' => $site['name'],
                'stock' => [
                    'closed' => $stock,
                    // Pièces encore ouvertes qui bloquent la clôture du stock.
                    'open_transfers' => (int) $this->db->fetchValue(
                        "SELECT COUNT(*) FROM stock_transfers WHERE status = 'shipped' AND DATE(shipped_at) <= :to AND (from_site_id = :s1 OR to_site_id = :s2)",
                        ['to' => $to, 's1' => $siteId, 's2' => $siteId]
                    ),
                    'open_inventories' => (int) $this->db->fetchValue(
                        "SELECT COUNT(*) FROM stock_inventories WHERE status IN ('draft','submitted') AND counted_on <= :to AND site_id = :s",
                        ['to' => $to, 's' => $siteId]
                    ),
                    'earlier_open' => $this->db->fetchValue(
                        "SELECT MIN(DATE_FORMAT(m.moved_on, '%Y-%m')) FROM stock_movements m
                          WHERE m.site_id = :s AND m.moved_on < :from
                            AND NOT EXISTS (SELECT 1 FROM stock_periods sp WHERE sp.site_id = m.site_id AND sp.period = DATE_FORMAT(m.moved_on, '%Y-%m'))",
                        ['s' => $siteId, 'from' => $from]
                    ),
                ],
                'costs' => ['closed' => $costs],
                'registers' => $registers,
            ];
        }

        return $out;
    }
}
