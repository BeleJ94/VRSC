<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Lot 2 vu par HTTP : écrans de stock, transfert et inventaire de bout en bout, « Mes validations », droits. */
final class StockHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private int $kolwezi;
    private int $likasi;
    private int $admin;
    private int $keeperK;
    private int $keeperL;
    private int $logistics;
    private int $part;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kolwezi = $this->createSite('KOLWEZI');
        $this->likasi = $this->createSite('LIKASI');
        $this->admin = $this->createUser('admin');
        $this->keeperK = $this->createUser('storekeeper', [$this->kolwezi]);
        $this->keeperL = $this->createUser('storekeeper', [$this->likasi]);
        $this->logistics = $this->createUser('logistics', [], true);
        $this->part = $this->createPart('FLT-100', 12.0);
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

    public function testStockScreensRenderAndReceiptIsEnteredFromThePartPage(): void
    {
        $session = $this->session($this->keeperK);
        $res = $this->http($session, 'POST', "/parts/{$this->part}/movements", self::CSRF + ['site_id' => $this->kolwezi, 'type' => 'receipt', 'quantity' => '10', 'unit_cost' => '20']);
        self::assertSame(302, $res->status);
        // Ajustement direct refusé à l'écran, et pas d'écriture sur le magasin d'un autre site.
        $this->http($session, 'POST', "/parts/{$this->part}/movements", self::CSRF + ['site_id' => $this->kolwezi, 'type' => 'adjust', 'quantity' => '3']);
        self::assertSame(403, $this->http($session, 'POST', "/parts/{$this->part}/movements", self::CSRF + ['site_id' => $this->likasi, 'type' => 'receipt', 'quantity' => '5'])->status);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM stock_movements'));

        foreach (['/stock', '/stock?consolidated=1', '/stock/journal', '/stock/transfers', '/stock/transfers/create', "/stock/transfers/create?part_id={$this->part}",
            '/stock/inventories', '/stock/periods', '/approvals', "/parts/{$this->part}"] as $path) {
            $page = $this->http($session, 'GET', $path);
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString('</html>', $page->body, $path);
        }
        $statement = $this->json($session, '/stock/data?draw=1&period=' . date('Y-m'));
        self::assertSame([['FLT-100', 'Site KOLWEZI', 10.0, 200.0]], array_map(static fn (array $r) => [$r['reference'], $r['site'], (float) $r['qty_close'], (float) $r['value_close']], $statement['data']));
        self::assertSame(1, $this->json($session, '/stock/data?draw=1&consolidated=1')['recordsTotal']);
        self::assertSame(0, $this->json($session, '/stock/data?draw=1&level=out')['recordsFiltered']);
        $journal = $this->json($session, '/stock/journal/data?draw=1&type=receipt');
        self::assertSame(['receipt', 10.0], [$journal['data'][0]['type'], (float) $journal['data'][0]['delta']]);
        self::assertSame(0, $this->json($session, '/stock/journal/data?draw=1&type=out')['recordsFiltered']);

        // Un technicien n'a pas accès au module ; un lecteur consulte mais n'écrit pas.
        self::assertSame(403, $this->http($this->session($this->createUser('technician', [$this->kolwezi])), 'GET', '/stock')->status);
        $viewer = $this->session($this->createUser('viewer', [$this->kolwezi]));
        self::assertSame(200, $this->http($viewer, 'GET', '/stock')->status);
        self::assertSame(403, $this->http($viewer, 'GET', '/stock/transfers/create')->status);
        self::assertSame(403, $this->http($viewer, 'POST', '/stock/inventories', self::CSRF + ['site_id' => $this->kolwezi])->status);
        self::assertSame(419, $this->http($session, 'POST', '/stock/transfers', ['from_site_id' => $this->kolwezi])->status);
    }

    public function testTransferEndToEndOverHttp(): void
    {
        $this->http($this->session($this->keeperK), 'POST', "/parts/{$this->part}/movements", self::CSRF + ['site_id' => $this->kolwezi, 'type' => 'receipt', 'quantity' => '10']);

        // Demande par le magasin bénéficiaire.
        $likasi = $this->session($this->keeperL);
        $res = $this->http($likasi, 'POST', '/stock/transfers', self::CSRF + ['from_site_id' => $this->kolwezi, 'to_site_id' => $this->likasi, 'reason' => 'Rupture',
            'lines' => [['part_id' => $this->part, 'quantity' => '4'], ['part_id' => '', 'quantity' => '']]]);
        self::assertSame(302, $res->status);
        $id = (int) $this->value('SELECT id FROM stock_transfers');
        self::assertStringEndsWith("/stock/transfers/$id", $res->headers['Location']);
        $page = $this->http($likasi, 'GET', "/stock/transfers/$id")->body;
        self::assertStringContainsString('BT-', $page);
        self::assertStringNotContainsString('/act"', $page, 'Le demandeur ne valide pas sa demande');

        // Autorisation par la Direction Logistique depuis « Mes validations ».
        $logistics = $this->session($this->logistics);
        $inbox = $this->http($logistics, 'GET', '/approvals')->body;
        self::assertStringContainsString("/stock/transfers/$id#approval", $inbox);
        $request = (int) $this->value('SELECT id FROM approval_requests');
        self::assertStringContainsString("/approvals/$request/act", $this->http($logistics, 'GET', "/stock/transfers/$id")->body);
        // Le magasinier d'origine n'autorise pas : refusé (403) ou demande invisible hors de son périmètre (404).
        self::assertContains($this->http($this->session($this->keeperK), 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable'])->status, [403, 404]);
        self::assertSame('requested', $this->value('SELECT status FROM stock_transfers'));
        $res = $this->http($logistics, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        self::assertStringEndsWith("/stock/transfers/$id", $res->headers['Location']);
        self::assertSame('authorized', $this->value('SELECT status FROM stock_transfers'));
        self::assertStringNotContainsString("/stock/transfers/$id", $this->http($logistics, 'GET', '/approvals')->body);

        // Expédition par Kolwezi, réception par Likasi.
        $kolwezi = $this->session($this->keeperK);
        self::assertStringContainsString("/stock/transfers/$id/ship", $this->http($kolwezi, 'GET', "/stock/transfers/$id")->body);
        self::assertSame(403, $this->http($likasi, 'POST', "/stock/transfers/$id/ship", self::CSRF)->status);
        $this->http($kolwezi, 'POST', "/stock/transfers/$id/ship", self::CSRF + ['shipped' => [$this->part => '4']]);
        self::assertSame('shipped', $this->value('SELECT status FROM stock_transfers'));
        self::assertSame(4.0, (float) $this->json($this->session($this->admin), '/stock/data?draw=1&consolidated=1')['data'][0]['in_transit']);

        self::assertStringContainsString("/stock/transfers/$id/receive", $this->http($likasi, 'GET', "/stock/transfers/$id")->body);
        $this->http($likasi, 'POST', "/stock/transfers/$id/receive", self::CSRF + ['received' => [$this->part => '4']]);
        self::assertSame(['received', 0], [$this->value('SELECT status FROM stock_transfers'), (int) $this->value('SELECT has_discrepancy FROM stock_transfers')]);
        $rows = array_column($this->json($this->session($this->admin), '/stock/data?draw=1')['data'], 'qty_close', 'site');
        self::assertSame(['Site KOLWEZI' => 6.0, 'Site LIKASI' => 4.0], array_map('floatval', $rows));
    }

    public function testInventoryAndPeriodClosingOverHttp(): void
    {
        $kolwezi = $this->session($this->keeperK);
        $this->http($kolwezi, 'POST', "/parts/{$this->part}/movements", self::CSRF + ['site_id' => $this->kolwezi, 'type' => 'receipt', 'quantity' => '10',
            'moved_on' => date('Y-m-d', strtotime(date('Y-m-01') . ' -10 days'))]);

        $this->http($kolwezi, 'POST', '/stock/inventories', self::CSRF + ['site_id' => $this->kolwezi]);
        $id = (int) $this->value('SELECT id FROM stock_inventories');
        self::assertStringContainsString("counted[{$this->part}]", $this->http($kolwezi, 'GET', "/stock/inventories/$id")->body);
        $this->http($kolwezi, 'POST', "/stock/inventories/$id/counts", self::CSRF + ['counted' => [$this->part => '9'], 'justification' => [$this->part => 'Casse'], 'submit' => '1']);
        self::assertSame(['submitted', 10.0], [$this->value('SELECT status FROM stock_inventories'), (float) $this->value('SELECT quantity FROM part_stocks')]);
        $page = $this->http($kolwezi, 'GET', "/stock/inventories/$id")->body;
        self::assertStringNotContainsString("counted[{$this->part}]", $page, 'Comptage verrouillé après soumission');

        // Clôture impossible tant qu'un inventaire est en attente sur la période.
        $logistics = $this->session($this->logistics);
        $lastMonth = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $this->db->execute('UPDATE stock_inventories SET counted_on = :d', ['d' => $lastMonth . '-28']);
        $this->http($logistics, 'POST', '/stock/periods', self::CSRF + ['site_id' => $this->kolwezi, 'period' => $lastMonth], ['referer' => 'http://localhost/stock/periods']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM stock_periods'));

        $request = (int) $this->value('SELECT id FROM approval_requests');
        $this->http($logistics, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        self::assertSame(['approved', 9.0], [$this->value('SELECT status FROM stock_inventories'), (float) $this->value('SELECT quantity FROM part_stocks')]);

        self::assertSame(403, $this->http($kolwezi, 'POST', '/stock/periods', self::CSRF + ['site_id' => $this->kolwezi, 'period' => $lastMonth])->status);
        $this->http($logistics, 'POST', '/stock/periods', self::CSRF + ['site_id' => $this->kolwezi, 'period' => $lastMonth]);
        self::assertSame($lastMonth, $this->value('SELECT period FROM stock_periods'));
        $periods = $this->http($logistics, 'GET', '/stock/periods')->body;
        self::assertStringContainsString('Clôturée', $periods);
        self::assertSame(200, $this->http($logistics, 'GET', "/stock?site_id={$this->kolwezi}&period=$lastMonth")->status);
    }
}
