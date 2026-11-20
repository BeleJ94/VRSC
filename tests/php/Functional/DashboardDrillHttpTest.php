<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Listes de détail des tableaux de bord : périmètre, filtres, droits, liens de ligne ; fiches compactes dans les vues. */
final class DashboardDrillHttpTest extends DatabaseTestCase
{
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private int $site;
    private int $other;
    private int $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->other = $this->createSite('LIKASI');
        $this->requester = $this->createUser('requester', [$this->site]);
    }

    private function insertLoose(string $table, array $values): int
    {
        foreach ($this->db->fetchAll("SHOW COLUMNS FROM $table") as $column) {
            $name = $column['Field'];
            if (array_key_exists($name, $values) || $column['Null'] === 'YES' || $column['Default'] !== null || $column['Extra'] !== '') {
                continue;
            }
            $type = strtolower($column['Type']);
            $values[$name] = match (true) {
                str_starts_with($type, 'enum') => trim(explode(',', substr($type, 5))[0], "'()"),
                str_contains($type, 'int'), str_contains($type, 'decimal') => 0,
                str_starts_with($type, 'date') => date('Y-m-d'),
                default => 'x' . bin2hex(random_bytes(3)),
            };
        }
        $columns = array_keys($values);

        return $this->db->insert("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')', $values);
    }

    private function json(int $user, string $path): array
    {
        $response = $this->http(Session::memory(['auth_user_id' => $user]), 'GET', $path, [], self::AJAX);
        self::assertSame(200, $response->status, $path . ' ' . substr($response->body, 0, 300));

        return json_decode($response->body, true);
    }

    public function testPaymentsAndInvoicesStayInScopeAndFollowFilters(): void
    {
        $now = date('Y-m-d H:i:s');
        $paidK = $this->insertLoose('payment_requests', ['reference' => 'DDP-K', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'paid', 'amount' => 100, 'paid_at' => $now, 'approved_at' => $now, 'requested_by' => $this->requester, 'beneficiary' => 'Hôtel <b>X</b>']);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-L', 'site_id' => $this->other, 'kind' => 'expense', 'status' => 'paid', 'amount' => 70, 'paid_at' => $now, 'approved_at' => $now, 'requested_by' => $this->requester]);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-A', 'site_id' => $this->site, 'kind' => 'advance', 'status' => 'approved', 'amount' => 30, 'approved_at' => $now, 'requested_by' => $this->requester]);

        $finance = $this->createUser('finance', [$this->site]);
        $rows = $this->json($finance, '/dashboard/drill/payments?draw=1&status=paid&date_field=paid&from=' . date('Y-m-01') . '&to=' . date('Y-m-d'))['data'];
        self::assertSame(['DDP-K'], array_column($rows, 'reference'), 'Likasi hors périmètre, avance non payée exclue');
        self::assertSame('Hôtel <b>X</b>', $rows[0]['party'], 'Données brutes : l\'échappement est fait à l\'affichage');
        self::assertStringEndsWith('/finance/payments/' . $paidK, $rows[0]['link']);
        self::assertSame(['DDP-A'], array_column($this->json($finance, '/dashboard/drill/payments?draw=1&status=approved')['data'], 'reference'));
        self::assertSame(2, $this->json($finance, '/dashboard/drill/payments?draw=1&status=paid,approved')['recordsFiltered']);
        // Hors période : rien.
        self::assertSame(0, $this->json($finance, '/dashboard/drill/payments?draw=1&status=paid&date_field=paid&from=2020-01-01&to=2020-01-31')['recordsFiltered']);
        // Filtre injecté : refusé silencieusement (valeur ignorée).
        self::assertSame(3 - 0, $this->json($this->createUser('finance', [], true), "/dashboard/drill/payments?draw=1&status=paid'--")['recordsFiltered'], 'Filtre invalide ignoré');

        // Factures : reste à payer et échues.
        $supplier = $this->db->insert("INSERT INTO suppliers (code, name) VALUES ('FRN-1', 'Katanga Diesel')");
        $order = $this->insertLoose('purchase_orders', ['reference' => 'BC-1', 'site_id' => $this->site, 'supplier_id' => $supplier, 'total_amount' => 500, 'ordered_at' => $now,
            'request_id' => $this->insertLoose('purchase_requests', ['reference' => 'EDB-1', 'site_id' => $this->site, 'requested_by' => $this->requester])]);
        $invoice = $this->insertLoose('supplier_invoices', ['supplier_id' => $supplier, 'invoice_number' => 'F-1', 'invoice_date' => date('Y-m-d', strtotime('-40 days')),
            'due_date' => date('Y-m-d', strtotime('-10 days')), 'amount' => 500, 'order_id' => $order, 'site_id' => $this->site, 'status' => 'recorded']);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-F', 'site_id' => $this->site, 'kind' => 'invoice', 'invoice_id' => $invoice, 'status' => 'paid', 'amount' => 200, 'paid_at' => $now, 'requested_by' => $this->requester]);
        $unpaid = $this->json($finance, '/dashboard/drill/invoices?draw=1&unpaid=1')['data'];
        self::assertSame([['F-1', 200.0, 300.0]], array_map(static fn (array $r): array => [$r['reference'], (float) $r['paid'], (float) $r['unpaid']], $unpaid));
        self::assertSame(1, $this->json($finance, '/dashboard/drill/invoices?draw=1&overdue=1')['recordsFiltered']);
        self::assertStringEndsWith('/purchases/' . $order, $unpaid[0]['link']);
        // Engagements : la commande apparaît, avec sa catégorie d'EDB.
        self::assertContains('BC-1', array_column($this->json($finance, '/dashboard/drill/commitments?draw=1&from=' . date('Y-m-01') . '&to=' . date('Y-m-d'))['data'], 'reference'));
    }

    public function testCostLinesAndStockValuesAndRights(): void
    {
        $truck = $this->createEquipment($this->site, 'CAM-01', 'available', 100);
        $part = $this->createPart('GO-1', 2.0);
        $this->db->execute('INSERT INTO part_stocks (part_id, site_id, quantity, avg_cost) VALUES (:p, :s, 10, 2.5)', ['p' => $part, 's' => $this->site]);
        $this->db->execute("INSERT INTO equipment_charges (equipment_id, site_id, charge_date, label, amount) VALUES (:e, :s, CURDATE(), 'Location', 650)", ['e' => $truck, 's' => $this->site]);

        $manager = $this->createUser('manager', [$this->site]);
        $lines = $this->json($manager, '/dashboard/drill/cost-lines?draw=1&from=' . date('Y-m-01') . '&to=' . date('Y-m-d') . '&equipment_id=' . $truck)['data'];
        self::assertSame([['Location', 650.0]], array_map(static fn (array $r): array => [$r['label'], (float) $r['amount']], $lines));
        self::assertStringEndsWith('/costs/' . $truck, $lines[0]['link']);
        self::assertSame(0, $this->json($manager, '/dashboard/drill/cost-lines?draw=1&missing=1')['recordsFiltered']);
        $stock = $this->json($this->createUser('storekeeper', [$this->site]), '/dashboard/drill/stock-values?draw=1')['data'];
        self::assertSame([['GO-1', 25.0]], array_map(static fn (array $r): array => [$r['reference'], (float) $r['value']], $stock));

        // Droits : chaque liste exige le droit de son module.
        $requester = Session::memory(['auth_user_id' => $this->requester]);
        self::assertSame(403, $this->http($requester, 'GET', '/dashboard/drill/payments', [], self::AJAX)->status);
        self::assertSame(403, $this->http($requester, 'GET', '/dashboard/drill/cost-lines', [], self::AJAX)->status);
        self::assertSame(403, $this->http(Session::memory(['auth_user_id' => $this->createUser('technician', [$this->site])]), 'GET', '/dashboard/drill/invoices', [], self::AJAX)->status);
        // Hors périmètre : l'engin d'un autre site n'apparaît pas.
        self::assertSame(0, $this->json($this->createUser('manager', [$this->other]), '/dashboard/drill/cost-lines?draw=1&equipment_id=' . $truck)['recordsFiltered']);
    }

    public function testDashboardElementsCarryDetailSpecs(): void
    {
        // Un paiement du mois : la mini-courbe (et ses points cliquables) s'affiche.
        $now = date('Y-m-d H:i:s');
        $this->insertLoose('payment_requests', ['reference' => 'DDP-1', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'paid', 'amount' => 100, 'paid_at' => $now, 'approved_at' => $now, 'requested_by' => $this->requester]);
        $html = $this->http(Session::memory(['auth_user_id' => $this->createUser('director', [], true)]), 'GET', '/?view=executive')->body;
        foreach (['/dashboard/drill/commitments', '/dashboard/drill/payments', '/dashboard/drill/cost-lines', '/dashboard/drill/stock-values', '/edb/data', 'data-peek=', 'xkpi__point'] as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        $finance = $this->http(Session::memory(['auth_user_id' => $this->createUser('finance', [], true)]), 'GET', '/?view=finance')->body;
        foreach (['/dashboard/drill/invoices', 'unpaid', 'data-peek='] as $needle) {
            self::assertStringContainsString($needle, $finance, $needle);
        }
    }
}
