<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use App\Services\FleetTransferService;
use Tests\DatabaseTestCase;

/** Lot 4 vu par HTTP : transfert d'un engin de bout en bout, écrans, contenu du PDF OTV + check-list, droits. */
final class FleetTransferHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];

    private int $kolwezi;
    private int $likasi;
    private int $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kolwezi = $this->createSite('KOLWEZI');
        $this->likasi = $this->createSite('LIKASI');
        $this->truck = $this->createEquipment($this->kolwezi, 'CAM-01', 'available', 100);
    }

    private function session(string $role, array $sites = [], bool $all = false): Session
    {
        return Session::memory(['auth_user_id' => $this->createUser($role, $sites, $all), '_csrf_token' => self::TOKEN]);
    }

    private function checklist(string $conclusion): array
    {
        return ['results' => array_fill_keys(FleetTransferService::ITEMS, 'ok'), 'conclusion' => $conclusion, 'hour_meter' => '110'];
    }

    public function testTransferEndToEndOverHttp(): void
    {
        $requester = $this->session('requester', [$this->kolwezi]);
        self::assertSame(200, $this->http($requester, 'GET', "/fleet-transfers/create?equipment_id={$this->truck}")->status);
        $res = $this->http($requester, 'POST', '/fleet-transfers', self::CSRF + ['equipment_id' => $this->truck, 'to_site_id' => $this->likasi,
            'reason' => 'Renfort <b>Likasi</b>', 'transport_mode' => 'own_power', 'driver_name' => 'Kabila J.']);
        self::assertSame(302, $res->status);
        $id = (int) $this->value('SELECT id FROM fleet_transfers');
        self::assertStringEndsWith("/fleet-transfers/$id", $res->headers['Location']);
        $page = $this->http($requester, 'GET', "/fleet-transfers/$id")->body;
        self::assertStringContainsString('Renfort &lt;b&gt;Likasi&lt;/b&gt;', $page);
        self::assertStringNotContainsString("/fleet-transfers/$id/depart", $page);
        self::assertStringNotContainsString("/fleet-transfers/$id/check", $page);

        // PDF : l'OTV et la check-list portent le même numéro.
        self::assertSame(1, preg_match('#<script type="application/json" id="doc-data">(.*?)</script>#s', $page, $m));
        $doc = json_decode($m[1], true);
        $reference = (string) $this->value('SELECT reference FROM fleet_transfers');
        self::assertSame($reference, $doc['reference']);
        self::assertCount(2, $doc['pages']);
        self::assertStringContainsString($reference, $doc['pages'][0]['title']);
        self::assertStringContainsString($reference, $doc['pages'][1]['title']);
        self::assertCount(count(FleetTransferService::ITEMS), $doc['pages'][1]['tables'][0]['body']);
        self::assertStringContainsString('pm-doc.js', $page);

        // Avis hiérarchique depuis « Mes validations ».
        $request = (int) $this->value('SELECT id FROM approval_requests');
        $supervisor = $this->session('supervisor', [$this->kolwezi]);
        self::assertStringContainsString("/fleet-transfers/$id#approval", $this->http($supervisor, 'GET', '/approvals')->body);
        $this->http($supervisor, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);

        // Contrôle technique : pas de bouton d'approbation, seulement la check-list.
        $technician = $this->session('technician', [$this->kolwezi]);
        $page = $this->http($technician, 'GET', "/fleet-transfers/$id")->body;
        self::assertStringContainsString("/fleet-transfers/$id/check", $page);
        self::assertStringNotContainsString("/approvals/$request/act", $page);
        $logistics = $this->session('logistics', [], true);
        self::assertStringNotContainsString("/approvals/$request/act", $this->http($logistics, 'GET', "/fleet-transfers/$id")->body);
        $this->http($logistics, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM approval_actions'), 'Contrôle technique non contournable');

        $this->http($technician, 'POST', "/fleet-transfers/$id/check", self::CSRF + $this->checklist('fit'));
        self::assertSame('fit', $this->value('SELECT conclusion FROM fleet_transfer_checks'));

        // Autorisation, départ, réception.
        self::assertStringContainsString("/approvals/$request/act", $this->http($logistics, 'GET', "/fleet-transfers/$id")->body);
        $this->http($logistics, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        self::assertSame('authorized', $this->value('SELECT status FROM fleet_transfers'));

        $managerK = $this->session('manager', [$this->kolwezi]);
        self::assertStringContainsString("/fleet-transfers/$id/depart", $this->http($managerK, 'GET', "/fleet-transfers/$id")->body);
        self::assertSame(403, $this->http($technician, 'POST', "/fleet-transfers/$id/depart", self::CSRF + ['departure_hours' => '112', 'handover_to' => 'x'])->status);
        $this->http($managerK, 'POST', "/fleet-transfers/$id/depart", self::CSRF + ['departure_hours' => '112', 'handover_to' => 'Kabila J.']);
        self::assertSame(['departed', $this->kolwezi], [$this->value('SELECT status FROM fleet_transfers'), (int) $this->value('SELECT site_id FROM equipment')]);

        $managerL = $this->session('manager', [$this->likasi]);
        self::assertSame(404, $this->http($managerL, 'GET', "/equipment/{$this->truck}")->status, 'Pas encore sur le site de destination');
        self::assertStringContainsString("/fleet-transfers/$id/receive", $this->http($managerL, 'GET', "/fleet-transfers/$id")->body);
        $this->http($managerL, 'POST', "/fleet-transfers/$id/receive", self::CSRF + ['arrival_hours' => '118', 'results' => array_fill_keys(FleetTransferService::ITEMS, 'ok')]);
        self::assertSame(['received', $this->likasi], [$this->value('SELECT status FROM fleet_transfers'), (int) $this->value('SELECT site_id FROM equipment')]);
        self::assertSame(200, $this->http($managerL, 'GET', "/equipment/{$this->truck}")->status);
        self::assertSame(404, $this->http($managerK, 'GET', "/equipment/{$this->truck}")->status);

        $final = $this->http($managerK, 'GET', "/fleet-transfers/$id")->body;
        self::assertStringContainsString('site actuel', $final);
        self::assertStringContainsString('Procès-verbal d&apos;arrivée', $final);
    }

    public function testScreensFiltersAndRights(): void
    {
        $requester = $this->session('requester', [$this->kolwezi]);
        $this->http($requester, 'POST', '/fleet-transfers', self::CSRF + ['equipment_id' => $this->truck, 'to_site_id' => $this->likasi, 'reason' => 'Test', 'transport_mode' => 'own_power',
            'planned_departure' => date('Y-m-d\TH:i', strtotime('-2 days'))]);
        $id = (int) $this->value('SELECT id FROM fleet_transfers');

        $admin = $this->session('admin');
        foreach (['/fleet-transfers', '/fleet-transfers?stage=opinion', '/fleet-transfers?status=requested', '/fleet-transfers?late=1', '/fleet-transfers/create', "/fleet-transfers/$id", "/equipment/{$this->truck}"] as $path) {
            $page = $this->http($admin, 'GET', $path);
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString('</html>', $page->body, $path);
        }
        $list = $this->http($admin, 'GET', '/fleet-transfers')->body;
        self::assertStringContainsString('OTV-', $list);
        self::assertStringContainsString('En retard', $list);
        self::assertStringNotContainsString('OTV-', $this->http($admin, 'GET', '/fleet-transfers?stage=reception')->body);
        self::assertStringContainsString('/fleet-transfers/create?equipment_id=' . $this->truck, $this->http($admin, 'GET', "/equipment/{$this->truck}")->body);

        // La fiche engin ne permet plus de changer le site.
        $this->http($admin, 'POST', "/equipment/{$this->truck}", self::CSRF + ['site_id' => $this->likasi, 'code' => 'CAM-01', 'name' => 'Engin CAM-01'], ['referer' => "http://localhost/equipment/{$this->truck}/edit"]);
        self::assertSame($this->kolwezi, (int) $this->value('SELECT site_id FROM equipment'));

        // Droits et périmètre.
        self::assertSame(403, $this->http($this->session('encoder', [$this->kolwezi]), 'GET', '/fleet-transfers')->status);
        self::assertSame(403, $this->http($this->session('viewer', [$this->kolwezi]), 'GET', '/fleet-transfers/create')->status);
        self::assertSame(200, $this->http($this->session('viewer', [$this->likasi]), 'GET', "/fleet-transfers/$id")->status, 'Le site de destination suit le transfert');
        self::assertSame(404, $this->http($this->session('viewer', [$this->createSite('KIPUSHI')]), 'GET', "/fleet-transfers/$id")->status);
        self::assertSame(419, $this->http($requester, 'POST', '/fleet-transfers', ['equipment_id' => $this->truck])->status);

        $this->http($requester, 'POST', "/fleet-transfers/$id/cancel", self::CSRF + ['cancel_reason' => 'Annulé par le chantier']);
        self::assertSame('cancelled', $this->value('SELECT status FROM fleet_transfers'));
    }
}
