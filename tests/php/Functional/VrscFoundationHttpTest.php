<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Socle VRSC vu par HTTP : référentiels, fonctions par site, délégations, import. */
final class VrscFoundationHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest', 'x-csrf-token' => self::TOKEN];

    private int $siteA;
    private int $siteB;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteA = $this->createSite('KOLWEZI');
        $this->siteB = $this->createSite('LIKASI');
        $this->admin = $this->createUser('admin');
    }

    private function session(int $userId): Session
    {
        return Session::memory(['auth_user_id' => $userId, '_csrf_token' => self::TOKEN]);
    }

    public function testReferencePagesRenderAndCrudWorksForAdmin(): void
    {
        $session = $this->session($this->admin);
        self::assertStringEndsWith('/references/projects', $this->http($session, 'GET', '/references')->headers['Location']);
        foreach (\App\Domain\ReferenceCatalog::types() as $type) {
            foreach (["/references/$type", "/references/$type/create", "/references/$type/import"] as $path) {
                $page = $this->http($session, 'GET', $path);
                self::assertSame(200, $page->status, $path);
                self::assertStringContainsString('</html>', $page->body, $path);
            }
        }
        self::assertSame(404, $this->http($session, 'GET', '/references/unknown')->status);

        $res = $this->http($session, 'POST', '/references/suppliers', self::CSRF + ['code' => 'frn-9', 'name' => '<b>Katanga</b> Diesel', 'is_active' => '1']);
        self::assertSame(302, $res->status);
        $id = (int) $this->value("SELECT id FROM suppliers WHERE code = 'FRN-9'");
        $list = $this->http($session, 'GET', '/references/suppliers?q=katanga')->body;
        self::assertStringContainsString('&lt;b&gt;Katanga&lt;/b&gt; Diesel', $list, 'Sortie échappée');
        self::assertStringNotContainsString('<b>Katanga</b>', $list);
        self::assertStringNotContainsString('FRN-9', $this->http($session, 'GET', '/references/suppliers?q=introuvable')->body);

        self::assertSame(200, $this->http($session, 'GET', "/references/suppliers/$id/edit")->status);
        $this->http($session, 'POST', "/references/suppliers/$id", self::CSRF + ['code' => 'FRN-9', 'name' => 'Katanga Diesel', 'is_active' => '0']);
        self::assertSame(['Katanga Diesel', 0], [$this->value('SELECT name FROM suppliers'), (int) $this->value('SELECT is_active FROM suppliers')]);

        // Doublon : retour au formulaire avec l'erreur, rien n'est créé.
        $dup = $this->http($session, 'POST', '/references/suppliers', self::CSRF + ['code' => 'FRN-9', 'name' => 'Autre'], ['referer' => 'http://localhost/references/suppliers/create']);
        self::assertSame(302, $dup->status);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM suppliers'));
        self::assertSame(419, $this->http($session, 'POST', '/references/suppliers', ['code' => 'X', 'name' => 'Sans jeton'])->status);
    }

    public function testViewerReadsButCannotManageReferences(): void
    {
        $viewer = $this->session($this->createUser('viewer', [$this->siteA]));
        self::assertSame(200, $this->http($viewer, 'GET', '/references/suppliers')->status);
        self::assertStringNotContainsString('/references/suppliers/create', $this->http($viewer, 'GET', '/references/suppliers')->body);
        self::assertSame(403, $this->http($viewer, 'GET', '/references/suppliers/create')->status);
        self::assertSame(403, $this->http($viewer, 'POST', '/references/suppliers', self::CSRF + ['code' => 'X', 'name' => 'Y'])->status);
        self::assertSame(403, $this->http($viewer, 'GET', '/delegations')->status);
        self::assertSame(403, $this->http($this->session($this->createUser('encoder', [$this->siteA])), 'GET', '/references')->status);
    }

    public function testAFunctionHeldOnOneSiteDoesNotApplyToAnother(): void
    {
        // Consultation sur le site A (profil principal) + Direction Logistique sur le site B (fonction supplémentaire).
        $user = $this->createUser('viewer', [$this->siteA]);
        $admin = $this->session($this->admin);
        $res = $this->http($admin, 'POST', "/users/$user", self::CSRF + [
            'email' => 'mixte@test.local', 'first_name' => 'Mixte', 'last_name' => 'Test', 'role' => 'viewer', 'locale' => 'fr',
            'is_active' => '1', 'all_sites' => '0', 'site_ids' => [$this->siteA],
            'extra_roles' => [['role' => 'logistics', 'site_id' => (string) $this->siteB], ['role' => '', 'site_id' => ''], ['role' => 'logistics', 'site_id' => (string) $this->siteB]],
        ]);
        self::assertSame(302, $res->status);
        self::assertSame([['logistics', $this->siteB]], array_map(static fn (array $r) => [$r['role'], (int) $r['site_id']],
            $this->db->fetchAll('SELECT role, site_id FROM user_roles WHERE user_id = :u', ['u' => $user])), 'Doublon de saisie ignoré');
        self::assertStringContainsString('value="logistics" selected', $this->http($admin, 'GET', "/users/$user/edit")->body);

        $this->db->execute("INSERT INTO warehouses (site_id, code, name) VALUES (:a, 'MAG-A', 'Magasin A'), (:b, 'MAG-B', 'Magasin B')", ['a' => $this->siteA, 'b' => $this->siteB]);
        $session = $this->session($user);

        // Lecture : les deux sites (consultation sur A, logistique sur B).
        $list = $this->http($session, 'GET', '/references/warehouses')->body;
        self::assertStringContainsString('MAG-A', $list);
        self::assertStringContainsString('MAG-B', $list);

        // Écriture : uniquement là où la fonction « gestion des référentiels » est détenue.
        $this->http($session, 'POST', '/references/warehouses', self::CSRF + ['code' => 'NEW-B', 'name' => 'Annexe B', 'site_id' => $this->siteB]);
        $this->http($session, 'POST', '/references/warehouses', self::CSRF + ['code' => 'NEW-A', 'name' => 'Annexe A', 'site_id' => $this->siteA]);
        self::assertSame(['MAG-A', 'MAG-B', 'NEW-B'], array_column($this->db->fetchAll('SELECT code FROM warehouses ORDER BY code'), 'code'));

        $idA = (int) $this->value("SELECT id FROM warehouses WHERE code = 'MAG-A'");
        self::assertSame(404, $this->http($session, 'GET', "/references/warehouses/$idA/edit")->status, 'Modification hors du site de la fonction');

        // La même personne reste simple lectrice pour le reste (pas de gestion du parc).
        self::assertSame(403, $this->http($session, 'GET', '/equipment/create')->status);
    }

    public function testAdminCannotBeGrantedAsExtraFunction(): void
    {
        $user = $this->createUser('viewer', [$this->siteA]);
        $this->http($this->session($this->admin), 'POST', "/users/$user", self::CSRF + [
            'email' => 'x@test.local', 'first_name' => 'X', 'last_name' => 'Y', 'role' => 'viewer', 'locale' => 'fr', 'is_active' => '1',
            'site_ids' => [$this->siteA], 'extra_roles' => [['role' => 'admin', 'site_id' => '']],
        ]);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM user_roles'));
    }

    public function testDelegationScreenCreatesAndRevokes(): void
    {
        $from = $this->createUser('supervisor', [$this->siteA]);
        $to = $this->createUser('requester', [$this->siteA]);
        $session = $this->session($this->admin);
        self::assertSame(200, $this->http($session, 'GET', '/delegations')->status);

        $this->http($session, 'POST', '/delegations', self::CSRF + ['from_user_id' => $from, 'to_user_id' => $to, 'role' => 'supervisor',
            'site_id' => $this->siteA, 'starts_on' => date('Y-m-d'), 'ends_on' => date('Y-m-d', strtotime('+3 days')), 'reason' => 'Mission au siège']);
        $id = (int) $this->value('SELECT id FROM delegations');
        self::assertGreaterThan(0, $id);
        $page = $this->http($session, 'GET', '/delegations')->body;
        self::assertStringContainsString('Mission au siège', $page);
        self::assertStringContainsString("/delegations/$id/revoke", $page);

        $this->http($session, 'POST', "/delegations/$id/revoke", self::CSRF);
        self::assertNotNull($this->value('SELECT revoked_at FROM delegations'));
        self::assertStringNotContainsString("/delegations/$id/revoke", $this->http($session, 'GET', '/delegations')->body);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM delegations'), 'Révoquée, jamais supprimée');
    }

    public function testImportEndpointsReturnJsonReportAndWriteOnlyOnConfirm(): void
    {
        $session = $this->session($this->admin);
        $rows = [['Code' => 'TR-1', 'Nom' => 'Trans Lualaba'], ['Code' => 'TR-1', 'Nom' => 'Doublon'], ['Code' => 'TR-2', 'Nom' => '']];

        $preview = $this->http($session, 'POST', '/references/carriers/import/preview', ['rows' => $rows], self::AJAX);
        self::assertSame(200, $preview->status);
        $json = json_decode($preview->body, true);
        self::assertSame(['new' => 1, 'duplicate' => 1, 'invalid' => 1], $json['counts']);
        self::assertArrayNotHasKey('data', $json['lines'][0]);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM carriers'));

        $run = json_decode($this->http($session, 'POST', '/references/carriers/import', ['rows' => $rows, 'file_name' => 'transporteurs.csv'], self::AJAX)->body, true);
        self::assertSame(1, $run['counts']['new']);
        self::assertStringContainsString('1', $run['message']);
        self::assertSame('TR-1', $this->value('SELECT code FROM carriers'));
        self::assertStringContainsString('transporteurs.csv', $this->http($session, 'GET', '/references/carriers/import')->body);

        self::assertSame(419, $this->http($session, 'POST', '/references/carriers/import', ['rows' => $rows], ['x-requested-with' => 'XMLHttpRequest'])->status);
        $viewer = $this->session($this->createUser('viewer', [$this->siteA]));
        self::assertSame(403, $this->http($viewer, 'POST', '/references/carriers/import', ['rows' => $rows], self::AJAX)->status);
    }

    public function testMenuShowsTheNewEntriesAccordingToRights(): void
    {
        $admin = $this->http($this->session($this->admin), 'GET', '/')->body;
        self::assertStringContainsString('/references"', $admin);
        self::assertStringContainsString('/delegations"', $admin);

        $storekeeper = $this->http($this->session($this->createUser('storekeeper', [$this->siteA])), 'GET', '/')->body;
        self::assertStringContainsString('/references"', $storekeeper);
        self::assertStringNotContainsString('/delegations"', $storekeeper);
        self::assertStringContainsString('Magasinier', $storekeeper);
    }
}
