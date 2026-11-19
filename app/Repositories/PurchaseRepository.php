<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/**
 * États de besoins (EDB), devis, commandes et réceptions — filtrés par le périmètre de sites.
 * Les quantités satisfaites viennent des écritures datées de purchase_fulfilments : un état peut donc être
 * recalculé à n'importe quelle date (soldes ouverts à la clôture d'un mois).
 */
final class PurchaseRepository extends Repository
{
    public const SORTABLE = [
        'reference' => 'x.reference',
        'requested_at' => 'x.requested_at',
        'requester' => 'x.requester',
        'site' => 'x.site',
        'subject' => 'x.subject',
        'category' => 'x.category',
        'urgency' => 'x.urgency_rank',
        'estimated_amount' => 'x.estimated_amount',
        'display_status' => 'x.status_rank',
        'approved_at' => 'x.approved_at',
        'executed_at' => 'x.executed_at',
        'engaged_amount' => 'x.engaged_amount',
        'received_value' => 'x.received_value',
        'paid_amount' => 'x.paid_amount',
        'remaining_amount' => 'x.remaining_amount',
        'qty_remaining' => 'x.qty_remaining',
        'age_days' => 'x.age_days',
        'stage' => 'x.stage',
    ];

    public const CATEGORIES = ['parts', 'consumables', 'fuel', 'tyres', 'services', 'materials', 'other'];
    public const URGENCIES = ['normal', 'urgent', 'critical'];
    /** Statuts affichés : le sous-statut « exécution partielle » appartient à « approuvé non exécuté ». */
    public const DISPLAY_STATUSES = ['pending', 'approved', 'partial', 'executed', 'cancelled'];
    public const REPORTS = ['issued', 'executed', 'open'];

    /**
     * Liste des EDB et rapports mensuels.
     *
     * @param array{report?: ?string, from?: ?string, to?: ?string, site_id?: ?int, project_id?: ?int, requested_by?: ?int,
     *              category?: ?string, status?: ?string, urgency?: ?string} $filters
     */
    public function datatable(DataTableRequest $dt, array $filters): array
    {
        [$source, $params] = $this->source($filters['to'] ?? null);
        [$where, $fp] = $this->filters($filters);

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.reference', 'x.subject', 'x.requester']);
    }

    /**
     * Synthèse d'une période : solde d'ouverture, nouveaux dossiers, exécutés, annulés, solde de clôture,
     * taux d'exécution en nombre et en valeur, ventilation par site / catégorie / centre de coûts.
     */
    public function periodSummary(string $from, string $to, array $filters): array
    {
        [$source, $params] = $this->source($to);
        unset($filters['report'], $filters['status'], $filters['from'], $filters['to']);
        [$where, $fp] = $this->filters($filters);
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $f = $this->db->pdo()->quote($from . ' 00:00:00');
        $t = $this->db->pdo()->quote($to . ' 23:59:59');
        $openAt = static fn (string $d, string $op): string => "x.requested_at $op $d AND (x.executed_at IS NULL OR x.executed_at >= $d) AND (x.cancelled_at IS NULL OR x.cancelled_at >= $d)";
        $closing = "x.requested_at <= $t AND (x.executed_at IS NULL OR x.executed_at > $t) AND (x.cancelled_at IS NULL OR x.cancelled_at > $t)";
        $new = "x.requested_at BETWEEN $f AND $t";
        $executed = "x.executed_at BETWEEN $f AND $t";
        $cancelled = "x.cancelled_at BETWEEN $f AND $t";
        $count = static fn (string $cond): string => "COALESCE(SUM($cond), 0)";
        $value = static fn (string $cond): string => "COALESCE(SUM(CASE WHEN $cond THEN x.estimated_amount END), 0)";
        $totals = $this->db->fetchOne(
            'SELECT ' . $count($openAt($f, '<')) . ' AS opening_count, ' . $value($openAt($f, '<')) . ' AS opening_value,
                    ' . $count($new) . ' AS new_count, ' . $value($new) . ' AS new_value,
                    ' . $count($executed) . ' AS executed_count, ' . $value($executed) . ' AS executed_value,
                    ' . $count($cancelled) . ' AS cancelled_count, ' . $value($cancelled) . ' AS cancelled_value,
                    ' . $count($closing) . ' AS closing_count, ' . $value($closing) . ' AS closing_value,
                    ' . $count("$closing AND x.display_status = 'partial'") . " AS partial_count
               FROM $source WHERE $whereSql",
            $params + $fp
        ) ?? [];
        $base = (int) $totals['opening_count'] + (int) $totals['new_count'];
        $baseValue = (float) $totals['opening_value'] + (float) $totals['new_value'];
        $totals['rate_count'] = $base > 0 ? round((int) $totals['executed_count'] / $base * 100, 1) : null;
        $totals['rate_value'] = $baseValue > 0 ? round((float) $totals['executed_value'] / $baseValue * 100, 1) : null;

        $breakdown = [];
        foreach (['site' => 'x.site', 'category' => 'x.category', 'cost_center' => "COALESCE(x.cost_center, '—')"] as $key => $expr) {
            $breakdown[$key] = $this->db->fetchAll(
                "SELECT $expr AS label, COUNT(*) AS request_count, COALESCE(SUM(x.estimated_amount), 0) AS amount
                   FROM $source WHERE $whereSql AND $new GROUP BY $expr ORDER BY amount DESC, label",
                $params + $fp
            );
        }

        return $totals + ['breakdown' => $breakdown];
    }

    /** @return array{0: list<string>, 1: array<string, mixed>} */
    private function filters(array $filters): array
    {
        $where = [];
        $fp = [];
        $from = isset($filters['from']) ? $this->db->pdo()->quote($filters['from'] . ' 00:00:00') : null;
        $to = isset($filters['to']) ? $this->db->pdo()->quote($filters['to'] . ' 23:59:59') : null;
        $report = $filters['report'] ?? null;
        if ($report === 'issued' && $from !== null && $to !== null) {
            $where[] = "x.requested_at BETWEEN $from AND $to";
        } elseif ($report === 'executed' && $from !== null && $to !== null) {
            $where[] = "x.executed_at BETWEEN $from AND $to";
        } elseif ($report === 'open' && $to !== null) {
            // Approuvés non intégralement exécutés à la fin de la période : leur solde reste ouvert.
            $where[] = "x.approved_at <= $to AND (x.executed_at IS NULL OR x.executed_at > $to) AND (x.cancelled_at IS NULL OR x.cancelled_at > $to)";
        }
        foreach (['site_id' => 'x.site_id', 'project_id' => 'x.project_id', 'requested_by' => 'x.requested_by'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "$column = :f_$key";
                $fp["f_$key"] = (int) $filters[$key];
            }
        }
        foreach (['category' => 'x.category', 'urgency' => 'x.urgency', 'status' => 'x.display_status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "$column = :f_$key";
                $fp["f_$key"] = (string) $filters[$key];
            }
        }

        return [$where, $fp];
    }

    /**
     * Une ligne par EDB, avec les montants et quantités arrêtés à la date $asOf (aujourd'hui par défaut).
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function source(?string $asOf = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('r.site_id');
        $limit = $this->db->pdo()->quote(($asOf !== null && $asOf < date('Y-m-d') ? $asOf : date('Y-m-d')) . ' 23:59:59');
        $sql = "(SELECT y.*,
                        GREATEST(0, y.qty_requested - y.qty_satisfied) AS qty_remaining,
                        CASE WHEN y.estimated_amount IS NULL THEN NULL ELSE GREATEST(0, y.estimated_amount - y.received_value) END AS remaining_amount,
                        CASE WHEN y.status = 'approved' AND y.qty_satisfied > 0 THEN 'partial' ELSE y.status END AS display_status,
                        CASE y.status WHEN 'pending' THEN 0 WHEN 'approved' THEN IF(y.qty_satisfied > 0, 2, 1) WHEN 'executed' THEN 3 ELSE 4 END AS status_rank,
                        CASE WHEN y.status <> 'approved' THEN NULL
                             WHEN y.qty_on_order > 0 THEN 'delivery'
                             ELSE 'purchase' END AS stage,
                        CASE WHEN y.executed_at IS NOT NULL AND y.approved_at IS NOT NULL THEN DATEDIFF(y.executed_at, y.approved_at) END AS delay_days,
                        CASE WHEN y.approved_at IS NOT NULL AND y.status = 'approved' THEN DATEDIFF(NOW(), y.approved_at)
                             WHEN y.status = 'pending' THEN DATEDIFF(NOW(), y.requested_at) END AS age_days
                   FROM (
                    SELECT r.id, r.reference, r.site_id, r.project_id, r.requested_by, r.category, r.urgency, r.subject, r.reason, r.status,
                           r.is_rejected, r.estimated_amount, r.requested_at, r.approved_at, r.executed_at, r.cancelled_at, r.cancel_reason,
                           FIELD(r.urgency, 'critical', 'urgent', 'normal') AS urgency_rank,
                           s.name AS site, d.name AS department, pj.code AS project, cc.code AS cost_center, e.code AS equipment,
                           CONCAT(u.first_name, ' ', u.last_name) AS requester,
                           (SELECT COALESCE(SUM(l.qty_requested), 0) FROM purchase_request_lines l WHERE l.request_id = r.id) AS qty_requested,
                           (SELECT COALESCE(SUM(f.quantity), 0) FROM purchase_fulfilments f JOIN purchase_request_lines l ON l.id = f.request_line_id
                             WHERE l.request_id = r.id AND f.done_at <= $limit) AS qty_satisfied,
                           (SELECT COALESCE(SUM(f.value), 0) FROM purchase_fulfilments f JOIN purchase_request_lines l ON l.id = f.request_line_id
                             WHERE l.request_id = r.id AND f.done_at <= $limit) AS received_value,
                           (SELECT COALESCE(SUM(o.total_amount), 0) FROM purchase_orders o
                             WHERE o.request_id = r.id AND o.status <> 'cancelled' AND o.ordered_at <= $limit) AS engaged_amount,
                           (SELECT COALESCE(SUM(pay.amount), 0) FROM payment_requests pay
                             WHERE pay.request_id = r.id AND pay.status = 'paid' AND pay.paid_at <= $limit) AS paid_amount,
                           (SELECT COALESCE(SUM(ol.qty_ordered - ol.qty_received), 0) FROM purchase_order_lines ol JOIN purchase_orders o ON o.id = ol.order_id
                             WHERE o.request_id = r.id AND o.status IN ('issued', 'partially_received')) AS qty_on_order,
                           (SELECT GROUP_CONCAT(o.reference ORDER BY o.id SEPARATOR ', ') FROM purchase_orders o
                             WHERE o.request_id = r.id AND o.status <> 'cancelled') AS orders,
                           (SELECT GROUP_CONCAT(DISTINCT f.document_ref ORDER BY f.id SEPARATOR ', ') FROM purchase_fulfilments f
                              JOIN purchase_request_lines l ON l.id = f.request_line_id WHERE l.request_id = r.id) AS proofs
                      FROM purchase_requests r
                      JOIN sites s ON s.id = r.site_id
                      LEFT JOIN departments d ON d.id = r.department_id
                      LEFT JOIN projects pj ON pj.id = r.project_id
                      LEFT JOIN cost_centers cc ON cc.id = r.cost_center_id
                      LEFT JOIN equipment e ON e.id = r.equipment_id
                      LEFT JOIN users u ON u.id = r.requested_by
                     WHERE $scopeSql
                   ) y
                ) x";

        return [$sql, $params];
    }

    // --- EDB ---------------------------------------------------------------------

    public function find(int $id, bool $forUpdate = false): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('r.site_id');

        return $this->db->fetchOne(
            "SELECT r.*, s.name AS site, d.name AS department, CONCAT(pj.code, ' – ', pj.name) AS project, CONCAT(cc.code, ' – ', cc.name) AS cost_center,
                    CONCAT(e.code, ' – ', e.name) AS equipment, CONCAT(u.first_name, ' ', u.last_name) AS requester
               FROM purchase_requests r
               JOIN sites s ON s.id = r.site_id
               LEFT JOIN departments d ON d.id = r.department_id
               LEFT JOIN projects pj ON pj.id = r.project_id
               LEFT JOIN cost_centers cc ON cc.id = r.cost_center_id
               LEFT JOIN equipment e ON e.id = r.equipment_id
               LEFT JOIN users u ON u.id = r.requested_by
              WHERE r.id = :id AND $scopeSql" . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $id] + $params
        );
    }

    public function create(array $data, array $lines): int
    {
        $this->scope->assertAllows((int) $data['site_id']);
        $id = $this->db->insert(
            'INSERT INTO purchase_requests (reference, site_id, department_id, project_id, cost_center_id, equipment_id, category, urgency, subject, reason,
                                            estimated_amount, requested_by)
             VALUES (:reference, :site_id, :department_id, :project_id, :cost_center_id, :equipment_id, :category, :urgency, :subject, :reason,
                     :estimated_amount, :requested_by)',
            $data
        );
        foreach ($lines as $line) {
            $this->db->execute(
                'INSERT INTO purchase_request_lines (request_id, part_id, description, unit, qty_requested, est_unit_cost) VALUES (:r, :p, :d, :u, :q, :c)',
                ['r' => $id, 'p' => $line['part_id'], 'd' => $line['description'], 'u' => $line['unit'], 'q' => $line['quantity'], 'c' => $line['est_unit_cost']]
            );
        }

        return $id;
    }

    public function setStatus(int $id, string $status, array $extra = []): void
    {
        $set = ['status = :status'];
        $params = ['status' => $status, 'id' => $id];
        foreach (['approved_at', 'executed_at', 'cancelled_at', 'cancel_reason', 'is_rejected'] as $column) {
            if (array_key_exists($column, $extra)) {
                $set[] = "$column = :$column";
                $params[$column] = $extra[$column];
            }
        }
        $this->db->execute('UPDATE purchase_requests SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
    }

    /**
     * Lignes d'un EDB avec leur avancement : servi du stock, reçu par transfert, reçu sur commande, en commande, restant,
     * et le stock disponible sur le site de l'EDB et dans les autres magasins (recherche « stock local puis autres sites »).
     */
    public function lines(int $requestId): array
    {
        return $this->db->fetchAll(
            "SELECT l.*, p.reference AS part_reference, p.name AS part_name,
                    COALESCE(f.from_stock, 0) AS qty_from_stock, COALESCE(f.transferred, 0) AS qty_transferred, COALESCE(f.purchased, 0) AS qty_purchased,
                    COALESCE(f.satisfied, 0) AS qty_satisfied, COALESCE(f.value, 0) AS value_satisfied,
                    COALESCE(o.on_order, 0) AS qty_on_order, COALESCE(tr.in_transfer, 0) AS qty_in_transfer,
                    GREATEST(0, l.qty_requested - COALESCE(f.satisfied, 0)) AS qty_remaining,
                    GREATEST(0, l.qty_requested - COALESCE(f.satisfied, 0) - COALESCE(o.on_order, 0) - COALESCE(tr.in_transfer, 0)) AS qty_to_source,
                    COALESCE(ls.quantity - ls.reserved, 0) AS local_available,
                    (SELECT COALESCE(SUM(os.quantity - os.reserved), 0) FROM part_stocks os JOIN sites s2 ON s2.id = os.site_id AND s2.is_active = 1
                      WHERE os.part_id = l.part_id AND os.site_id <> r.site_id AND os.quantity - os.reserved > 0) AS other_available
               FROM purchase_request_lines l
               JOIN purchase_requests r ON r.id = l.request_id
               LEFT JOIN parts p ON p.id = l.part_id
               LEFT JOIN part_stocks ls ON ls.part_id = l.part_id AND ls.site_id = r.site_id
               LEFT JOIN (SELECT request_line_id, SUM(quantity) AS satisfied, SUM(value) AS value,
                                 SUM(IF(kind = 'stock', quantity, 0)) AS from_stock, SUM(IF(kind = 'transfer', quantity, 0)) AS transferred,
                                 SUM(IF(kind = 'purchase', quantity, 0)) AS purchased
                            FROM purchase_fulfilments GROUP BY request_line_id) f ON f.request_line_id = l.id
               LEFT JOIN (SELECT ol.request_line_id, SUM(ol.qty_ordered - ol.qty_received) AS on_order
                            FROM purchase_order_lines ol JOIN purchase_orders po ON po.id = ol.order_id
                           WHERE po.status IN ('issued', 'partially_received') GROUP BY ol.request_line_id) o ON o.request_line_id = l.id
               LEFT JOIN (SELECT tl.request_line_id, SUM(COALESCE(tl.qty_shipped, tl.qty_requested)) AS in_transfer
                            FROM stock_transfer_lines tl JOIN stock_transfers t ON t.id = tl.transfer_id
                           WHERE t.status IN ('requested', 'authorized', 'shipped') AND tl.request_line_id IS NOT NULL
                           GROUP BY tl.request_line_id) tr ON tr.request_line_id = l.id
              WHERE l.request_id = :r ORDER BY l.id",
            ['r' => $requestId]
        );
    }

    public function line(int $lineId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM purchase_request_lines WHERE id = :id FOR UPDATE', ['id' => $lineId]);
    }

    /** Magasins des autres sites où l'article est disponible (tous sites : l'EDB doit pouvoir désigner une origine). */
    public function otherStores(int $partId, int $exceptSiteId): array
    {
        return $this->db->fetchAll(
            'SELECT s.id AS site_id, s.name AS site, os.quantity - os.reserved AS available
               FROM part_stocks os JOIN sites s ON s.id = os.site_id AND s.is_active = 1
              WHERE os.part_id = :p AND os.site_id <> :s AND os.quantity - os.reserved > 0 ORDER BY available DESC',
            ['p' => $partId, 's' => $exceptSiteId]
        );
    }

    public function addFulfilment(int $lineId, string $kind, float $quantity, ?float $value, ?string $documentRef, ?int $userId): void
    {
        $this->db->execute(
            'INSERT INTO purchase_fulfilments (request_line_id, kind, quantity, value, document_ref, done_by) VALUES (:l, :k, :q, :v, :d, :u)',
            ['l' => $lineId, 'k' => $kind, 'q' => $quantity, 'v' => $value, 'd' => $documentRef, 'u' => $userId]
        );
    }

    public function fulfilments(int $requestId): array
    {
        return $this->db->fetchAll(
            "SELECT f.*, l.description, l.unit, CONCAT(u.first_name, ' ', u.last_name) AS user
               FROM purchase_fulfilments f JOIN purchase_request_lines l ON l.id = f.request_line_id LEFT JOIN users u ON u.id = f.done_by
              WHERE l.request_id = :r ORDER BY f.id",
            ['r' => $requestId]
        );
    }

    /** Quantité restant à satisfaire sur l'ensemble de l'EDB. */
    public function remaining(int $requestId): float
    {
        return (float) $this->db->fetchValue(
            'SELECT COALESCE(SUM(GREATEST(0, l.qty_requested - COALESCE((SELECT SUM(f.quantity) FROM purchase_fulfilments f WHERE f.request_line_id = l.id), 0))), 0)
               FROM purchase_request_lines l WHERE l.request_id = :r',
            ['r' => $requestId]
        );
    }

    // --- Devis -------------------------------------------------------------------

    public function quotes(int $requestId): array
    {
        return $this->db->fetchAll(
            "SELECT q.*, CONCAT(s.code, ' – ', s.name) AS supplier FROM purchase_quotes q JOIN suppliers s ON s.id = q.supplier_id
              WHERE q.request_id = :r ORDER BY q.amount, q.id",
            ['r' => $requestId]
        );
    }

    public function addQuote(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO purchase_quotes (request_id, supplier_id, reference, amount, delivery_days, note, created_by)
             VALUES (:request_id, :supplier_id, :reference, :amount, :delivery_days, :note, :created_by)',
            $data
        );
    }

    public function selectQuote(int $requestId, int $quoteId, ?string $reason): void
    {
        $this->db->execute('UPDATE purchase_quotes SET is_selected = 0, selection_reason = NULL WHERE request_id = :r', ['r' => $requestId]);
        $this->db->execute('UPDATE purchase_quotes SET is_selected = 1, selection_reason = :why WHERE id = :q AND request_id = :r', ['why' => $reason, 'q' => $quoteId, 'r' => $requestId]);
    }

    // --- Commandes et réceptions ---------------------------------------------------

    public function orders(?int $requestId = null, ?string $status = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('o.site_id');
        $where = $scopeSql;
        if ($requestId !== null) {
            $where .= ' AND o.request_id = :r';
            $params['r'] = $requestId;
        }
        if ($status !== null) {
            $where .= ' AND o.status = :st';
            $params['st'] = $status;
        }

        return $this->db->fetchAll(
            "SELECT o.*, CONCAT(sup.code, ' – ', sup.name) AS supplier, s.name AS site, r.reference AS request_reference,
                    CONCAT(u.first_name, ' ', u.last_name) AS buyer,
                    (SELECT COALESCE(SUM(ol.qty_ordered), 0) FROM purchase_order_lines ol WHERE ol.order_id = o.id) AS qty_ordered,
                    (SELECT COALESCE(SUM(ol.qty_received), 0) FROM purchase_order_lines ol WHERE ol.order_id = o.id) AS qty_received
               FROM purchase_orders o
               JOIN suppliers sup ON sup.id = o.supplier_id
               JOIN sites s ON s.id = o.site_id
               JOIN purchase_requests r ON r.id = o.request_id
               LEFT JOIN users u ON u.id = o.ordered_by
              WHERE $where ORDER BY o.id DESC LIMIT 300",
            $params
        );
    }

    public function findOrder(int $id, bool $forUpdate = false): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('o.site_id');

        return $this->db->fetchOne(
            "SELECT o.*, CONCAT(sup.code, ' – ', sup.name) AS supplier, s.name AS site, r.reference AS request_reference, r.subject AS request_subject,
                    CONCAT(u.first_name, ' ', u.last_name) AS buyer
               FROM purchase_orders o
               JOIN suppliers sup ON sup.id = o.supplier_id
               JOIN sites s ON s.id = o.site_id
               JOIN purchase_requests r ON r.id = o.request_id
               LEFT JOIN users u ON u.id = o.ordered_by
              WHERE o.id = :id AND $scopeSql" . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $id] + $params
        );
    }

    public function orderLines(int $orderId): array
    {
        return $this->db->fetchAll(
            'SELECT ol.*, l.part_id, l.description, l.unit, p.reference AS part_reference,
                    ol.qty_ordered - ol.qty_received AS qty_outstanding
               FROM purchase_order_lines ol
               JOIN purchase_request_lines l ON l.id = ol.request_line_id
               LEFT JOIN parts p ON p.id = l.part_id
              WHERE ol.order_id = :o ORDER BY ol.id',
            ['o' => $orderId]
        );
    }

    public function createOrder(array $data, array $lines): int
    {
        $this->scope->assertAllows((int) $data['site_id']);
        $id = $this->db->insert(
            'INSERT INTO purchase_orders (reference, request_id, site_id, supplier_id, quote_id, total_amount, expected_on, note, ordered_by)
             VALUES (:reference, :request_id, :site_id, :supplier_id, :quote_id, :total_amount, :expected_on, :note, :ordered_by)',
            $data
        );
        foreach ($lines as $line) {
            $this->db->execute(
                'INSERT INTO purchase_order_lines (order_id, request_line_id, qty_ordered, unit_price) VALUES (:o, :l, :q, :p)',
                ['o' => $id, 'l' => $line['request_line_id'], 'q' => $line['quantity'], 'p' => $line['unit_price']]
            );
        }

        return $id;
    }

    public function setOrderStatus(int $id, string $status, ?string $cancelReason = null): void
    {
        $this->db->execute(
            "UPDATE purchase_orders SET status = :s, cancel_reason = COALESCE(:why, cancel_reason),
                    closed_at = IF(:s2 IN ('received', 'cancelled'), NOW(), NULL) WHERE id = :id",
            ['s' => $status, 's2' => $status, 'why' => $cancelReason, 'id' => $id]
        );
    }

    public function addReceipt(array $data, array $lines): int
    {
        $this->scope->assertAllows((int) $data['site_id']);
        $id = $this->db->insert(
            'INSERT INTO goods_receipts (reference, order_id, site_id, delivery_note, carrier_id, vehicle_plate, driver_name, note, received_by)
             VALUES (:reference, :order_id, :site_id, :delivery_note, :carrier_id, :vehicle_plate, :driver_name, :note, :received_by)',
            $data
        );
        foreach ($lines as $line) {
            $this->db->execute(
                'INSERT INTO goods_receipt_lines (receipt_id, order_line_id, qty_accepted, qty_rejected, reject_reason) VALUES (:r, :l, :a, :x, :why)',
                ['r' => $id, 'l' => $line['order_line_id'], 'a' => $line['accepted'], 'x' => $line['rejected'], 'why' => $line['reject_reason']]
            );
            $this->db->execute(
                'UPDATE purchase_order_lines SET qty_received = qty_received + :a, qty_rejected = qty_rejected + :x WHERE id = :l',
                ['a' => $line['accepted'], 'x' => $line['rejected'], 'l' => $line['order_line_id']]
            );
        }

        return $id;
    }

    public function receipts(int $orderId): array
    {
        return $this->db->fetchAll(
            "SELECT g.*, c.name AS carrier, CONCAT(u.first_name, ' ', u.last_name) AS receiver,
                    (SELECT COALESCE(SUM(gl.qty_accepted), 0) FROM goods_receipt_lines gl WHERE gl.receipt_id = g.id) AS qty_accepted,
                    (SELECT COALESCE(SUM(gl.qty_rejected), 0) FROM goods_receipt_lines gl WHERE gl.receipt_id = g.id) AS qty_rejected
               FROM goods_receipts g LEFT JOIN carriers c ON c.id = g.carrier_id LEFT JOIN users u ON u.id = g.received_by
              WHERE g.order_id = :o ORDER BY g.id",
            ['o' => $orderId]
        );
    }

    /** Commandé non encore livré : ce qui reste chez les fournisseurs (cahier des charges § 5.1). */
    public function outstanding(?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('o.site_id');
        $where = $scopeSql;
        if ($siteId !== null) {
            $where .= ' AND o.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT o.id AS order_id, o.reference, o.ordered_at, o.expected_on, s.name AS site, sup.name AS supplier, r.reference AS request_reference,
                    l.description, l.unit, ol.qty_ordered, ol.qty_received, ol.qty_ordered - ol.qty_received AS qty_outstanding,
                    ROUND((ol.qty_ordered - ol.qty_received) * ol.unit_price, 2) AS value_outstanding,
                    DATEDIFF(CURDATE(), COALESCE(o.expected_on, DATE(o.ordered_at))) AS late_days
               FROM purchase_order_lines ol
               JOIN purchase_orders o ON o.id = ol.order_id
               JOIN purchase_request_lines l ON l.id = ol.request_line_id
               JOIN purchase_requests r ON r.id = o.request_id
               JOIN suppliers sup ON sup.id = o.supplier_id
               JOIN sites s ON s.id = o.site_id
              WHERE o.status IN ('issued', 'partially_received') AND ol.qty_ordered > ol.qty_received AND $where
              ORDER BY o.ordered_at",
            $params
        );
    }
}
