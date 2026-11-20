<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/**
 * Coût mensuel par équipement (cahier des charges § 8). Les coûts ne sont pas ressaisis : ils sont lus dans leurs
 * pièces sources (sorties de stock imputées à l'engin, ravitaillements en station, main-d'œuvre et prestations
 * des OT, autres charges, prestations reçues sur EDB). Chaque pièce source produit UNE ligne : pas de double
 * imputation. Un montant inconnu vaut NULL (« manquant ») et n'est jamais compté pour zéro en silence.
 */
final class EquipmentCostRepository extends Repository
{
    public const SORTABLE = [
        'code' => 'x.code',
        'site' => 'x.site',
        'category' => 'x.category',
        'usage_value' => 'x.usage_value',
        'fuel' => 'x.fuel',
        'parts' => 'x.parts',
        'lubricants' => 'x.lubricants',
        'tyres_filters' => 'x.tyres_filters',
        'labor' => 'x.labor',
        'external' => 'x.external',
        'other' => 'x.other',
        'total' => 'x.total',
        'unit_cost' => 'x.unit_cost',
        'prev_total' => 'x.prev_total',
        'ytd_total' => 'x.ytd_total',
        'fuel_ratio' => 'x.fuel_ratio',
        // Ravitaillements
        'refill_date' => 'f.refill_date',
        'reference' => 'f.reference',
        'equipment' => 'e.code',
        'litres' => 'f.litres',
        'amount' => 'amount',
    ];

    public const KINDS = ['fuel', 'parts', 'lubricants', 'tyres_filters', 'labor', 'external', 'other'];
    /** Écart toléré autour de la consommation de référence avant alerte. */
    public const FUEL_TOLERANCE = 0.20;

    /** Toutes les lignes de coût, une par pièce source. */
    private function lines(): string
    {
        return "(
            SELECT COALESCE(m.equipment_id, w.equipment_id) AS equipment_id, m.site_id, m.moved_on AS cost_date,
                   CASE p.part_type WHEN 'fuel' THEN 'fuel' WHEN 'lubricant' THEN 'lubricants' WHEN 'tyre' THEN 'tyres_filters'
                                    WHEN 'filter' THEN 'tyres_filters' ELSE 'parts' END AS kind,
                   -m.value AS amount, -m.delta AS quantity, p.unit, CONCAT(p.reference, ' – ', p.name) AS label,
                   'stock_movement' AS source_type, m.id AS source_id, COALESCE(m.document_ref, w.reference) AS document_ref
              FROM stock_movements m
              JOIN parts p ON p.id = m.part_id
              LEFT JOIN work_orders w ON w.id = m.work_order_id
             WHERE m.type = 'out' AND COALESCE(m.equipment_id, w.equipment_id) IS NOT NULL
            UNION ALL
            SELECT f.equipment_id, f.site_id, f.refill_date, 'fuel', ROUND(f.litres * f.unit_price, 2), f.litres, 'L',
                   COALESCE(f.station, 'Station'), 'fuel_refill', f.id, f.reference
              FROM fuel_refills f WHERE f.source = 'external'
            UNION ALL
            SELECT w.equipment_id, w.site_id, DATE(COALESCE(w.completed_at, t.created_at)), 'labor',
                   ROUND(t.minutes / 60 * pe.hourly_rate, 2), ROUND(t.minutes / 60, 2), 'h',
                   CONCAT(pe.first_name, ' ', pe.last_name), 'work_order_technician', t.id, w.reference
              FROM work_order_technicians t
              JOIN work_orders w ON w.id = t.work_order_id AND w.status <> 'cancelled'
              JOIN personnel pe ON pe.id = t.personnel_id
            UNION ALL
            SELECT w.equipment_id, w.site_id, sv.service_date, 'external', sv.amount, 1, 'u', sv.description,
                   'work_order_service', sv.id, COALESCE(sv.document_ref, w.reference)
              FROM work_order_services sv JOIN work_orders w ON w.id = sv.work_order_id
            UNION ALL
            SELECT c.equipment_id, c.site_id, c.charge_date, 'other', c.amount, 1, 'u', c.label, 'equipment_charge', c.id, c.document_ref
              FROM equipment_charges c
            UNION ALL
            SELECT r.equipment_id, r.site_id, DATE(pf.done_at), IF(r.category = 'services', 'external', 'other'), pf.value, pf.quantity, l.unit,
                   l.description, 'purchase_fulfilment', pf.id, pf.document_ref
              FROM purchase_fulfilments pf
              JOIN purchase_request_lines l ON l.id = pf.request_line_id AND l.part_id IS NULL
              JOIN purchase_requests r ON r.id = l.request_id AND r.equipment_id IS NOT NULL
        )";
    }

    /**
     * Rapport mensuel : une ligne par engin, classée par coût. Une période close est lue dans son rapport figé.
     *
     * @param array{site_id?: ?int, category_id?: ?int, flag?: ?string} $filters flag = missing | alert
     */
    public function monthly(DataTableRequest $dt, string $period, array $filters = []): array
    {
        [$source, $params] = $this->monthlySource($period);
        $where = [];
        $fp = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'x.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'x.category_id = :f_cat';
            $fp['f_cat'] = (int) $filters['category_id'];
        }
        if (!empty($filters['project_id'])) {
            $where[] = 'x.project_id = :f_project';
            $fp['f_project'] = (int) $filters['project_id'];
        }
        if (($filters['flag'] ?? '') === 'missing') {
            $where[] = '(x.missing_count > 0 OR x.usage_value IS NULL)';
        } elseif (($filters['flag'] ?? '') === 'alert') {
            $where[] = '(x.fuel_alert IS NOT NULL OR x.meter_alert = 1)';
        }

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.code', 'x.name']);
    }

    /**
     * Coûts imputés aux engins entre deux dates, lus dans les pièces sources (même source que le rapport mensuel),
     * regroupés par mois, par site ou par engin. Un montant inconnu est compté à part (missing), jamais comme zéro.
     *
     * @param 'month'|'site'|'equipment' $group
     */
    public function costsBetween(string $from, string $to, ?int $siteId, string $group, int $limit = 0): array
    {
        [$scopeSql, $params] = $this->scope->clause('c.site_id');
        $key = ['month' => "DATE_FORMAT(c.cost_date, '%Y-%m')", 'site' => 'c.site_id', 'equipment' => 'c.equipment_id'][$group];
        $site = '';
        if ($siteId !== null) {
            $site = ' AND c.site_id = :f_site';
            $params['f_site'] = $siteId;
        }
        $extra = $group === 'equipment' ? ', e.code, e.name, s.name AS site' : '';
        $join = $group === 'equipment' ? ' JOIN equipment e ON e.id = c.equipment_id JOIN sites s ON s.id = e.site_id' : '';
        $groupBy = $group === 'equipment' ? 'c.equipment_id, e.code, e.name, s.name' : 'k';

        return $this->db->fetchAll(
            "SELECT $key AS k, COALESCE(SUM(c.amount), 0) AS total, SUM(c.amount IS NULL) AS missing, SUM(IF(c.kind = 'fuel', COALESCE(c.amount, 0), 0)) AS fuel$extra
               FROM " . $this->lines() . " c$join
              WHERE c.cost_date BETWEEN :f AND :t AND $scopeSql$site
              GROUP BY $groupBy ORDER BY " . ($group === 'equipment' ? 'total DESC' : 'k') . ($limit > 0 ? ' LIMIT ' . $limit : ''),
            ['f' => $from, 't' => $to] + $params
        );
    }

    /** Totaux du rapport d'une période (dans le périmètre, site éventuel). */
    public function monthlyTotals(string $period, ?int $siteId = null): array
    {
        [$source, $params] = $this->monthlySource($period);
        $where = '1=1';
        if ($siteId !== null) {
            $where = 'x.site_id = :f_site';
            $params['f_site'] = $siteId;
        }
        $sums = implode(', ', array_map(static fn (string $k): string => "COALESCE(SUM(x.$k), 0) AS $k", array_merge(self::KINDS, ['total', 'prev_total', 'ytd_total', 'litres'])));

        return $this->db->fetchOne(
            "SELECT $sums, COALESCE(SUM(x.missing_count), 0) AS missing_count, COALESCE(SUM(x.missing_count > 0 OR x.usage_value IS NULL), 0) AS incomplete_count,
                    COALESCE(SUM(x.fuel_alert IS NOT NULL OR x.meter_alert = 1), 0) AS alert_count, COUNT(*) AS equipment_count
               FROM $source WHERE $where",
            $params
        ) ?? [];
    }

    /** Ligne du rapport pour un engin (null hors périmètre). */
    public function monthlyRow(int $equipmentId, string $period): ?array
    {
        [$source, $params] = $this->monthlySource($period);

        return $this->db->fetchOne("SELECT x.* FROM $source WHERE x.id = :eq", $params + ['eq' => $equipmentId]);
    }

    /** @return array{0: string, 1: array<string, int>} */
    private function monthlySource(string $period): array
    {
        $pdo = $this->db->pdo();
        $from = $period . '-01';
        $to = date('Y-m-t', (int) strtotime($from));
        $prevFrom = date('Y-m-01', (int) strtotime($from . ' -1 month'));
        $prevTo = date('Y-m-t', (int) strtotime($prevFrom));
        $yearFrom = substr($period, 0, 4) . '-01-01';
        [$f, $t, $pf, $pt, $yf, $p] = array_map(static fn (string $v): string => $pdo->quote($v), [$from, $to, $prevFrom, $prevTo, $yearFrom, $period]);
        $tEnd = $pdo->quote($to . ' 23:59:59');
        $fStart = $pdo->quote($from . ' 00:00:00');
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $in = "c.cost_date BETWEEN $f AND $t";
        $kinds = implode(",\n", array_map(static fn (string $k): string => "SUM(IF($in AND c.kind = '$k', COALESCE(c.amount, 0), 0)) AS $k", self::KINDS));
        // Compteur au début de la période (dernier relevé antérieur, sinon premier du mois) et à la fin (dernier relevé ≤ fin).
        $meter = static fn (string $col, bool $start): string => $start
            ? "COALESCE((SELECT mr.$col FROM meter_readings mr WHERE mr.equipment_id = e.id AND mr.$col IS NOT NULL AND mr.recorded_at < $fStart ORDER BY mr.recorded_at DESC, mr.id DESC LIMIT 1),
                        (SELECT mr.$col FROM meter_readings mr WHERE mr.equipment_id = e.id AND mr.$col IS NOT NULL AND mr.recorded_at BETWEEN $fStart AND $tEnd ORDER BY mr.recorded_at, mr.id LIMIT 1))"
            : "(SELECT mr.$col FROM meter_readings mr WHERE mr.equipment_id = e.id AND mr.$col IS NOT NULL AND mr.recorded_at BETWEEN $fStart AND $tEnd ORDER BY mr.recorded_at DESC, mr.id DESC LIMIT 1)";
        $pick = static fn (string $col): string => "IF(sn.equipment_id IS NOT NULL, sn.$col, COALESCE(agg.$col, 0)) AS $col";
        $tol = self::FUEL_TOLERANCE;

        $sql = "(SELECT z.*,
                        CASE WHEN z.usage_value > 0 THEN ROUND(z.total / z.usage_value, 2) END AS unit_cost,
                        z.total - z.prev_total AS variation,
                        CASE WHEN z.fuel_ratio IS NULL OR z.fuel_reference IS NULL THEN NULL
                             WHEN z.fuel_ratio > z.fuel_reference * (1 + $tol) THEN 'over'
                             WHEN z.fuel_ratio < z.fuel_reference * (1 - $tol) THEN 'under' END AS fuel_alert
                   FROM (
                    SELECT y.*,
                           CASE WHEN y.usage_value > 0 AND y.litres > 0 THEN ROUND(IF(y.meter_basis = 'km', y.litres / y.usage_value * 100, y.litres / y.usage_value), 2) END AS fuel_ratio,
                           CASE WHEN y.usage_value < 0 OR (y.litres > 0 AND COALESCE(y.usage_value, 0) = 0) OR y.refill_alerts > 0 THEN 1 ELSE 0 END AS meter_alert
                      FROM (
                        SELECT w.*, CASE WHEN w.meter_basis = 'km' THEN w.km_end - w.km_start ELSE w.hours_end - w.hours_start END AS usage_value
                          FROM (
                            SELECT e.id, e.code, e.name, e.site_id, s.name AS site, e.category_id, cat.name AS category,
                                   e.project_id, (SELECT pj.code FROM projects pj WHERE pj.id = e.project_id) AS project_code,
                                   COALESCE(e.meter_basis, cat.meter_basis, 'hours') AS meter_basis,
                                   COALESCE(e.fuel_reference, mo.fuel_reference) AS fuel_reference,
                                   sn.equipment_id IS NOT NULL AS is_closed,
                                   " . implode(', ', array_map($pick, array_merge(self::KINDS, ['total', 'missing_count', 'litres']))) . ",
                                   COALESCE(agg.prev_total, 0) AS prev_total, COALESCE(agg.ytd_total, 0) AS ytd_total,
                                   IF(sn.equipment_id IS NOT NULL, sn.hours_start, " . $meter('hour_meter', true) . ") AS hours_start,
                                   IF(sn.equipment_id IS NOT NULL, sn.hours_end, " . $meter('hour_meter', false) . ") AS hours_end,
                                   IF(sn.equipment_id IS NOT NULL, sn.km_start, " . $meter('odometer_km', true) . ") AS km_start,
                                   IF(sn.equipment_id IS NOT NULL, sn.km_end, " . $meter('odometer_km', false) . ") AS km_end,
                                   (SELECT COUNT(*) FROM fuel_refills fr WHERE fr.equipment_id = e.id AND fr.refill_date BETWEEN $f AND $t AND fr.meter_alert IS NOT NULL) AS refill_alerts,
                                   (SELECT n.note FROM equipment_cost_notes n WHERE n.equipment_id = e.id AND n.period = $p) AS observation
                              FROM equipment e
                              JOIN sites s ON s.id = e.site_id
                              LEFT JOIN equipment_categories cat ON cat.id = e.category_id
                              LEFT JOIN equipment_models mo ON mo.id = e.model_id
                              LEFT JOIN equipment_cost_snapshots sn ON sn.equipment_id = e.id AND sn.period = $p
                              LEFT JOIN (
                                SELECT c.equipment_id,
                                       $kinds,
                                       SUM(IF($in, COALESCE(c.amount, 0), 0)) AS total,
                                       SUM($in AND c.amount IS NULL) AS missing_count,
                                       SUM(IF($in AND c.kind = 'fuel', c.quantity, 0)) AS litres,
                                       SUM(IF(c.cost_date BETWEEN $pf AND $pt, COALESCE(c.amount, 0), 0)) AS prev_total,
                                       SUM(IF(c.cost_date BETWEEN $yf AND $t, COALESCE(c.amount, 0), 0)) AS ytd_total
                                  FROM " . $this->lines() . " c
                                 WHERE c.cost_date BETWEEN LEAST($yf, $pf) AND $t
                                 GROUP BY c.equipment_id
                              ) agg ON agg.equipment_id = e.id
                             WHERE e.is_archived = 0 AND $scopeSql
                          ) w
                      ) y
                   ) z
                ) x";

        return [$sql, $params];
    }

    /** Détail des coûts d'un engin sur une période : chaque ligne avec sa pièce source. */
    public function detail(int $equipmentId, string $from, string $to): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, s.name AS site FROM ' . $this->lines() . ' c JOIN sites s ON s.id = c.site_id
              WHERE c.equipment_id = :e AND c.cost_date BETWEEN :f AND :t ORDER BY c.cost_date, c.kind, c.source_id',
            ['e' => $equipmentId, 'f' => $from, 't' => $to]
        );
    }

    /** Total connu par mois sur les $months derniers mois (évolution). */
    public function history(int $equipmentId, string $period, int $months = 12): array
    {
        $from = date('Y-m-01', (int) strtotime($period . '-01 -' . ($months - 1) . ' month'));
        $to = date('Y-m-t', (int) strtotime($period . '-01'));
        $rows = array_column($this->db->fetchAll(
            "SELECT DATE_FORMAT(c.cost_date, '%Y-%m') AS period, SUM(COALESCE(c.amount, 0)) AS total, SUM(c.amount IS NULL) AS missing_count,
                    SUM(IF(c.kind = 'fuel', c.quantity, 0)) AS litres
               FROM " . $this->lines() . ' c WHERE c.equipment_id = :e AND c.cost_date BETWEEN :f AND :t GROUP BY period',
            ['e' => $equipmentId, 'f' => $from, 't' => $to]
        ), null, 'period');
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = date('Y-m', (int) strtotime($period . "-01 -$i month"));
            $out[] = ['period' => $month, 'total' => (float) ($rows[$month]['total'] ?? 0), 'missing_count' => (int) ($rows[$month]['missing_count'] ?? 0),
                'litres' => (float) ($rows[$month]['litres'] ?? 0)];
        }

        return $out;
    }

    // --- Clôture -------------------------------------------------------------------

    public function isClosed(int $siteId, string $period): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM equipment_cost_periods WHERE site_id = :s AND period = :p', ['s' => $siteId, 'p' => $period]);
    }

    public function closing(int $siteId, string $period): ?array
    {
        return $this->db->fetchOne(
            "SELECT cp.*, CONCAT(u.first_name, ' ', u.last_name) AS closed_by_name FROM equipment_cost_periods cp LEFT JOIN users u ON u.id = cp.closed_by
              WHERE cp.site_id = :s AND cp.period = :p",
            ['s' => $siteId, 'p' => $period]
        );
    }

    /** Fige le rapport des engins du site pour la période. */
    public function close(int $siteId, string $period, ?string $note, ?int $userId): int
    {
        $this->scope->assertAllows($siteId);
        [$source, $params] = $this->monthlySource($period);
        $rows = $this->db->fetchAll("SELECT x.* FROM $source WHERE x.site_id = :f_site", $params + ['f_site' => $siteId]);
        $this->db->execute('INSERT INTO equipment_cost_periods (site_id, period, note, closed_by) VALUES (:s, :p, :n, :u)', ['s' => $siteId, 'p' => $period, 'n' => $note, 'u' => $userId]);
        foreach ($rows as $row) {
            $this->db->execute(
                'INSERT INTO equipment_cost_snapshots (site_id, period, equipment_id, fuel, parts, lubricants, tyres_filters, labor, external, other, total,
                                                       missing_count, litres, hours_start, hours_end, km_start, km_end)
                 VALUES (:s, :p, :e, :fuel, :parts, :lubricants, :tyres_filters, :labor, :external, :other, :total, :missing_count, :litres,
                         :hours_start, :hours_end, :km_start, :km_end)',
                ['s' => $siteId, 'p' => $period, 'e' => $row['id']] + array_intersect_key($row, array_flip(array_merge(self::KINDS,
                    ['total', 'missing_count', 'litres', 'hours_start', 'hours_end', 'km_start', 'km_end'])))
            );
        }

        return count($rows);
    }

    public function saveNote(int $equipmentId, string $period, string $note, ?int $userId): void
    {
        $this->db->execute(
            'INSERT INTO equipment_cost_notes (equipment_id, period, note, updated_by) VALUES (:e, :p, :n, :u)
             ON DUPLICATE KEY UPDATE note = VALUES(note), updated_by = VALUES(updated_by)',
            ['e' => $equipmentId, 'p' => $period, 'n' => $note, 'u' => $userId]
        );
    }

    // --- Ravitaillements ---------------------------------------------------------------

    /** @param array{site_id?: ?int, equipment_id?: ?int, from?: ?string, to?: ?string} $filters */
    public function refills(DataTableRequest $dt, array $filters = []): array
    {
        [$scopeSql, $params] = $this->scope->clause('f.site_id');
        $where = [];
        $fp = [];
        foreach (['site_id' => 'f.site_id', 'equipment_id' => 'f.equipment_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "$column = :f_$key";
                $fp["f_$key"] = (int) $filters[$key];
            }
        }
        if (!empty($filters['from'])) {
            $where[] = 'f.refill_date >= :f_from';
            $fp['f_from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'f.refill_date <= :f_to';
            $fp['f_to'] = $filters['to'];
        }

        return $this->paginate(
            $dt,
            "f.id, f.reference, f.refill_date, f.litres, f.source, f.station, f.voucher_ref, f.hour_meter, f.odometer_km, f.meter_alert, f.note,
             e.id AS equipment_id, e.code AS equipment, e.name AS equipment_name, s.name AS site,
             CASE WHEN f.source = 'stock' THEN -m.value ELSE ROUND(f.litres * f.unit_price, 2) END AS amount,
             CASE WHEN f.source = 'stock' THEN m.unit_cost ELSE f.unit_price END AS unit_price,
             CONCAT(u.first_name, ' ', u.last_name) AS user",
            'fuel_refills f
             JOIN equipment e ON e.id = f.equipment_id
             JOIN sites s ON s.id = f.site_id
             LEFT JOIN stock_movements m ON m.id = f.stock_movement_id
             LEFT JOIN users u ON u.id = f.recorded_by',
            [$scopeSql],
            $params,
            $where,
            $fp,
            ['f.reference', 'e.code', 'e.name', 'f.voucher_ref', 'f.station'],
        );
    }

    public function createRefill(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);
        $columns = array_keys($data);

        return $this->db->insert(
            'INSERT INTO fuel_refills (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => ":$c", $columns)) . ')',
            $data
        );
    }
}
