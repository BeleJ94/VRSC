<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DataTableRequest;

/**
 * Finance : factures fournisseurs, demandes de décaissement, caisses, rapprochement et états — filtrés par site.
 */
final class FinanceRepository extends Repository
{
    public const SORTABLE = [
        'label' => 'x.label',
        'requested' => 'x.requested',
        'approved' => 'x.approved',
        'engaged' => 'x.engaged',
        'paid' => 'x.paid',
        'remaining' => 'x.remaining',
        'advances' => 'x.advances',
    ];

    public const GROUPS = ['site', 'project', 'cost_center', 'category'];
    public const KINDS = ['invoice', 'advance', 'expense'];
    public const STATUSES = ['pending', 'approved', 'paid', 'rejected', 'cancelled'];
    public const CATEGORIES = ['parts', 'consumables', 'fuel', 'tyres', 'services', 'materials', 'maintenance', 'other'];

    // --- Factures -----------------------------------------------------------------

    private const INVOICE_SELECT = "i.*, sup.name AS supplier, s.name AS site, o.reference AS order_reference, o.total_amount AS order_amount,
            o.request_id, r.reference AS request_reference,
            COALESCE(pay.paid, 0) AS paid, COALESCE(pay.in_progress, 0) AS in_progress,
            i.amount - COALESCE(pay.paid, 0) AS unpaid,
            i.amount - COALESCE(pay.paid, 0) - COALESCE(pay.in_progress, 0) AS payable,
            DATEDIFF(CURDATE(), COALESCE(i.due_date, i.invoice_date)) AS overdue_days";

    private const INVOICE_FROM = "supplier_invoices i
            JOIN suppliers sup ON sup.id = i.supplier_id
            JOIN sites s ON s.id = i.site_id
            JOIN purchase_orders o ON o.id = i.order_id
            JOIN purchase_requests r ON r.id = o.request_id
            LEFT JOIN (SELECT invoice_id, SUM(IF(status = 'paid', amount, 0)) AS paid, SUM(IF(status IN ('pending', 'approved'), amount, 0)) AS in_progress
                         FROM payment_requests WHERE invoice_id IS NOT NULL GROUP BY invoice_id) pay ON pay.invoice_id = i.id";

    /** @param array{unpaid?: bool, site_id?: ?int, order_id?: ?int} $filters */
    public function invoices(array $filters = []): array
    {
        [$scopeSql, $params] = $this->scope->clause('i.site_id');
        $where = $scopeSql;
        if (!empty($filters['unpaid'])) {
            $where .= " AND i.status = 'recorded' AND i.amount - COALESCE(pay.paid, 0) > 0";
        }
        if (!empty($filters['site_id'])) {
            $where .= ' AND i.site_id = :site';
            $params['site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['order_id'])) {
            $where .= ' AND i.order_id = :o';
            $params['o'] = (int) $filters['order_id'];
        }

        return $this->db->fetchAll('SELECT ' . self::INVOICE_SELECT . ' FROM ' . self::INVOICE_FROM . " WHERE $where ORDER BY i.status, COALESCE(i.due_date, i.invoice_date), i.id LIMIT 500", $params);
    }

    public function findInvoice(int $id, bool $forUpdate = false): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('i.site_id');
        if ($forUpdate && $this->db->fetchOne("SELECT i.id FROM supplier_invoices i WHERE i.id = :id AND $scopeSql FOR UPDATE", ['id' => $id] + $params) === null) {
            return null;
        }

        return $this->db->fetchOne('SELECT ' . self::INVOICE_SELECT . ' FROM ' . self::INVOICE_FROM . " WHERE i.id = :id AND $scopeSql", ['id' => $id] + $params);
    }

    public function invoiceNumberExists(int $supplierId, string $number): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM supplier_invoices WHERE supplier_id = :s AND invoice_number = :n', ['s' => $supplierId, 'n' => $number]);
    }

    public function createInvoice(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);

        return $this->db->insert(
            'INSERT INTO supplier_invoices (supplier_id, invoice_number, invoice_date, due_date, amount, order_id, site_id, note, created_by)
             VALUES (:supplier_id, :invoice_number, :invoice_date, :due_date, :amount, :order_id, :site_id, :note, :created_by)',
            $data
        );
    }

    public function cancelInvoice(int $id, string $reason): void
    {
        $this->db->execute("UPDATE supplier_invoices SET status = 'cancelled', cancel_reason = :why WHERE id = :id", ['why' => $reason, 'id' => $id]);
    }

    /**
     * Chiffres d'une commande pour le rapprochement : commandé, reçu (valeur acceptée), facturé, payé, en cours de paiement.
     */
    public function orderFigures(int $orderId): array
    {
        return $this->db->fetchOne(
            "SELECT o.id, o.reference, o.total_amount AS ordered, o.supplier_id, o.site_id, o.request_id, o.status,
                    (SELECT COALESCE(SUM(gl.qty_accepted * ol.unit_price), 0) FROM goods_receipt_lines gl
                       JOIN purchase_order_lines ol ON ol.id = gl.order_line_id WHERE ol.order_id = o.id) AS received,
                    (SELECT COALESCE(SUM(i.amount), 0) FROM supplier_invoices i WHERE i.order_id = o.id AND i.status = 'recorded') AS invoiced,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payment_requests p WHERE p.order_id = o.id AND p.status = 'paid') AS paid,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payment_requests p WHERE p.order_id = o.id AND p.status IN ('pending', 'approved')) AS in_progress
               FROM purchase_orders o WHERE o.id = :id",
            ['id' => $orderId]
        ) ?? [];
    }

    /**
     * Rapprochement EDB – commande – réception – facture – paiement, une ligne par commande, avec ses anomalies.
     */
    public function reconciliation(?int $siteId = null, bool $anomaliesOnly = false): array
    {
        [$scopeSql, $params] = $this->scope->clause('o.site_id');
        $where = $scopeSql . " AND o.status <> 'cancelled'";
        if ($siteId !== null) {
            $where .= ' AND o.site_id = :site';
            $params['site'] = $siteId;
        }
        $rows = $this->db->fetchAll(
            "SELECT o.id AS order_id, o.reference AS order_reference, o.ordered_at, o.status AS order_status, o.total_amount AS ordered,
                    r.id AS request_id, r.reference AS request_reference, r.status AS request_status, s.name AS site, sup.name AS supplier,
                    (SELECT COALESCE(SUM(gl.qty_accepted * ol.unit_price), 0) FROM goods_receipt_lines gl
                       JOIN purchase_order_lines ol ON ol.id = gl.order_line_id WHERE ol.order_id = o.id) AS received,
                    (SELECT COALESCE(SUM(i.amount), 0) FROM supplier_invoices i WHERE i.order_id = o.id AND i.status = 'recorded') AS invoiced,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payment_requests p WHERE p.order_id = o.id AND p.status = 'paid') AS paid
               FROM purchase_orders o
               JOIN purchase_requests r ON r.id = o.request_id
               JOIN sites s ON s.id = o.site_id
               JOIN suppliers sup ON sup.id = o.supplier_id
              WHERE $where ORDER BY o.id DESC LIMIT 500",
            $params
        );
        foreach ($rows as &$row) {
            $ordered = (float) $row['ordered'];
            $received = (float) $row['received'];
            $invoiced = (float) $row['invoiced'];
            $paid = (float) $row['paid'];
            $flags = [];
            if ($paid > $received + 0.005) {
                $flags[] = 'paid_not_received';
            }
            if ($invoiced > $received + 0.005) {
                $flags[] = 'invoiced_not_received';
            }
            if ($received > $invoiced + 0.005) {
                $flags[] = 'received_not_invoiced';
            }
            if ($invoiced > $paid + 0.005) {
                $flags[] = 'unpaid';
            }
            if ($ordered > $received + 0.005) {
                $flags[] = 'not_delivered';
            }
            $row['flags'] = $flags;
            $row['matched'] = $flags === [];
        }
        unset($row);

        return $anomaliesOnly ? array_values(array_filter($rows, static fn (array $r): bool => !$r['matched'])) : $rows;
    }

    // --- Demandes de décaissement ---------------------------------------------------

    private const PAYMENT_SELECT = "p.*, s.name AS site, pj.code AS project, cc.code AS cost_center, d.name AS department,
            r.reference AS request_reference, o.reference AS order_reference, i.invoice_number,
            pm.name AS payment_method, pm.code AS payment_method_code, reg.name AS cash_register,
            CONCAT(u.first_name, ' ', u.last_name) AS requester, CONCAT(pu.first_name, ' ', pu.last_name) AS payer,
            ar.id AS approval_id, st.code AS approval_step,
            CASE WHEN p.kind = 'advance' AND p.status = 'paid' THEN p.amount - p.justified_amount - p.returned_amount ELSE 0 END AS to_justify";

    private const PAYMENT_FROM = "payment_requests p
            JOIN sites s ON s.id = p.site_id
            LEFT JOIN projects pj ON pj.id = p.project_id
            LEFT JOIN cost_centers cc ON cc.id = p.cost_center_id
            LEFT JOIN departments d ON d.id = p.department_id
            LEFT JOIN purchase_requests r ON r.id = p.request_id
            LEFT JOIN purchase_orders o ON o.id = p.order_id
            LEFT JOIN supplier_invoices i ON i.id = p.invoice_id
            LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
            LEFT JOIN cash_registers reg ON reg.id = p.cash_register_id
            LEFT JOIN users u ON u.id = p.requested_by
            LEFT JOIN users pu ON pu.id = p.paid_by
            LEFT JOIN approval_requests ar ON ar.entity_type = 'payment_request' AND ar.entity_id = p.id AND ar.status = 'pending'
            LEFT JOIN approval_flow_steps st ON st.id = ar.current_step_id";

    /** @param array{status?: ?string, site_id?: ?int, to_justify?: bool, request_id?: ?int} $filters */
    public function payments(array $filters = []): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $where = $scopeSql;
        if (!empty($filters['status'])) {
            $where .= ' AND p.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['site_id'])) {
            $where .= ' AND p.site_id = :site';
            $params['site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['to_justify'])) {
            $where .= " AND p.kind = 'advance' AND p.status = 'paid' AND p.amount - p.justified_amount - p.returned_amount > 0";
        }
        if (!empty($filters['request_id'])) {
            $where .= ' AND p.request_id = :req';
            $params['req'] = (int) $filters['request_id'];
        }

        return $this->db->fetchAll(
            'SELECT ' . self::PAYMENT_SELECT . ' FROM ' . self::PAYMENT_FROM . " WHERE $where
              ORDER BY FIELD(p.status, 'pending', 'approved', 'paid', 'rejected', 'cancelled'), p.id DESC LIMIT 500",
            $params
        );
    }

    public function findPayment(int $id, bool $forUpdate = false): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        if ($forUpdate && $this->db->fetchOne("SELECT p.id FROM payment_requests p WHERE p.id = :id AND $scopeSql FOR UPDATE", ['id' => $id] + $params) === null) {
            return null;
        }

        return $this->db->fetchOne('SELECT ' . self::PAYMENT_SELECT . ' FROM ' . self::PAYMENT_FROM . " WHERE p.id = :id AND $scopeSql", ['id' => $id] + $params);
    }

    public function createPayment(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);
        $columns = array_keys($data);

        return $this->db->insert(
            'INSERT INTO payment_requests (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => ":$c", $columns)) . ')',
            $data
        );
    }

    public function updatePayment(int $id, array $fields): void
    {
        $allowed = ['status', 'approved_at', 'paid_at', 'paid_by', 'payment_method_id', 'payment_reference', 'cash_register_id',
            'justified_amount', 'returned_amount', 'justified_at', 'cancel_reason'];
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $name => $value) {
            if (in_array($name, $allowed, true)) {
                $set[] = "$name = :$name";
                $params[$name] = $value;
            }
        }
        if ($set !== []) {
            $this->db->execute('UPDATE payment_requests SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
        }
    }

    /** Une même référence de paiement (virement, chèque…) ne sert pas deux fois pour un même mode. */
    public function paymentReferenceUsed(int $methodId, string $reference, int $exceptId): bool
    {
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM payment_requests WHERE payment_method_id = :m AND payment_reference = :r AND status = 'paid' AND id <> :id",
            ['m' => $methodId, 'r' => $reference, 'id' => $exceptId]
        );
    }

    // --- Caisse ----------------------------------------------------------------------

    public function registers(?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('c.site_id');
        $where = $scopeSql . ' AND c.is_active = 1';
        if ($siteId !== null) {
            $where .= ' AND c.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT c.*, s.name AS site,
                    (SELECT MAX(cl.closing_date) FROM cash_closings cl WHERE cl.register_id = c.id) AS last_closing
               FROM cash_registers c JOIN sites s ON s.id = c.site_id WHERE $where ORDER BY s.name, c.name",
            $params
        );
    }

    public function findRegister(int $id, bool $forUpdate = false): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('c.site_id');

        return $this->db->fetchOne(
            "SELECT c.*, s.name AS site FROM cash_registers c JOIN sites s ON s.id = c.site_id WHERE c.id = :id AND $scopeSql" . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $id] + $params
        );
    }

    public function createRegister(int $siteId, string $name): int
    {
        $this->scope->assertAllows($siteId);

        return $this->db->insert('INSERT INTO cash_registers (site_id, name) VALUES (:s, :n)', ['s' => $siteId, 'n' => $name]);
    }

    public function addEntry(array $data): int
    {
        $this->db->execute('UPDATE cash_registers SET balance = :b WHERE id = :id', ['b' => $data['balance_after'], 'id' => $data['register_id']]);

        return $this->db->insert(
            'INSERT INTO cash_entries (register_id, entry_date, reference, direction, amount, balance_after, label, payment_request_id, created_by)
             VALUES (:register_id, :entry_date, :reference, :direction, :amount, :balance_after, :label, :payment_request_id, :created_by)',
            $data
        );
    }

    public function isDayClosed(int $registerId, string $date): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM cash_closings WHERE register_id = :r AND closing_date = :d', ['r' => $registerId, 'd' => $date]);
    }

    /**
     * Journal d'une journée : solde d'ouverture, écritures, totaux, solde de clôture, arrêté éventuel.
     *
     * @return array{opening: float, entries: list<array>, total_in: float, total_out: float, closing: float, closed: array|null}
     */
    public function journal(int $registerId, string $date): array
    {
        $opening = (float) ($this->db->fetchValue(
            'SELECT balance_after FROM cash_entries WHERE register_id = :r AND entry_date < :d ORDER BY entry_date DESC, id DESC LIMIT 1',
            ['r' => $registerId, 'd' => $date]
        ) ?? 0);
        $entries = $this->db->fetchAll(
            "SELECT e.*, p.reference AS payment_reference, p.beneficiary, CONCAT(u.first_name, ' ', u.last_name) AS user
               FROM cash_entries e LEFT JOIN payment_requests p ON p.id = e.payment_request_id LEFT JOIN users u ON u.id = e.created_by
              WHERE e.register_id = :r AND e.entry_date = :d ORDER BY e.id",
            ['r' => $registerId, 'd' => $date]
        );
        $in = 0.0;
        $out = 0.0;
        foreach ($entries as $entry) {
            if ($entry['direction'] === 'in') {
                $in += (float) $entry['amount'];
            } else {
                $out += (float) $entry['amount'];
            }
        }
        $closed = $this->db->fetchOne(
            "SELECT cl.*, CONCAT(u.first_name, ' ', u.last_name) AS closed_by_name FROM cash_closings cl LEFT JOIN users u ON u.id = cl.closed_by
              WHERE cl.register_id = :r AND cl.closing_date = :d",
            ['r' => $registerId, 'd' => $date]
        );

        return ['opening' => $opening, 'entries' => $entries, 'total_in' => $in, 'total_out' => $out, 'closing' => round($opening + $in - $out, 2), 'closed' => $closed];
    }

    /** Jours antérieurs comportant des écritures et non encore arrêtés. */
    public function unclosedDayBefore(int $registerId, string $date): ?string
    {
        $day = $this->db->fetchValue(
            'SELECT MIN(e.entry_date) FROM cash_entries e
              WHERE e.register_id = :r AND e.entry_date < :d
                AND NOT EXISTS (SELECT 1 FROM cash_closings cl WHERE cl.register_id = e.register_id AND cl.closing_date = e.entry_date)',
            ['r' => $registerId, 'd' => $date]
        );

        return $day !== null ? (string) $day : null;
    }

    public function addClosing(array $data): void
    {
        $this->db->execute(
            'INSERT INTO cash_closings (register_id, closing_date, opening_balance, total_in, total_out, closing_balance, counted_balance, gap, gap_note, closed_by)
             VALUES (:register_id, :closing_date, :opening_balance, :total_in, :total_out, :closing_balance, :counted_balance, :gap, :gap_note, :closed_by)',
            $data
        );
    }

    // --- Synthèses ---------------------------------------------------------------------

    /** Indicateurs du tableau de bord financier, dans le périmètre. */
    public function dashboard(?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('p.site_id');
        $site = '';
        if ($siteId !== null) {
            $site = ' AND p.site_id = :site';
            $params['site'] = $siteId;
        }
        $payments = $this->db->fetchOne(
            "SELECT COALESCE(SUM(p.status = 'pending'), 0) AS pending_count, COALESCE(SUM(IF(p.status = 'pending', p.amount, 0)), 0) AS pending_amount,
                    COALESCE(SUM(p.status = 'approved'), 0) AS to_pay_count, COALESCE(SUM(IF(p.status = 'approved', p.amount, 0)), 0) AS to_pay_amount,
                    COALESCE(SUM(IF(p.status = 'paid' AND p.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01'), p.amount, 0)), 0) AS paid_month,
                    COALESCE(SUM(IF(p.kind = 'advance' AND p.status = 'paid', p.amount - p.justified_amount - p.returned_amount, 0)), 0) AS advances_to_justify
               FROM payment_requests p WHERE $scopeSql$site",
            $params
        ) ?? [];
        $unpaid = array_sum(array_map(static fn (array $i): float => (float) $i['unpaid'], $this->invoices(['unpaid' => true, 'site_id' => $siteId])));
        [$scopeR, $paramsR] = $this->scope->clause('r.site_id');
        $siteR = $siteId !== null ? ' AND r.site_id = :site' : '';
        $edb = $this->db->fetchOne(
            "SELECT COALESCE(SUM(r.status = 'pending'), 0) AS pending, COALESCE(SUM(r.status = 'approved'), 0) AS open FROM purchase_requests r WHERE $scopeR$siteR",
            $paramsR + ($siteId !== null ? ['site' => $siteId] : [])
        ) ?? [];
        [$scopeO, $paramsO] = $this->scope->clause('o.site_id');
        $siteO = $siteId !== null ? ' AND o.site_id = :site' : '';
        $engaged = (float) $this->db->fetchValue(
            "SELECT COALESCE(SUM(GREATEST(0, o.total_amount - (SELECT COALESCE(SUM(p.amount), 0) FROM payment_requests p WHERE p.order_id = o.id AND p.status = 'paid'))), 0)
               FROM purchase_orders o WHERE o.status <> 'cancelled' AND $scopeO$siteO",
            $paramsO + ($siteId !== null ? ['site' => $siteId] : [])
        );

        return $payments + ['unpaid_invoices' => $unpaid, 'edb_pending' => (int) ($edb['pending'] ?? 0), 'edb_open' => (int) ($edb['open'] ?? 0),
            'engaged_unpaid' => $engaged, 'cash' => array_sum(array_map(static fn (array $r): float => (float) $r['balance'], $this->registers($siteId)))];
    }

    /**
     * Dépenses d'une période par site, projet, centre de coûts ou catégorie : demandé, approuvé, engagé, payé, restant,
     * avances à justifier. Engagé = commandes émises + dépenses directes et avances approuvées (chaque montant une seule fois).
     */
    public function spend(DataTableRequest $dt, string $group, string $from, string $to, ?int $siteId = null): array
    {
        [$source, $params] = $this->spendSource($group, $from, $to, $siteId);

        return $this->paginate($dt, 'x.*', $source, [], $params, [], [], ['x.label']);
    }

    public function spendTotals(string $from, string $to, ?int $siteId = null): array
    {
        [$source, $params] = $this->spendSource('site', $from, $to, $siteId);

        return $this->db->fetchOne(
            "SELECT COALESCE(SUM(x.requested), 0) AS requested, COALESCE(SUM(x.approved), 0) AS approved, COALESCE(SUM(x.engaged), 0) AS engaged,
                    COALESCE(SUM(x.paid), 0) AS paid, COALESCE(SUM(x.remaining), 0) AS remaining, COALESCE(SUM(x.advances), 0) AS advances FROM $source",
            $params
        ) ?? [];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function spendSource(string $group, string $from, string $to, ?int $siteId): array
    {
        $pdo = $this->db->pdo();
        $f = $pdo->quote($from . ' 00:00:00');
        $t = $pdo->quote($to . ' 23:59:59');
        [$scopeP, $paramsP] = $this->scope->clause('p.site_id', 'sp');
        [$scopeO, $paramsO] = $this->scope->clause('o.site_id', 'so');
        $siteP = $siteId !== null ? ' AND p.site_id = ' . (int) $siteId : '';
        $siteO = $siteId !== null ? ' AND o.site_id = ' . (int) $siteId : '';
        $dimP = ['site' => 's.name', 'project' => "COALESCE(pj.code, '—')", 'cost_center' => "COALESCE(cc.code, '—')", 'category' => 'p.category'][$group];
        $dimO = ['site' => 's.name', 'project' => "COALESCE(pj.code, '—')", 'cost_center' => "COALESCE(cc.code, '—')", 'category' => 'r.category'][$group];
        $sql = "(SELECT z.label, SUM(z.requested) AS requested, SUM(z.approved) AS approved, SUM(z.engaged) AS engaged, SUM(z.paid) AS paid,
                        GREATEST(0, SUM(z.engaged) - SUM(z.paid)) AS remaining, SUM(z.advances) AS advances
                   FROM (
                        SELECT $dimP AS label,
                               SUM(IF(p.status NOT IN ('cancelled', 'rejected') AND p.requested_at BETWEEN $f AND $t, p.amount, 0)) AS requested,
                               SUM(IF(p.status IN ('approved', 'paid') AND p.approved_at BETWEEN $f AND $t, p.amount, 0)) AS approved,
                               SUM(IF(p.kind <> 'invoice' AND p.status IN ('approved', 'paid') AND p.approved_at BETWEEN $f AND $t, p.amount, 0)) AS engaged,
                               SUM(IF(p.status = 'paid' AND p.paid_at BETWEEN $f AND $t, p.amount, 0)) AS paid,
                               SUM(IF(p.kind = 'advance' AND p.status = 'paid' AND p.paid_at <= $t, p.amount - p.justified_amount - p.returned_amount, 0)) AS advances
                          FROM payment_requests p
                          JOIN sites s ON s.id = p.site_id
                          LEFT JOIN projects pj ON pj.id = p.project_id
                          LEFT JOIN cost_centers cc ON cc.id = p.cost_center_id
                         WHERE $scopeP$siteP GROUP BY label
                        UNION ALL
                        SELECT $dimO AS label, 0, 0, SUM(o.total_amount), 0, 0
                          FROM purchase_orders o
                          JOIN purchase_requests r ON r.id = o.request_id
                          JOIN sites s ON s.id = o.site_id
                          LEFT JOIN projects pj ON pj.id = r.project_id
                          LEFT JOIN cost_centers cc ON cc.id = r.cost_center_id
                         WHERE o.status <> 'cancelled' AND o.ordered_at BETWEEN $f AND $t AND $scopeO$siteO GROUP BY label
                   ) z GROUP BY z.label
                   HAVING SUM(z.requested) + SUM(z.approved) + SUM(z.engaged) + SUM(z.paid) + SUM(z.advances) > 0) x";

        return [$sql, $paramsP + $paramsO];
    }
}
