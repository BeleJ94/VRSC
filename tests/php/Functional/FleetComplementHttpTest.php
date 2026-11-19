<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Lot 7 vu par HTTP : fiche complète, remise en service validée depuis la fiche, documents (renouvellement, non applicable), droits. */
final class FleetComplementHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private int $site;
    private int $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->truck = $this->createEquipment($this->site, 'CAM-01', 'available', 1000);
    }

    private function session(string $role, array $sites = [], bool $all = false): Session
    {
        return Session::memory(['auth_user_id' => $this->createUser($role, $sites, $all), '_csrf_token' => self::TOKEN]);
    }

    private function json(Session $session, string $path): array
    {
        $response = $this->http($session, 'GET', $path, [], self::AJAX);
        self::assertSame(200, $response->status, $path . ' ' . substr($response->body, 0, 300));

        return json_decode($response->body, true);
    }

    public function testEquipmentSheetOverHttp(): void
    {
        $manager = $this->session('manager', [$this->site]);
        $project = $this->db->insert('INSERT INTO projects (site_id, code, name) VALUES (:s, :c, :n)', ['s' => $this->site, 'c' => 'PRJ-K1', 'n' => 'Route <b>RN39</b>']);

        $form = $this->http($manager, 'GET', '/equipment/create')->body;
        foreach (['name="year_made"', 'name="power_value"', 'name="capacity_unit"', 'name="color"', 'name="acquired_at"', 'name="owner_name"', 'name="project_id"',
            'name="fuel_reference"', 'name="not_applicable[]"', 'Route &lt;b&gt;RN39&lt;/b&gt;'] as $needle) {
            self::assertStringContainsString($needle, $form, $needle);
        }

        $res = $this->http($manager, 'POST', '/equipment', self::CSRF + ['site_id' => $this->site, 'code' => 'rem-01', 'name' => 'Remorque', 'year_made' => '2018',
            'color' => 'Bleu <i>nuit</i>', 'capacity_value' => '28', 'capacity_unit' => 't', 'project_id' => $project, 'fuel_reference' => '12', 'not_applicable' => ['power', 'serial_number']]);
        self::assertSame(302, $res->status);
        $id = (int) $this->value("SELECT id FROM equipment WHERE code = 'REM-01'");
        $page = $this->http($manager, 'GET', "/equipment/$id")->body;
        foreach (['Bleu &lt;i&gt;nuit&lt;/i&gt;', 'Non applicable', 'Information manquante', 'PRJ-K1', '2018', 'information(s) manquante(s)'] as $needle) {
            self::assertStringContainsString($needle, $page, $needle);
        }
        self::assertStringNotContainsString('<i>nuit</i>', $page);
        $edit = $this->http($manager, 'GET', "/equipment/$id/edit")->body;
        self::assertMatchesRegularExpression('/name="not_applicable\[\]" value="power" checked/', $edit);
        self::assertDoesNotMatchRegularExpression('/name="not_applicable\[\]" value="color" checked/', $edit);

        // Liste : filtre « informations manquantes » et projet.
        self::assertSame(200, $this->http($manager, 'GET', '/equipment?incomplete=1')->status);
        self::assertSame(2, $this->json($manager, '/equipment/data?draw=1&incomplete=1')['recordsFiltered']);
        $rows = $this->json($manager, "/equipment/data?draw=1&project_id=$project")['data'];
        self::assertSame([['REM-01', 'PRJ-K1', 1]], array_map(static fn (array $r): array => [$r['code'], $r['project_code'], (int) $r['incomplete']], $rows));

        // Coûts : classement filtré par projet.
        self::assertStringContainsString('name="project_id"', $this->http($manager, 'GET', '/costs')->body);
        self::assertSame(['REM-01'], array_column($this->json($manager, "/costs/data?draw=1&project_id=$project")['data'], 'code'));
    }

    public function testReturnToServiceOverHttp(): void
    {
        $technician = $this->session('technician', [$this->site]);
        $manager = $this->session('manager', [$this->site]);

        $this->http($technician, 'POST', '/work-orders', self::CSRF + ['equipment_id' => $this->truck, 'title' => 'Panne moteur', 'priority' => 'high', 'immobilizing' => '1']);
        $wo = (int) $this->value('SELECT id FROM work_orders');
        $this->http($technician, 'POST', "/work-orders/$wo/transition", self::CSRF + ['status' => 'in_progress']);
        $this->http($technician, 'POST', "/work-orders/$wo/transition", self::CSRF + ['status' => 'done', 'resolution' => 'Injecteur remplacé']);
        self::assertSame('pending_release', $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $this->truck]));
        $release = (int) $this->value('SELECT id FROM equipment_releases');
        $request = (int) $this->value("SELECT id FROM approval_requests WHERE entity_type = 'equipment_release'");

        // Le technicien voit la demande sur la fiche, sans pouvoir la valider lui-même.
        $page = $this->http($technician, 'GET', "/equipment/{$this->truck}")->body;
        self::assertStringContainsString('Remise en service à valider', $page);
        self::assertStringContainsString('RMS-', $page);
        self::assertStringNotContainsString("/approvals/$request/act", $page);
        $own = $this->http($technician, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable'], ['referer' => "http://localhost/equipment/{$this->truck}"]);
        self::assertContains($own->status, [302, 403, 422]);
        self::assertSame('pending_release', $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $this->truck]));

        // Le responsable la trouve dans ses validations, le lien mène à la fiche, il valide.
        self::assertStringContainsString("/equipment/releases/$release", $this->http($manager, 'GET', '/approvals')->body);
        $link = $this->http($manager, 'GET', "/equipment/releases/$release");
        self::assertSame(302, $link->status);
        self::assertStringContainsString("/equipment/{$this->truck}", (string) ($link->headers['Location'] ?? ''));
        self::assertStringContainsString("/approvals/$request/act", $this->http($manager, 'GET', "/equipment/{$this->truck}")->body);
        $res = $this->http($manager, 'POST', "/approvals/$request/act", self::CSRF + ['decision' => 'favorable']);
        self::assertSame(302, $res->status);
        self::assertSame('available', $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $this->truck]));

        // Changement manuel : « hors service » direct, retour à « disponible » soumis à validation.
        $this->http($manager, 'POST', "/equipment/{$this->truck}/status", self::CSRF + ['status' => 'out_of_service', 'reason' => 'Réforme']);
        self::assertStringContainsString('Hors service', $this->http($manager, 'GET', "/equipment/{$this->truck}")->body);
        $this->http($manager, 'POST', "/equipment/{$this->truck}/status", self::CSRF + ['status' => 'available', 'reason' => 'Remis en état']);
        self::assertSame('pending_release', $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $this->truck]));
        self::assertSame(1, $this->json($manager, '/equipment/data?draw=1&status=pending_release')['recordsFiltered']);

        // Hors périmètre : la demande d'un autre site est introuvable.
        self::assertSame(404, $this->http($this->session('manager', [$this->createSite('LIKASI')]), 'GET', "/equipment/releases/$release")->status);
        self::assertSame(404, $this->http($manager, 'GET', '/equipment/releases/999999')->status);
    }

    public function testDocumentsOverHttp(): void
    {
        $manager = $this->session('manager', [$this->site]);
        $managerId = (int) $this->value('SELECT MAX(id) FROM users');
        $types = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM document_types WHERE is_active = 1 ORDER BY sort_order, name LIMIT 2'), 'id'));
        $base = "/equipment/{$this->truck}";

        $this->http($manager, 'POST', "$base/documents", self::CSRF + ['document_type_id' => $types[0], 'expires_at' => date('Y-m-d', strtotime('+3 days')), 'issuer' => 'SONAS', 'responsible_id' => $managerId]);
        $doc = (int) $this->value('SELECT id FROM equipment_documents');
        $page = $this->http($manager, 'GET', $base)->body;
        self::assertStringContainsString("$base/documents/$doc/renewal", $page);
        self::assertStringContainsString("$base/document-exemptions", $page);
        self::assertStringContainsString('Responsable : ', $page);

        $this->http($manager, 'POST', "$base/documents/$doc/renewal", self::CSRF + ['renewing' => '1', 'responsible_id' => $managerId, 'renewal_note' => 'Dossier <b>déposé</b>']);
        $page = $this->http($manager, 'GET', $base)->body;
        self::assertStringContainsString('En renouvellement', $page);
        self::assertStringContainsString('Dossier &lt;b&gt;déposé&lt;/b&gt;', $page);
        self::assertStringNotContainsString('<b>déposé</b>', $page);

        $this->http($manager, 'POST', "$base/document-exemptions", self::CSRF + ['document_type_id' => $types[1], 'reason' => 'Engin de carrière, hors voie publique']);
        self::assertStringContainsString('Engin de carrière, hors voie publique', $this->http($manager, 'GET', $base)->body);

        // Tableau de bord des documents.
        self::assertStringContainsString('En renouvellement', $this->http($manager, 'GET', '/documents')->body);
        self::assertStringContainsString('name="responsible_id"', $this->http($manager, 'GET', '/documents/create')->body);
        $states = array_column($this->json($manager, '/documents/data?draw=1&length=100')['data'], null, 'document_type_id');
        self::assertSame(['renewing', 'not_applicable'], [$states[$types[0]]['state'], $states[$types[1]]['state']]);
        self::assertNull($states[$types[1]]['renew_url']);
        self::assertSame(1, $this->json($manager, '/documents/data?draw=1&state=renewing')['recordsFiltered']);

        $this->http($manager, 'POST', "$base/document-exemptions/{$types[1]}/delete", self::CSRF);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_document_exemptions'));
        $this->http($manager, 'POST', "$base/documents/$doc/renewal", self::CSRF + ['renewing' => '0']);
        self::assertNull($this->value('SELECT renewal_started_at FROM equipment_documents WHERE id = :id', ['id' => $doc]));

        // Droits : un lecteur consulte sans modifier ; sans jeton : refusé.
        $viewer = $this->session('viewer', [$this->site]);
        self::assertSame(200, $this->http($viewer, 'GET', $base)->status);
        self::assertSame(403, $this->http($viewer, 'POST', "$base/documents/$doc/renewal", self::CSRF + ['renewing' => '1', 'responsible_id' => $managerId])->status);
        self::assertSame(403, $this->http($viewer, 'POST', "$base/document-exemptions", self::CSRF + ['document_type_id' => $types[1], 'reason' => 'x'])->status);
        self::assertSame(419, $this->http($manager, 'POST', "$base/document-exemptions", ['document_type_id' => $types[1], 'reason' => 'x'])->status);

        // Catalogue : consommation de référence du modèle.
        $admin = $this->session('admin', [], true);
        self::assertStringContainsString('name="fuel_reference"', $this->http($admin, 'GET', '/catalog')->body);
    }
}
