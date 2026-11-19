<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Lot 3 vu par HTTP : EDB de bout en bout, écrans, rapports mensuels et droits. */
final class PurchaseHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private int $site;
    private int $requester;
    private int $supervisor;
    private int $logistics;
    private int $buyer;
    private int $keeper;
    private int $part;
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->requester = $this->createUser('requester', [$this->site]);
        $this->supervisor = $this->createUser('supervisor', [$this->site]);
        $this->logistics = $this->createUser('logistics', [], true);
        $this->buyer = $this->createUser('buyer', [], true);
        $this->keeper = $this->createUser('storekeeper', [$this->site]);
        $this->part = $this->createPart('FLT-100', 20.0);
        $this->supplier = $this->db->insert("INSERT INTO suppliers (code, name) VALUES ('FRN-A', 'Katanga <b>Diesel</b>')");
    }

    private function session(int $userId): Session
    {
        return Session::memory(['auth_user_id' => $userId, '_csrf_token' => self::TOKEN]);
    }

    private function json(Session $session, string $path): array
    {
        $response = $this->http($session, 'GET', $path, [], self::AJAX);
        self::assertSame(200, $response->status, $path . ' ' . substr($response->body, 0, 300));

        return json_decode($response->body, true);
    }

    public function testRequestToExecutionOverHttp(): void
    {
        $requester = $this->session($this->requester);
        self::assertSame(200, $this->http($requester, 'GET', '/edb/create')->status);
        $res = $this->http($requester, 'POST', '/edb', self::CSRF + [
            'site_id' => $this->site, 'category' => 'parts', 'urgency' => 'critical', 'subject' => 'Filtres <script>x</script>', 'reason' => 'Révision 500 h',
            'lines' => [['part_id' => $this->part, 'quantity' => '5', 'est_unit_cost' => '20'], ['part_id' => '', 'description' => '', 'quantity' => '']],
        ]);
        self::assertSame(302, $res->status);
        $id = (int) $this->value('SELECT id FROM purchase_requests');
        $page = $this->http($requester, 'GET', "/edb/$id")->body;
        self::assertStringContainsString('EDB-', $page);
        self::assertStringContainsString('Filtres &lt;script&gt;', $page);
        self::assertStringNotContainsString('<script>x</script>', $page);
        self::assertStringNotContainsString("/edb/$id/quotes\"", $page, 'Rien à acheter tant que l\'EDB est en attente');
        self::assertStringContainsString("/edb/$id/cancel", $page);

        // Visa puis approbation depuis la page de l'EDB.
        $request = (int) $this->value('SELECT id FROM approval_requests');
        $supervisor = $this->session($this->supervisor);
        self::assertStringContainsString("/edb/$id#approval", $this->http($supervisor, 'GET', '/approvals')->body);
        $this->http($supervisor, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        $res = $this->http($this->session($this->logistics), 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        self::assertStringEndsWith("/edb/$id", $res->headers['Location']);
        self::assertSame('approved', $this->value('SELECT status FROM purchase_requests'));

        // Achat : devis, sélection, commande.
        $buyer = $this->session($this->buyer);
        $page = $this->http($buyer, 'GET', "/edb/$id")->body;
        self::assertStringContainsString("/edb/$id/quotes", $page);
        self::assertStringContainsString('Katanga &lt;b&gt;Diesel&lt;/b&gt;', $page);
        self::assertSame(403, $this->http($requester, 'POST', "/edb/$id/quotes", self::CSRF + ['supplier_id' => $this->supplier, 'amount' => '100'])->status);
        $this->http($buyer, 'POST', "/edb/$id/quotes", self::CSRF + ['supplier_id' => $this->supplier, 'amount' => '100', 'delivery_days' => '3']);
        $quote = (int) $this->value('SELECT id FROM purchase_quotes');
        $this->http($buyer, 'POST', "/edb/$id/quotes/select", self::CSRF + ['quote_id' => $quote]);
        $line = (int) $this->value('SELECT id FROM purchase_request_lines');
        self::assertStringContainsString("lines[$line][quantity]", $this->http($buyer, 'GET', "/edb/$id")->body);
        $res = $this->http($buyer, 'POST', "/edb/$id/orders", self::CSRF + ['lines' => [$line => ['quantity' => '5', 'unit_price' => '20']]]);
        $order = (int) $this->value('SELECT id FROM purchase_orders');
        self::assertStringEndsWith("/purchases/$order", $res->headers['Location']);

        // Réception par le magasinier : partielle puis solde.
        $orderLine = (int) $this->value('SELECT id FROM purchase_order_lines');
        self::assertStringNotContainsString("/purchases/$order/receive", $this->http($buyer, 'GET', "/purchases/$order")->body);
        self::assertSame(403, $this->http($buyer, 'POST', "/purchases/$order/receive", self::CSRF + ['delivery_note' => 'BL', 'accepted' => [$orderLine => '5']])->status);
        $keeper = $this->session($this->keeper);
        self::assertStringContainsString("/purchases/$order/receive", $this->http($keeper, 'GET', "/purchases/$order")->body);
        $this->http($keeper, 'POST', "/purchases/$order/receive", self::CSRF + ['delivery_note' => 'BL-1', 'accepted' => [$orderLine => '2']]);
        self::assertSame(['approved', 'partially_received'], [$this->value('SELECT status FROM purchase_requests'), $this->value('SELECT status FROM purchase_orders')]);
        self::assertStringContainsString('exécution partielle', $this->http($requester, 'GET', "/edb/$id")->body);
        self::assertStringContainsString('BC-', $this->http($keeper, 'GET', '/purchases')->body);

        $open = $this->json($this->session($this->logistics), '/edb/data?draw=1&report=open&period=' . date('Y-m'));
        self::assertSame([['partial', 3.0, 60.0]], array_map(static fn (array $r) => [$r['display_status'], (float) $r['qty_remaining'], (float) $r['remaining_amount']], $open['data']));
        self::assertSame(0, $this->json($this->session($this->logistics), '/edb/data?draw=1&report=executed&period=' . date('Y-m'))['recordsFiltered']);

        $this->http($keeper, 'POST', "/purchases/$order/receive", self::CSRF + ['delivery_note' => 'BL-2', 'accepted' => [$orderLine => '3']]);
        self::assertSame(['executed', 'received'], [$this->value('SELECT status FROM purchase_requests'), $this->value('SELECT status FROM purchase_orders')]);
        self::assertSame(1, $this->json($this->session($this->logistics), '/edb/data?draw=1&report=executed&period=' . date('Y-m'))['recordsFiltered']);
        self::assertSame(0, $this->json($this->session($this->logistics), '/edb/data?draw=1&report=open&period=' . date('Y-m'))['recordsFiltered']);
        self::assertSame(5.0, (float) $this->value('SELECT quantity FROM part_stocks'));
    }

    public function testScreensReportsFiltersAndRights(): void
    {
        $this->http($this->session($this->requester), 'POST', '/edb', self::CSRF + ['site_id' => $this->site, 'category' => 'services', 'urgency' => 'normal',
            'subject' => 'Soudure', 'reason' => 'Châssis fissuré', 'lines' => [['description' => 'Soudure châssis', 'quantity' => '1', 'est_unit_cost' => '150']]]);
        $id = (int) $this->value('SELECT id FROM purchase_requests');

        $logistics = $this->session($this->logistics);
        foreach (['/edb', "/edb/$id", '/edb/reports', '/edb/reports?report=executed', '/edb/reports?report=open&period=' . date('Y-m'), '/purchases', '/'] as $path) {
            $page = $this->http($logistics, 'GET', $path);
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString('</html>', $page->body, $path);
        }
        $reports = $this->http($logistics, 'GET', '/edb/reports')->body;
        foreach (['Solde d&apos;ouverture', 'Nouveaux dossiers', 'Solde de clôture', 'Taux d&apos;exécution', 'Prestations', 'data-table="purchase_requests"'] as $needle) {
            self::assertStringContainsString($needle, $reports);
        }
        self::assertStringContainsString('data-table="purchase_execution"', $this->http($logistics, 'GET', '/edb/reports?report=open')->body);

        $list = $this->json($logistics, '/edb/data?draw=1');
        self::assertSame(['pending', 150.0, 'Soudure'], [$list['data'][0]['display_status'], (float) $list['data'][0]['estimated_amount'], $list['data'][0]['subject']]);
        self::assertSame(0, $this->json($logistics, '/edb/data?draw=1&status=executed')['recordsFiltered']);
        self::assertSame(1, $this->json($logistics, '/edb/data?draw=1&category=services&urgency=normal')['recordsFiltered']);
        self::assertSame(0, $this->json($logistics, '/edb/data?draw=1&report=issued&period=2020-01')['recordsFiltered']);
        self::assertSame(1, $this->json($logistics, '/edb/data?draw=1&requested_by=' . $this->requester)['recordsFiltered']);

        // Droits : un encodeur n'a pas accès ; un lecteur consulte mais n'émet pas ; pas de CSRF, pas d'écriture.
        self::assertSame(403, $this->http($this->session($this->createUser('encoder', [$this->site])), 'GET', '/edb')->status);
        $viewer = $this->session($this->createUser('viewer', [$this->site]));
        self::assertSame(200, $this->http($viewer, 'GET', "/edb/$id")->status);
        self::assertSame(403, $this->http($viewer, 'GET', '/edb/create')->status);
        self::assertSame(419, $this->http($this->session($this->requester), 'POST', '/edb', ['site_id' => $this->site])->status);
        self::assertSame(404, $this->http($this->session($this->createUser('requester', [$this->createSite('LIKASI')])), 'GET', "/edb/$id")->status);

        // Annulation motivée par le demandeur.
        $this->http($this->session($this->requester), 'POST', "/edb/$id/cancel", self::CSRF + ['cancel_reason' => 'Réparé en interne']);
        self::assertSame(['cancelled', 'Réparé en interne'], [$this->value('SELECT status FROM purchase_requests'), $this->value('SELECT cancel_reason FROM purchase_requests')]);
        self::assertStringContainsString('Réparé en interne', $this->http($logistics, 'GET', "/edb/$id")->body);
    }
}
