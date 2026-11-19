<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\ReferenceRepository;
use App\Services\ReferenceImportService;
use App\Services\ReferenceService;
use Tests\DatabaseTestCase;

/** Référentiels communs VRSC : projets, magasins, fournisseurs… (unicité, périmètre de sites, import contrôlé). */
final class ReferenceTest extends DatabaseTestCase
{
    private int $siteA;
    private int $siteB;
    private int $admin;
    private int $logisticsA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteA = $this->createSite('KOLWEZI');
        $this->siteB = $this->createSite('LIKASI');
        $this->admin = $this->createUser('admin');
        $this->logisticsA = $this->createUser('logistics', [$this->siteA]);
    }

    public function testCreateNormalizesCodeAndRejectsDuplicates(): void
    {
        $service = $this->service($this->admin, ReferenceService::class);
        $id = $service->create('suppliers', ['code' => 'frn-001', 'name' => 'Congo Pièces SARL', 'tax_id' => 'A1234567B', 'is_active' => '1']);

        $row = $this->row('SELECT * FROM suppliers WHERE id = :id', ['id' => $id]);
        self::assertSame('FRN-001', $row['code']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame('ref_suppliers', $this->value("SELECT entity_type FROM activity_log WHERE action = 'create' ORDER BY id DESC LIMIT 1"));

        $this->expectException(ValidationException::class);
        $service->create('suppliers', ['code' => 'FRN-001', 'name' => 'Autre']);
    }

    public function testUpdateLogsOnlyChangedFields(): void
    {
        $service = $this->service($this->admin, ReferenceService::class);
        $id = $service->create('cost-centers', ['code' => 'CC10', 'name' => 'Terrassement', 'is_active' => '1']);
        $service->update('cost-centers', $id, ['code' => 'CC10', 'name' => 'Terrassement & roulage', 'is_active' => '1']);

        $log = $this->row("SELECT before_data, after_data FROM activity_log WHERE action = 'update' AND entity_type = 'ref_cost_centers'");
        self::assertSame(['name' => 'Terrassement'], json_decode($log['before_data'], true));
        self::assertSame(['name' => 'Terrassement & roulage'], json_decode($log['after_data'], true));
    }

    public function testWarehouseCodeIsUniqueAcrossSitesAndProjectsLinkToCostCentre(): void
    {
        $service = $this->service($this->admin, ReferenceService::class);
        $cc = $service->create('cost-centers', ['code' => 'CC20', 'name' => 'Génie civil']);
        $service->create('warehouses', ['code' => 'MAG-KZI', 'name' => 'Magasin central Kolwezi', 'site_id' => $this->siteA, 'is_central' => '1', 'is_active' => '1']);
        $project = $service->create('projects', ['code' => 'PRJ-01', 'name' => 'Route d\'accès', 'site_id' => $this->siteA,
            'project_kind' => 'worksite', 'cost_center_id' => $cc, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => '1']);

        $list = $this->service($this->admin, ReferenceRepository::class)->paginateList('projects');
        self::assertSame('CC20 – Génie civil', $list['rows'][0]['cost_center_id_label']);
        self::assertSame('Site KOLWEZI', $list['rows'][0]['site_id_label']);
        self::assertSame($project, (int) $list['rows'][0]['id']);

        try {
            $service->create('warehouses', ['code' => 'mag-kzi', 'name' => 'Doublon', 'site_id' => $this->siteB]);
            self::fail('Identifiant de magasin unique sur tous les sites');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('code', $e->errors);
        }
        try {
            $service->create('projects', ['code' => 'PRJ-02', 'name' => 'Dates inversées', 'site_id' => $this->siteA, 'project_kind' => 'project',
                'starts_on' => '2026-06-01', 'ends_on' => '2026-01-01']);
            self::fail('Fin avant début');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ends_on', $e->errors);
        }
    }

    public function testSiteScopedReferencesFollowTheUserPerimeter(): void
    {
        $admin = $this->service($this->admin, ReferenceService::class);
        $admin->create('warehouses', ['code' => 'MAG-A', 'name' => 'Magasin A', 'site_id' => $this->siteA, 'is_active' => '1']);
        $other = $admin->create('warehouses', ['code' => 'MAG-B', 'name' => 'Magasin B', 'site_id' => $this->siteB]);

        $repo = $this->service($this->logisticsA, ReferenceRepository::class);
        self::assertSame(['MAG-A'], array_column($repo->paginateList('warehouses')['rows'], 'code'));
        self::assertNull($repo->find('warehouses', $other), 'Magasin d\'un autre site invisible');
        self::assertSame(['MAG-A – Magasin A'], array_values($repo->options('warehouses')));

        $service = $this->service($this->logisticsA, ReferenceService::class);
        try {
            $service->create('warehouses', ['code' => 'MAG-X', 'name' => 'Hors périmètre', 'site_id' => $this->siteB]);
            self::fail('Création hors périmètre');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('site_id', $e->errors);
        }
        $this->expectException(HttpException::class);
        $service->update('warehouses', $other, ['code' => 'MAG-B', 'name' => 'Piraté', 'site_id' => $this->siteA]);
    }

    public function testControlledImportSkipsDuplicatesFlagsErrorsAndKeepsALog(): void
    {
        $this->service($this->admin, ReferenceService::class)->create('suppliers', ['code' => 'FRN-001', 'name' => 'Déjà là']);
        $importer = $this->service($this->admin, ReferenceImportService::class);
        $rows = [
            ['Code' => 'FRN-001', 'Nom' => 'Doublon base'],
            ['Code' => 'frn-002', 'Nom' => 'Katanga Diesel', 'E-mail' => 'ventes@katanga-diesel.test', 'Actif' => 'oui'],
            ['code' => 'FRN-002', 'name' => 'Doublon fichier'],
            ['Code' => 'FRN-003', 'Nom' => ''],
            ['Code' => 'FRN-004', 'Nom' => 'E-mail faux', 'E-mail' => 'pas-un-mail'],
            ['Code' => '', 'Nom' => ''],
            ['Code' => 'FRN-005', 'Nom' => 'Inactif', 'Actif' => 'non'],
        ];

        $preview = $importer->preview('suppliers', $rows);
        self::assertSame(['new' => 2, 'duplicate' => 2, 'invalid' => 2], $preview['counts']);
        self::assertSame(['duplicate', 'new', 'duplicate', 'invalid', 'invalid', 'new'], array_column($preview['lines'], 'status'));
        self::assertSame(3, $preview['lines'][1]['line'], 'Numéro de ligne du fichier (titres en ligne 1)');
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM suppliers'), 'Le contrôle n\'écrit rien');

        $result = $importer->import('suppliers', $rows, 'fournisseurs.xlsx');
        self::assertSame(2, $result['counts']['new']);
        self::assertSame(['FRN-001', 'FRN-002', 'FRN-005'], array_column($this->db->fetchAll('SELECT code FROM suppliers ORDER BY code'), 'code'));
        self::assertSame(0, (int) $this->value("SELECT is_active FROM suppliers WHERE code = 'FRN-005'"));

        $log = $this->row('SELECT * FROM import_logs WHERE id = :id', ['id' => $result['log_id']]);
        self::assertSame(['suppliers', 'fournisseurs.xlsx', 6, 2, 2, 2], [$log['target'], $log['file_name'], (int) $log['rows_total'],
            (int) $log['rows_created'], (int) $log['rows_skipped'], (int) $log['rows_invalid']]);
        self::assertSame((int) $this->admin, (int) $log['imported_by']);
        self::assertCount(6, json_decode($log['report'], true));

        // Rejouer le même fichier n'ajoute rien : dédoublonnage.
        $again = $importer->import('suppliers', $rows, 'fournisseurs.xlsx');
        self::assertSame(0, $again['counts']['new']);
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM suppliers'));
    }

    public function testImportResolvesSiteAndLinkedReferenceByCode(): void
    {
        $this->service($this->admin, ReferenceService::class)->create('cost-centers', ['code' => 'CC30', 'name' => 'Maintenance']);
        $importer = $this->service($this->admin, ReferenceImportService::class);
        $result = $importer->import('projects', [
            ['Code' => 'PRJ-10', 'Nom' => 'Digue', 'Site' => 'likasi', 'Nature' => 'Chantier', 'Centre de coûts' => 'cc30', 'Début' => '15/01/2026'],
            ['Code' => 'PRJ-11', 'Nom' => 'Site inconnu', 'Site' => 'NULLEPART', 'Nature' => 'Projet'],
        ], null);

        self::assertSame(['new' => 1, 'duplicate' => 0, 'invalid' => 1], $result['counts']);
        $project = $this->row("SELECT * FROM projects WHERE code = 'PRJ-10'");
        self::assertSame([$this->siteB, 'worksite', '2026-01-15'], [(int) $project['site_id'], $project['project_kind'], $project['starts_on']]);
        self::assertNotNull($project['cost_center_id']);
    }
}
