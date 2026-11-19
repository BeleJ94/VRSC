<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use Tests\DatabaseTestCase;

/** Lot 5 vu par HTTP : écrans de finance, décaissement de bout en bout avec preuve, caisse, droits. */
final class FinanceHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private int $site;
    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->db->execute("INSERT IGNORE INTO payment_methods (code, name) VALUES ('CAISSE', 'Espèces (caisse)'), ('VIREMENT', 'Virement bancaire')");
        $this->pdf = sys_get_temp_dir() . '/vrsc-http-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        // Les preuves déposées par ce test vont dans le stockage réel : on les retire.
        foreach ($this->db->fetchAll('SELECT file_name FROM attachments') as $row) {
            @unlink(dirname(__DIR__, 3) . '/storage/uploads/attachments/' . basename((string) $row['file_name']));
        }
        @unlink($this->pdf);
        parent::tearDown();
    }

    private function session(string $role, array $sites = [], bool $all = false): Session
    {
        return Session::memory(['auth_user_id' => $this->createUser($role, $sites, $all), '_csrf_token' => self::TOKEN]);
    }

    /** Requête POST avec un fichier envoyé (preuve de paiement). */
    private function upload(Session $session, string $path, array $body, string $field): Response
    {
        $files = [$field => ['tmp_name' => $this->pdf, 'name' => 'preuve.pdf', 'size' => filesize($this->pdf), 'error' => UPLOAD_ERR_OK]];
        $request = new Request('POST', $path, [], self::CSRF + $body, [], ['REMOTE_ADDR' => '127.0.0.1'], $files);

        return App::create(self::config(), $session, $this->db, $request)->handle($request);
    }

    public function testDirectExpensePaidInCashOverHttp(): void
    {
        $cashier = $this->session('cashier', [$this->site]);
        self::assertSame(200, $this->http($cashier, 'GET', '/finance/cash')->status);
        $this->http($cashier, 'POST', '/finance/cash', self::CSRF + ['site_id' => $this->site, 'name' => 'Caisse principale']);
        $register = (int) $this->value('SELECT id FROM cash_registers');
        $this->http($cashier, 'POST', "/finance/cash/$register/entries", self::CSRF + ['direction' => 'in', 'amount' => '500', 'label' => 'Approvisionnement']);
        self::assertSame(500.0, (float) $this->value('SELECT balance FROM cash_registers'));

        // Demande de dépense directe.
        $requester = $this->session('requester', [$this->site]);
        self::assertSame(200, $this->http($requester, 'GET', '/finance/payments/create?kind=expense')->status);
        $res = $this->http($requester, 'POST', '/finance/payments', self::CSRF + ['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'Garage <i>Kasongo</i>',
            'amount' => '120', 'reason' => 'Réparation crevaison', 'category' => 'maintenance']);
        $id = (int) $this->value('SELECT id FROM payment_requests');
        self::assertStringEndsWith("/finance/payments/$id", $res->headers['Location']);
        self::assertSame(403, $this->http($requester, 'GET', "/finance/payments/$id")->status, 'Le demandeur n\'a pas accès au module Finance');

        // Vérification Finance, approbation Direction, depuis la page de la demande.
        $request = (int) $this->value('SELECT id FROM approval_requests');
        $finance = $this->session('finance', [], true);
        $page = $this->http($finance, 'GET', "/finance/payments/$id")->body;
        self::assertStringContainsString('Garage &lt;i&gt;Kasongo&lt;/i&gt;', $page);
        self::assertStringContainsString("/approvals/$request/act", $page);
        self::assertStringNotContainsString("/finance/payments/$id/pay", $page);
        $this->http($finance, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        $director = $this->session('director', [], true);
        $res = $this->http($director, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        self::assertStringEndsWith("/finance/payments/$id", $res->headers['Location']);
        self::assertSame('approved', $this->value('SELECT status FROM payment_requests'));

        // Paiement en espèces par le caissier, avec preuve.
        $page = $this->http($cashier, 'GET', "/finance/payments/$id")->body;
        self::assertStringContainsString("/finance/payments/$id/pay", $page);
        $caisse = (int) $this->value("SELECT id FROM payment_methods WHERE code = 'CAISSE'");
        self::assertSame(403, $this->http($finance, 'POST', "/finance/payments/$id/pay", self::CSRF + ['payment_method_id' => $caisse, 'cash_register_id' => $register])->status);
        $this->http($cashier, 'POST', "/finance/payments/$id/pay", self::CSRF + ['payment_method_id' => $caisse, 'cash_register_id' => $register]);
        self::assertSame('approved', $this->value('SELECT status FROM payment_requests'), 'Sans preuve, pas de paiement');
        $res = $this->upload($cashier, "/finance/payments/$id/pay", ['payment_method_id' => $caisse, 'cash_register_id' => $register], 'proof');
        self::assertSame(302, $res->status);
        self::assertSame(['paid', 380.0], [$this->value('SELECT status FROM payment_requests'), (float) $this->value('SELECT balance FROM cash_registers')]);

        $page = $this->http($cashier, 'GET', "/finance/payments/$id")->body;
        self::assertStringContainsString('Preuve de paiement', $page);
        $attachment = (int) $this->value('SELECT id FROM attachments');
        $file = $this->http($cashier, 'GET', "/attachments/$attachment");
        self::assertSame([200, 'application/pdf'], [$file->status, $file->headers['Content-Type']]);
        self::assertSame(404, $this->http($this->session('cashier', [$this->createSite('LIKASI')]), 'GET', "/attachments/$attachment")->status, 'Pièce d\'un autre site');

        // Journal de caisse et arrêté.
        $journal = $this->http($cashier, 'GET', "/finance/cash?register_id=$register")->body;
        self::assertStringContainsString('PC-', $journal);
        self::assertStringContainsString("/finance/cash/$register/close", $journal);
        $this->http($cashier, 'POST', "/finance/cash/$register/close", self::CSRF + ['closing_date' => date('Y-m-d'), 'counted_balance' => '380']);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM cash_closings'));
        $closed = $this->http($cashier, 'GET', "/finance/cash?register_id=$register")->body;
        self::assertStringNotContainsString("/finance/cash/$register/close", $closed);
        self::assertStringContainsString('Journée arrêtée', $closed);
    }

    public function testScreensReportsAndRights(): void
    {
        $finance = $this->session('finance', [], true);
        foreach (['/finance', '/finance/payments', '/finance/payments?status=approved', '/finance/payments?to_justify=1', '/finance/payments/create',
            '/finance/payments/create?kind=advance', '/finance/payments/create?kind=invoice', '/finance/invoices', '/finance/invoices?unpaid=1',
            '/finance/cash', '/finance/reports', '/finance/reports?group=category', '/finance/reconciliation', '/finance/reconciliation?anomalies=1'] as $path) {
            $page = $this->http($finance, 'GET', $path);
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString('</html>', $page->body, $path);
        }
        $data = json_decode($this->http($finance, 'GET', '/finance/reports/data?draw=1&group=site&period=' . date('Y-m'), [], self::AJAX)->body, true);
        self::assertSame(0, $data['recordsTotal']);
        self::assertStringContainsString('data-table="finance_summary"', $this->http($finance, 'GET', '/finance/reports')->body);

        // Menu et droits : la Finance voit le module, un technicien non ; la Finance ne paie pas, le caissier ne saisit pas de facture.
        self::assertStringContainsString('/finance/payments"', $this->http($finance, 'GET', '/')->body);
        $technician = $this->session('technician', [$this->site]);
        self::assertStringNotContainsString('/finance"', $this->http($technician, 'GET', '/work-orders')->body);
        self::assertSame(403, $this->http($technician, 'GET', '/finance')->status);
        $cashier = $this->session('cashier', [$this->site]);
        self::assertSame(403, $this->http($cashier, 'POST', '/finance/invoices', self::CSRF + ['order_id' => 1])->status);
        self::assertSame(403, $this->http($cashier, 'GET', '/finance/payments/create')->status);
        self::assertSame(403, $this->http($this->session('buyer', [], true), 'POST', '/finance/cash', self::CSRF + ['site_id' => $this->site, 'name' => 'x'])->status);
        self::assertSame(419, $this->http($finance, 'POST', '/finance/payments', ['kind' => 'expense'])->status);

        // Le rôle « Direction générale » existe et se choisit dans la fiche utilisateur.
        self::assertStringContainsString('Direction générale', $this->http($this->session('admin'), 'GET', '/users/create')->body);
    }
}
