<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Indicateurs des tableaux de bord Exécutif et Finance : dépenses, coûts de flotte, stock, exécution des EDB,
 * comparaison des sites, alertes. Toujours limités aux sites du périmètre (et au site choisi).
 */
final class ExecutiveDashboardRepository extends Repository
{
    public const PERIODS = ['month', 'last_month', 'quarter', 'year'];

    /**
     * Bornes d'une période et de la période précédente comparable.
     *
     * @return array{key: string, from: string, to: string, prev_from: string, prev_to: string, months: list<string>}
     */
    public static function period(string $key, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $first = date('Y-m-01', (int) strtotime($today));
        [$from, $to, $prevFrom, $prevTo] = match ($key) {
            'last_month' => [date('Y-m-01', (int) strtotime("$first -1 month")), date('Y-m-t', (int) strtotime("$first -1 month")),
                date('Y-m-01', (int) strtotime("$first -2 months")), date('Y-m-t', (int) strtotime("$first -2 months"))],
            'quarter' => (static function () use ($today): array {
                $q = intdiv((int) date('n', (int) strtotime($today)) - 1, 3);
                $from = date('Y', (int) strtotime($today)) . '-' . sprintf('%02d', $q * 3 + 1) . '-01';
                $prevFrom = date('Y-m-01', (int) strtotime("$from -3 months"));

                return [$from, $today, $prevFrom, date('Y-m-d', (int) strtotime("$prevFrom +" . ((strtotime($today) - strtotime($from)) / 86400) . ' days'))];
            })(),
            'year' => [date('Y-01-01', (int) strtotime($today)), $today, date('Y-01-01', (int) strtotime("$today -1 year")), date('Y-m-d', (int) strtotime("$today -1 year"))],
            // Mois en cours, comparé au même nombre de jours du mois précédent.
            default => [$first, $today, date('Y-m-01', (int) strtotime("$first -1 month")),
                min(date('Y-m-t', (int) strtotime("$first -1 month")), date('Y-m-d', (int) strtotime("$today -1 month")))],
        };
        $months = [];
        for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', (int) strtotime("$m-01 +1 month"))) {
            $months[] = $m;
        }

        return ['key' => in_array($key, self::PERIODS, true) ? $key : 'month', 'from' => $from, 'to' => $to, 'prev_from' => $prevFrom, 'prev_to' => $prevTo, 'months' => $months];
    }

    /** Les 6 derniers mois jusqu'à la fin de la période (séries des mini-graphiques). @return list<string> */
    public static function trendMonths(string $to, int $count = 6): array
    {
        $months = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $months[] = date('Y-m', (int) strtotime(substr($to, 0, 7) . "-01 -$i months"));
        }

        return $months;
    }

    /** @return array{0: string, 1: array} condition « dans le périmètre et le site choisi » sur une colonne de site */
    private function sites(string $column, ?int $siteId, string $prefix): array
    {
        [$sql, $params] = $this->scope->clause($column, $prefix);
        if ($siteId !== null) {
            $sql .= " AND $column = :{$prefix}_site";
            $params[$prefix . '_site'] = $siteId;
        }

        return [$sql, $params];
    }

    /** Montant payé (décaissements payés) par mois. @return array<string, float> */
    public function paidByMonth(string $from, string $to, ?int $siteId): array
    {
        [$s, $p] = $this->sites('p.site_id', $siteId, 'pd');

        return $this->byMonth("SELECT DATE_FORMAT(p.paid_at, '%Y-%m') AS m, SUM(p.amount) AS v FROM payment_requests p
                                WHERE p.status = 'paid' AND p.paid_at BETWEEN :f AND :t AND $s GROUP BY m", $from, $to, $p);
    }

    /** Engagé par mois : commandes émises + dépenses directes et avances approuvées. @return array<string, float> */
    public function engagedByMonth(string $from, string $to, ?int $siteId): array
    {
        [$so, $po] = $this->sites('o.site_id', $siteId, 'eo');
        [$sp, $pp] = $this->sites('p.site_id', $siteId, 'ep');

        return $this->byMonth(
            "SELECT m, SUM(v) AS v FROM (
                SELECT DATE_FORMAT(o.ordered_at, '%Y-%m') AS m, o.total_amount AS v FROM purchase_orders o
                 WHERE o.status <> 'cancelled' AND o.ordered_at BETWEEN :f AND :t AND $so
                UNION ALL
                SELECT DATE_FORMAT(p.approved_at, '%Y-%m'), p.amount FROM payment_requests p
                 WHERE p.kind <> 'invoice' AND p.status IN ('approved', 'paid') AND p.approved_at BETWEEN :f2 AND :t2 AND $sp
             ) z GROUP BY m",
            $from, $to, $po + $pp, true
        );
    }

    /** Factures fournisseurs enregistrées par mois. @return array<string, float> */
    public function invoicedByMonth(string $from, string $to, ?int $siteId): array
    {
        [$s, $p] = $this->sites('i.site_id', $siteId, 'iv');

        return $this->byMonth("SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS m, SUM(i.amount) AS v FROM supplier_invoices i
                                WHERE i.status = 'recorded' AND i.invoice_date BETWEEN :f AND :t AND $s GROUP BY m", $from, $to, $p);
    }

    /** @return array<string, float> mois → valeur */
    private function byMonth(string $sql, string $from, string $to, array $params, bool $twice = false): array
    {
        $bounds = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'] + ($twice ? ['f2' => $from . ' 00:00:00', 't2' => $to . ' 23:59:59'] : []);

        return array_map('floatval', array_column($this->db->fetchAll($sql, $bounds + $params), 'v', 'm'));
    }

    /** Somme d'une série mensuelle sur une période (bornes en jours pour la période partielle). */
    public static function sum(array $byMonth): float
    {
        return round(array_sum($byMonth), 2);
    }

    /** Valeur du stock au coût moyen, par site. @return array<int, float> */
    public function stockValueBySite(?int $siteId): array
    {
        [$s, $p] = $this->sites('ps.site_id', $siteId, 'sv');

        return array_map('floatval', array_column($this->db->fetchAll(
            "SELECT ps.site_id, SUM(ps.quantity * COALESCE(ps.avg_cost, 0)) AS v FROM part_stocks ps WHERE ps.quantity > 0 AND $s GROUP BY ps.site_id", $p
        ), 'v', 'site_id'));
    }

    /** Parc par site : total, disponibles, hors service, à valider. @return array<int, array> */
    public function fleetBySite(?int $siteId): array
    {
        [$s, $p] = $this->sites('e.site_id', $siteId, 'fl');

        return array_column($this->db->fetchAll(
            "SELECT e.site_id, COUNT(*) AS total, SUM(e.status = 'available') AS available, SUM(e.status = 'out_of_service') AS out_of_service,
                    SUM(e.status = 'pending_release') AS pending_release, SUM(e.status IN ('breakdown', 'maintenance', 'waiting_part', 'immobilized')) AS down
               FROM equipment e WHERE e.is_archived = 0 AND $s GROUP BY e.site_id",
            $p
        ), null, 'site_id');
    }

    /** États de besoins émis sur la période : total, exécutés, refusés / annulés, en cours. */
    public function edbExecution(string $from, string $to, ?int $siteId): array
    {
        [$s, $p] = $this->sites('r.site_id', $siteId, 'ed');

        return $this->db->fetchOne(
            "SELECT COUNT(*) AS issued, COALESCE(SUM(r.status = 'executed'), 0) AS executed, COALESCE(SUM(r.status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(r.status IN ('pending', 'approved')), 0) AS open
               FROM purchase_requests r WHERE r.requested_at BETWEEN :f AND :t AND $s",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'] + $p
        ) ?? ['issued' => 0, 'executed' => 0, 'cancelled' => 0, 'open' => 0];
    }

    /** Dépenses payées et engagées par site sur la période. @return array<int, array{paid: float, engaged: float}> */
    public function spendBySite(string $from, string $to, ?int $siteId): array
    {
        [$so, $po] = $this->sites('o.site_id', $siteId, 'bo');
        [$sp, $pp] = $this->sites('p.site_id', $siteId, 'bp');
        $rows = $this->db->fetchAll(
            "SELECT site_id, SUM(paid) AS paid, SUM(engaged) AS engaged FROM (
                SELECT p.site_id, IF(p.status = 'paid' AND p.paid_at BETWEEN :f AND :t, p.amount, 0) AS paid,
                       IF(p.kind <> 'invoice' AND p.status IN ('approved', 'paid') AND p.approved_at BETWEEN :f2 AND :t2, p.amount, 0) AS engaged
                  FROM payment_requests p WHERE $sp
                UNION ALL
                SELECT o.site_id, 0, o.total_amount FROM purchase_orders o WHERE o.status <> 'cancelled' AND o.ordered_at BETWEEN :f3 AND :t3 AND $so
             ) z GROUP BY site_id",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59', 'f2' => $from . ' 00:00:00', 't2' => $to . ' 23:59:59', 'f3' => $from . ' 00:00:00', 't3' => $to . ' 23:59:59'] + $po + $pp
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['site_id']] = ['paid' => (float) $row['paid'], 'engaged' => (float) $row['engaged']];
        }

        return $out;
    }

    /** Dépenses par catégorie (engagé, payé) sur la période. */
    public function spendByCategory(string $from, string $to, ?int $siteId): array
    {
        [$so, $po] = $this->sites('o.site_id', $siteId, 'co');
        [$sp, $pp] = $this->sites('p.site_id', $siteId, 'cp');

        return $this->db->fetchAll(
            "SELECT category, SUM(paid) AS paid, SUM(engaged) AS engaged FROM (
                SELECT COALESCE(p.category, r.category, 'other') AS category,
                       IF(p.status = 'paid' AND p.paid_at BETWEEN :f AND :t, p.amount, 0) AS paid,
                       IF(p.kind <> 'invoice' AND p.status IN ('approved', 'paid') AND p.approved_at BETWEEN :f2 AND :t2, p.amount, 0) AS engaged
                  FROM payment_requests p
                  LEFT JOIN supplier_invoices i ON i.id = p.invoice_id
                  LEFT JOIN purchase_orders o2 ON o2.id = COALESCE(p.order_id, i.order_id)
                  LEFT JOIN purchase_requests r ON r.id = o2.request_id
                 WHERE $sp
                UNION ALL
                SELECT r.category, 0, o.total_amount FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
                 WHERE o.status <> 'cancelled' AND o.ordered_at BETWEEN :f3 AND :t3 AND $so
             ) z GROUP BY category HAVING SUM(paid) + SUM(engaged) > 0 ORDER BY SUM(engaged) DESC",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59', 'f2' => $from . ' 00:00:00', 't2' => $to . ' 23:59:59', 'f3' => $from . ' 00:00:00', 't3' => $to . ' 23:59:59'] + $po + $pp
        );
    }

    /** Factures restant à payer (montant, nombre, dont échues). */
    public function unpaidInvoices(?int $siteId): array
    {
        [$s, $p] = $this->sites('i.site_id', $siteId, 'up');

        return $this->db->fetchOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(x.unpaid), 0) AS amount, COALESCE(SUM(x.overdue), 0) AS overdue_count, COALESCE(SUM(IF(x.overdue, x.unpaid, 0)), 0) AS overdue_amount FROM (
                SELECT i.amount - COALESCE((SELECT SUM(pr.amount) FROM payment_requests pr WHERE pr.invoice_id = i.id AND pr.status = 'paid'), 0) AS unpaid,
                       (i.due_date IS NOT NULL AND i.due_date < CURDATE()) AS overdue
                  FROM supplier_invoices i WHERE i.status = 'recorded' AND $s
             ) x WHERE x.unpaid > 0.005",
            $p
        ) ?? ['count' => 0, 'amount' => 0, 'overdue_count' => 0, 'overdue_amount' => 0];
    }

    /** Décaissements en circuit : à vérifier / approuver, approuvés à payer, avances à justifier. */
    public function paymentPipeline(?int $siteId): array
    {
        [$s, $p] = $this->sites('p.site_id', $siteId, 'pp');

        return $this->db->fetchOne(
            "SELECT COALESCE(SUM(p.status = 'pending'), 0) AS pending_count, COALESCE(SUM(IF(p.status = 'pending', p.amount, 0)), 0) AS pending_amount,
                    COALESCE(SUM(p.status = 'approved'), 0) AS to_pay_count, COALESCE(SUM(IF(p.status = 'approved', p.amount, 0)), 0) AS to_pay_amount,
                    COALESCE(SUM(IF(p.kind = 'advance' AND p.status = 'paid', p.amount - p.justified_amount - p.returned_amount, 0)), 0) AS advances
               FROM payment_requests p WHERE $s",
            $p
        ) ?? [];
    }

    /** Caisses : solde, journées non arrêtées avant aujourd'hui. */
    public function registers(?int $siteId): array
    {
        [$s, $p] = $this->sites('c.site_id', $siteId, 'rg');

        return $this->db->fetchAll(
            "SELECT c.id, c.name, c.site_id, s.name AS site, c.balance,
                    (SELECT COUNT(DISTINCT e.entry_date) FROM cash_entries e WHERE e.register_id = c.id AND e.entry_date < CURDATE()
                        AND NOT EXISTS (SELECT 1 FROM cash_closings cl WHERE cl.register_id = c.id AND cl.closing_date = e.entry_date)) AS open_days
               FROM cash_registers c JOIN sites s ON s.id = c.site_id WHERE c.is_active = 1 AND $s ORDER BY s.name, c.name",
            $p
        );
    }

    /** Clôtures du dernier mois terminé : nombre de clôtures faites sur le nombre attendu (stock + coûts par site). */
    public function closingStatus(?int $siteId): array
    {
        $period = date('Y-m', (int) strtotime(date('Y-m-01') . ' -1 month'));
        [$s, $p] = $this->sites('s.id', $siteId, 'cl');
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS sites,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM stock_periods sp WHERE sp.site_id = s.id AND sp.period = :p1)), 0) AS stock_done,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM equipment_cost_periods cp WHERE cp.site_id = s.id AND cp.period = :p2)), 0) AS costs_done
               FROM sites s WHERE s.is_active = 1 AND $s",
            ['p1' => $period, 'p2' => $period] + $p
        ) ?? [];

        return ['period' => $period, 'sites' => (int) ($row['sites'] ?? 0), 'done' => (int) ($row['stock_done'] ?? 0) + (int) ($row['costs_done'] ?? 0),
            'expected' => 2 * (int) ($row['sites'] ?? 0)];
    }

    /** Documents d'engins échus (en vigueur, hors renouvellement en cours). */
    public function expiredDocuments(?int $siteId): int
    {
        [$s, $p] = $this->sites('e.site_id', $siteId, 'dc');

        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM equipment_documents d JOIN equipment e ON e.id = d.equipment_id AND e.is_archived = 0
               JOIN document_types t ON t.id = d.document_type_id AND t.is_active = 1
              WHERE d.expires_at < CURDATE() AND d.renewal_started_at IS NULL AND " . EquipmentDocumentRepository::CURRENT . " AND $s",
            $p
        );
    }

    /** Valeur des mouvements de stock par mois : sorties (out) ou entrées (réceptions, retours, transferts reçus). @return array<string, float> */
    public function movementsByMonth(string $direction, string $from, string $to, ?int $siteId): array
    {
        [$s, $p] = $this->sites('m.site_id', $siteId, 'mv');
        $types = $direction === 'out' ? "('out')" : "('receipt', 'return', 'transfer_in')";

        return array_map('floatval', array_column($this->db->fetchAll(
            "SELECT DATE_FORMAT(m.moved_on, '%Y-%m') AS m, SUM(ABS(COALESCE(m.value, 0))) AS v FROM stock_movements m
              WHERE m.type IN $types AND m.moved_on BETWEEN :f AND :t AND $s GROUP BY m",
            ['f' => $from, 't' => $to] + $p
        ), 'v', 'm'));
    }

    /** Articles sous le seuil minimum (stock positif) et en rupture (stock nul avec un seuil). */
    public function stockLevels(?int $siteId): array
    {
        [$s, $p] = $this->sites('ps.site_id', $siteId, 'lv');

        return $this->db->fetchOne(
            "SELECT COALESCE(SUM(ps.min_quantity > 0 AND ps.quantity > 0 AND ps.quantity < ps.min_quantity), 0) AS low,
                    COALESCE(SUM(ps.min_quantity > 0 AND ps.quantity <= 0), 0) AS out_of_stock
               FROM part_stocks ps JOIN parts pt ON pt.id = ps.part_id AND pt.is_active = 1 WHERE $s",
            $p
        ) ?? ['low' => 0, 'out_of_stock' => 0];
    }

    /** Cuves de carburant par site : stock, consommation moyenne sur 30 jours, autonomie en jours. */
    public function fuelTanks(?int $siteId): array
    {
        [$s, $p] = $this->sites('ps.site_id', $siteId, 'ft');

        return $this->db->fetchAll(
            "SELECT ps.site_id, s.name AS site, pt.id AS part_id, pt.reference, ps.quantity, ps.min_quantity, pt.unit,
                    (SELECT COALESCE(SUM(-m.delta), 0) / 30 FROM stock_movements m
                      WHERE m.part_id = ps.part_id AND m.site_id = ps.site_id AND m.type = 'out' AND m.moved_on >= CURDATE() - INTERVAL 30 DAY) AS daily
               FROM part_stocks ps JOIN parts pt ON pt.id = ps.part_id AND pt.part_type = 'fuel' AND pt.is_active = 1 JOIN sites s ON s.id = ps.site_id
              WHERE $s ORDER BY s.name",
            $p
        );
    }

    /** États de besoins approuvés restant à servir, du plus ancien au plus récent. */
    public function edbToServe(?int $siteId, int $limit = 6): array
    {
        [$s, $p] = $this->sites('r.site_id', $siteId, 'es');
        $rows = $this->db->fetchAll(
            "SELECT r.id, r.reference, r.subject, r.urgency, r.approved_at, s.name AS site, DATEDIFF(CURDATE(), DATE(r.approved_at)) AS age
               FROM purchase_requests r JOIN sites s ON s.id = r.site_id WHERE r.status = 'approved' AND $s
              ORDER BY FIELD(r.urgency, 'critical', 'urgent', 'normal'), r.approved_at LIMIT " . max(1, $limit),
            $p
        );
        $count = (int) $this->db->fetchValue("SELECT COUNT(*) FROM purchase_requests r WHERE r.status = 'approved' AND $s", $p);

        return ['count' => $count, 'rows' => $rows];
    }

    /** Commandes fournisseurs non soldées : nombre, montant, en retard, attendues sous 7 jours. */
    public function openOrders(?int $siteId, int $limit = 6): array
    {
        [$s, $p] = $this->sites('o.site_id', $siteId, 'oo');
        $summary = $this->db->fetchOne(
            "SELECT COUNT(*) AS count, COALESCE(SUM(o.total_amount), 0) AS amount, COALESCE(SUM(o.expected_on < CURDATE()), 0) AS late
               FROM purchase_orders o WHERE o.status IN ('issued', 'partially_received') AND $s",
            $p
        ) ?? [];
        $rows = $this->db->fetchAll(
            "SELECT o.id, o.reference, o.expected_on, o.total_amount, o.status, sp.name AS supplier, s.name AS site, DATEDIFF(o.expected_on, CURDATE()) AS days
               FROM purchase_orders o JOIN suppliers sp ON sp.id = o.supplier_id JOIN sites s ON s.id = o.site_id
              WHERE o.status IN ('issued', 'partially_received') AND $s ORDER BY o.expected_on IS NULL, o.expected_on LIMIT " . max(1, $limit),
            $p
        );

        return ['count' => (int) ($summary['count'] ?? 0), 'amount' => (float) ($summary['amount'] ?? 0), 'late' => (int) ($summary['late'] ?? 0), 'rows' => $rows];
    }

    /** Transferts de stock en cours touchant un site du périmètre (à autoriser, à expédier, en transit). */
    public function openTransfers(?int $siteId, int $limit = 6): array
    {
        [$sf, $pf] = $this->sites('t.from_site_id', $siteId, 'tf');
        [$st, $pt] = $this->sites('t.to_site_id', $siteId, 'tt');

        return $this->db->fetchAll(
            "SELECT t.id, t.reference, t.status, t.requested_at, t.shipped_at, sf.name AS from_site, st.name AS to_site,
                    (SELECT COUNT(*) FROM stock_transfer_lines l WHERE l.transfer_id = t.id) AS line_count
               FROM stock_transfers t JOIN sites sf ON sf.id = t.from_site_id JOIN sites st ON st.id = t.to_site_id
              WHERE t.status IN ('requested', 'authorized', 'shipped') AND (($sf) OR ($st))
              ORDER BY FIELD(t.status, 'shipped', 'authorized', 'requested'), t.requested_at LIMIT " . max(1, $limit),
            $pf + $pt
        );
    }

    /** États de besoins émis par mois (nombre). @return array<string, float> */
    public function edbIssuedByMonth(string $from, string $to, ?int $siteId): array
    {
        [$s, $p] = $this->sites('r.site_id', $siteId, 'ei');

        return array_map('floatval', array_column($this->db->fetchAll(
            "SELECT DATE_FORMAT(r.requested_at, '%Y-%m') AS m, COUNT(*) AS v FROM purchase_requests r
              WHERE r.requested_at BETWEEN :f AND :t AND $s GROUP BY m",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'] + $p
        ), 'v', 'm'));
    }

    /** Répartition des états de besoins par statut (tous, ou émis sur la période). @return array<string, int> */
    public function edbByStatus(?int $siteId, ?string $from = null, ?string $to = null): array
    {
        [$s, $p] = $this->sites('r.site_id', $siteId, 'eb');
        $period = '';
        if ($from !== null && $to !== null) {
            $period = ' AND r.requested_at BETWEEN :f AND :t';
            $p += ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];
        }

        return array_map('intval', array_column($this->db->fetchAll("SELECT r.status, COUNT(*) AS n FROM purchase_requests r WHERE $s$period GROUP BY r.status", $p), 'n', 'status'))
            + ['pending' => 0, 'approved' => 0, 'executed' => 0, 'cancelled' => 0];
    }

    /** États de besoins approuvés sans aucune commande ni service depuis le stock : à acheter. */
    public function edbToBuy(?int $siteId): int
    {
        [$s, $p] = $this->sites('r.site_id', $siteId, 'eu');

        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM purchase_requests r WHERE r.status = 'approved' AND $s
                AND NOT EXISTS (SELECT 1 FROM purchase_orders o WHERE o.request_id = r.id AND o.status <> 'cancelled')",
            $p
        );
    }

    /** Délai moyen (jours) entre l'émission de l'EDB et la commande, pour les commandes passées sur la période. */
    public function leadDays(string $from, string $to, ?int $siteId): ?float
    {
        [$s, $p] = $this->sites('o.site_id', $siteId, 'ld');
        $v = $this->db->fetchValue(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, r.requested_at, o.ordered_at)) / 24 FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
              WHERE o.status <> 'cancelled' AND o.ordered_at BETWEEN :f AND :t AND $s",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'] + $p
        );

        return $v !== null ? round((float) $v, 1) : null;
    }

    /** Délai moyen EDB → commande par mois. @return array<string, float> */
    public function leadDaysByMonth(string $from, string $to, ?int $siteId): array
    {
        [$s, $p] = $this->sites('o.site_id', $siteId, 'lm');

        return array_map(static fn ($v): float => round((float) $v, 1), array_column($this->db->fetchAll(
            "SELECT DATE_FORMAT(o.ordered_at, '%Y-%m') AS m, AVG(TIMESTAMPDIFF(HOUR, r.requested_at, o.ordered_at)) / 24 AS v
               FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id
              WHERE o.status <> 'cancelled' AND o.ordered_at BETWEEN :f AND :t AND $s GROUP BY m",
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'] + $p
        ), 'v', 'm'));
    }

    /** Fournisseurs par montant commandé sur la période. */
    public function suppliersRanking(string $from, string $to, ?int $siteId, int $limit = 6): array
    {
        [$s, $p] = $this->sites('o.site_id', $siteId, 'sr');

        return $this->db->fetchAll(
            "SELECT sp.id, sp.name, COUNT(*) AS orders, SUM(o.total_amount) AS amount, SUM(o.status IN ('issued', 'partially_received')) AS open
               FROM purchase_orders o JOIN suppliers sp ON sp.id = o.supplier_id
              WHERE o.status <> 'cancelled' AND o.ordered_at BETWEEN :f AND :t AND $s
              GROUP BY sp.id, sp.name ORDER BY amount DESC LIMIT " . max(1, $limit),
            ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'] + $p
        );
    }

    /** Engins en route entre deux sites. */
    public function fleetInTransit(?int $siteId): int
    {
        [$s, $p] = $this->sites('f.to_site_id', $siteId, 'ft');

        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM fleet_transfers f WHERE f.status = 'departed' AND $s", $p);
    }
}
