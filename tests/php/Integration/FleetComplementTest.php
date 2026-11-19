<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Core\ValidationException;
use App\Domain\EquipmentInfo;
use App\Repositories\EquipmentCostRepository;
use App\Repositories\EquipmentDocumentRepository;
use App\Repositories\EquipmentRepository;
use App\Services\ApprovalWorkflow;
use App\Services\EquipmentDocumentService;
use App\Services\EquipmentService;
use App\Services\WorkOrderService;
use Tests\DatabaseTestCase;

/**
 * Lot 7 — compléments de la flotte :
 * fiche complète avec « non applicable » distinct de « manquant », statut « hors service »,
 * validation de remise en service après intervention, documents « en renouvellement » avec responsable
 * et documents « non applicables ».
 */
final class FleetComplementTest extends DatabaseTestCase
{
    private int $site;
    private int $other;
    private int $manager;
    private int $technician;
    private int $supervisor;
    private int $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->other = $this->createSite('LIKASI');
        $this->manager = $this->createUser('manager', [$this->site]);
        $this->technician = $this->createUser('technician', [$this->site]);
        $this->supervisor = $this->createUser('supervisor', [$this->site]);
        $this->truck = $this->createEquipment($this->site, 'CAM-01', 'available', 100);
    }

    private function project(int $site, string $code): int
    {
        return $this->db->insert('INSERT INTO projects (site_id, code, name) VALUES (:s, :c, :n)', ['s' => $site, 'c' => $code, 'n' => 'Chantier ' . $code]);
    }

    private function eqStatus(): string
    {
        return (string) $this->value('SELECT status FROM equipment WHERE id = :id', ['id' => $this->truck]);
    }

    private function decide(int $user, string $decision = 'favorable', ?string $comment = null): array
    {
        $requestId = (int) $this->value("SELECT id FROM approval_requests WHERE entity_type = 'equipment_release' ORDER BY id DESC LIMIT 1");

        return $this->service($user, ApprovalWorkflow::class)->act($requestId, ['decision' => $decision, 'comment' => $comment]);
    }

    /** OT ouvert par $by, démarré puis terminé par $by. */
    private function repair(int $by, array $extra = []): int
    {
        $orders = $this->service($by, WorkOrderService::class);
        $id = $orders->create($extra + ['equipment_id' => $this->truck, 'title' => 'Fuite hydraulique', 'priority' => 'high', 'immobilizing' => '1']);
        $orders->transition($id, ['status' => 'in_progress']);
        $orders->transition($id, ['status' => 'done', 'resolution' => 'Flexible remplacé']);

        return $id;
    }

    public function testSheetDistinguishesNotApplicableFromMissing(): void
    {
        $service = $this->service($this->manager, EquipmentService::class);
        $project = $this->project($this->site, 'PRJ-K1');
        $base = ['site_id' => $this->site, 'code' => 'REM-01', 'name' => 'Remorque plateau'];

        $id = $service->create($base + [
            'year_made' => '2019', 'color' => 'Jaune', 'capacity_value' => '30', 'acquired_at' => '2020-03-01', 'owner_name' => 'VRSC SARL',
            'project_id' => $project, 'fuel_reference' => '18.5', 'plate_number' => 'ab-123',
            // Puissance sans objet pour une remorque ; « couleur » cochée alors qu'elle est renseignée : ignorée.
            'not_applicable' => ['power', 'serial_number', 'color', 'inconnu'],
        ]);
        $item = $this->service($this->manager, EquipmentRepository::class)->find($id);
        self::assertSame(['2019', 'Jaune', '30.00', 't', 'VRSC SARL', 'PRJ-K1', '18.50', 'AB-123'],
            [(string) $item['year_made'], $item['color'], $item['capacity_value'], $item['capacity_unit'], $item['owner_name'], $item['project_code'], $item['fuel_reference'], $item['plate_number']]);
        self::assertSame('serial_number,power', $item['not_applicable']);
        $states = EquipmentInfo::states($item);
        self::assertSame(['na', 'na', 'filled', 'missing', 'missing'], [$states['power'], $states['serial_number'], $states['color'], $states['chassis_number'], $states['commissioned_at']]);
        self::assertSame(['chassis_number', 'commissioned_at'], EquipmentInfo::missing($item));

        // Filtre « fiche incomplète » : l'engin sort de la liste une fois tout renseigné ou déclaré sans objet.
        $repo = $this->service($this->manager, EquipmentRepository::class);
        $list = static fn (array $filters): array => array_column($repo->datatable(DataTableRequest::fromQuery([], EquipmentRepository::SORTABLE, ['e.code', 'ASC']), $filters)['data'], 'code');
        self::assertSame(['CAM-01', 'REM-01'], $list(['incomplete' => true]));
        self::assertSame(['REM-01'], $list(['project_id' => $project]));
        $service->update($id, $base + ['year_made' => '2019', 'color' => 'Jaune', 'capacity_value' => '30', 'acquired_at' => '2020-03-01', 'owner_name' => 'VRSC SARL',
            'plate_number' => 'AB-123', 'project_id' => $project, 'not_applicable' => ['power', 'serial_number', 'chassis_number', 'commissioned_at']]);
        self::assertSame(['CAM-01'], $list(['incomplete' => true]));

        // Un projet d'un autre site est refusé ; une date d'acquisition future aussi.
        foreach ([['project_id' => $this->project($this->other, 'PRJ-L1')], ['acquired_at' => date('Y-m-d', strtotime('+2 days'))]] as $bad) {
            try {
                $service->update($id, $base + $bad);
                self::fail('Saisie incohérente acceptée');
            } catch (ValidationException $e) {
                self::assertArrayHasKey(array_key_first($bad), $e->errors);
            }
        }
    }

    public function testReturnToServiceNeedsValidationByAnotherPerson(): void
    {
        // Intervention immobilisante terminée : l'engin n'est pas disponible, il attend la validation.
        $wo = $this->repair($this->technician);
        self::assertSame('pending_release', $this->eqStatus());
        $release = $this->row('SELECT * FROM equipment_releases');
        self::assertSame([$wo, 'pending', $this->technician], [(int) $release['work_order_id'], $release['status'], (int) $release['requested_by']]);
        self::assertStringStartsWith('RMS-', $release['reference']);

        // Celui qui a terminé l'intervention ne valide pas sa propre remise en service.
        try {
            $this->decide($this->technician);
            self::fail('Auto-validation acceptée');
        } catch (ValidationException | \App\Core\HttpException $e) {
            self::assertSame('pending_release', $this->eqStatus());
        }

        // Refus motivé : l'engin retourne « en panne », le motif est tracé.
        $this->decide($this->supervisor, 'unfavorable', 'Fuite toujours visible');
        self::assertSame('breakdown', $this->eqStatus());
        self::assertSame('rejected', $this->value('SELECT status FROM equipment_releases WHERE id = :id', ['id' => $release['id']]));
        self::assertStringContainsString('Fuite toujours visible', (string) $this->value('SELECT reason FROM equipment_status_history ORDER BY id DESC LIMIT 1'));

        // Nouvelle intervention, puis validation par le responsable : disponible.
        $this->repair($this->technician);
        self::assertSame('pending_release', $this->eqStatus());
        $this->decide($this->manager);
        self::assertSame('available', $this->eqStatus());
        self::assertSame(['rejected', 'approved'], array_column($this->db->fetchAll('SELECT status FROM equipment_releases ORDER BY id'), 'status'));
    }

    public function testPreventiveWorkAndManualStatusFollowTheSameGate(): void
    {
        // Entretien préventif non immobilisant : pas de validation, retour direct.
        $orders = $this->service($this->technician, WorkOrderService::class);
        $pm = $orders->create(['equipment_id' => $this->truck, 'title' => 'Vidange 250 h', 'type' => 'preventive', 'priority' => 'normal']);
        $orders->transition($pm, ['status' => 'in_progress']);
        self::assertSame('maintenance', $this->eqStatus());
        $orders->transition($pm, ['status' => 'done', 'resolution' => 'Vidange faite']);
        self::assertSame('available', $this->eqStatus());
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_releases'));

        // Hors service : décision manuelle, que les ordres de travail ne modifient pas.
        $service = $this->service($this->manager, EquipmentService::class);
        self::assertSame('changed', $service->changeStatus($this->truck, ['status' => 'out_of_service', 'reason' => 'Châssis fissuré, en attente de réforme']));
        $this->repair($this->technician);
        self::assertSame('out_of_service', $this->eqStatus());
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment_releases'));

        // Le retour manuel à « disponible » ouvre une demande : jamais direct, jamais deux fois.
        self::assertSame('pending', $service->changeStatus($this->truck, ['status' => 'available', 'reason' => 'Châssis réparé et contrôlé']));
        self::assertSame('pending_release', $this->eqStatus());
        try {
            $service->changeStatus($this->truck, ['status' => 'available', 'reason' => 'Encore']);
            self::fail('Seconde demande acceptée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        // « À valider » ne se pose pas à la main.
        try {
            $service->changeStatus($this->truck, ['status' => 'pending_release', 'reason' => 'x']);
            self::fail('Statut réservé accepté');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        // Pendant l'attente, un entretien préventif terminé ne rend pas l'engin disponible.
        $pm = $orders->create(['equipment_id' => $this->truck, 'title' => 'Graissage', 'type' => 'preventive', 'priority' => 'low']);
        $orders->transition($pm, ['status' => 'in_progress']);
        $orders->transition($pm, ['status' => 'done', 'resolution' => 'Fait']);
        self::assertSame('pending_release', $this->eqStatus());

        // Une décision manuelle contraire annule la demande en attente.
        $service->changeStatus($this->truck, ['status' => 'immobilized', 'reason' => 'Contrôle technique échu']);
        self::assertSame('immobilized', $this->eqStatus());
        self::assertSame('cancelled', $this->value('SELECT status FROM equipment_releases ORDER BY id DESC LIMIT 1'));
        self::assertSame('cancelled', $this->value("SELECT status FROM approval_requests WHERE entity_type = 'equipment_release' ORDER BY id DESC LIMIT 1"));

        // Nouvelle demande, validée par le supérieur hiérarchique.
        $service->changeStatus($this->truck, ['status' => 'available', 'reason' => 'Contrôle technique passé']);
        $this->decide($this->supervisor);
        self::assertSame('available', $this->eqStatus());
    }

    public function testDocumentRenewalResponsibleAndNotApplicable(): void
    {
        $documents = $this->service($this->manager, EquipmentDocumentService::class);
        $repo = $this->service($this->manager, EquipmentDocumentRepository::class);
        $types = array_column($repo->types(), 'id');
        self::assertGreaterThanOrEqual(2, count($types));
        [$insurance, $inspection] = [(int) $types[0], (int) $types[1]];
        $board = function (?string $state = null) use ($repo): array {
            $rows = $repo->datatable(DataTableRequest::fromQuery(['length' => '100'], EquipmentDocumentRepository::SORTABLE, ['x.state_rank, x.expires_at, x.code', 'ASC']), ['state' => $state])['data'];

            return array_column($rows, null, 'document_type_id');
        };

        // Document expiré, puis mis en renouvellement : responsable obligatoire, échéance toujours visible.
        $doc = $documents->add($this->truck, ['document_type_id' => $insurance, 'expires_at' => date('Y-m-d', strtotime('-5 days')), 'issuer' => 'SONAS']);
        self::assertSame('expired', $board()[$insurance]['state']);
        try {
            $documents->setRenewal($this->truck, $doc, ['renewing' => '1']);
            self::fail('Renouvellement sans responsable accepté');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('responsible_id', $e->errors);
        }
        $documents->setRenewal($this->truck, $doc, ['renewing' => '1', 'responsible_id' => $this->manager, 'renewal_note' => 'Dossier déposé chez SONAS']);
        $row = $board()[$insurance];
        self::assertSame('renewing', $row['state']);
        self::assertNotNull($row['responsible']);
        self::assertStringContainsString('5', $row['state_label']);
        self::assertSame(1, $repo->boardSummary([])['renewing']);
        self::assertSame(0, $repo->boardSummary([])['expired']);
        self::assertSame('renewing', $repo->forEquipment($this->truck)[0]['current']['state']);

        // Le nouveau document clôt le renouvellement de lui-même.
        $documents->add($this->truck, ['document_type_id' => $insurance, 'expires_at' => date('Y-m-d', strtotime('+300 days')), 'responsible_id' => $this->manager]);
        self::assertSame('valid', $board()[$insurance]['state']);
        // Fin de renouvellement explicite.
        $late = $documents->add($this->truck, ['document_type_id' => $inspection, 'expires_at' => date('Y-m-d', strtotime('-1 day'))]);
        $documents->setRenewal($this->truck, $late, ['renewing' => '1', 'responsible_id' => $this->manager]);
        $documents->setRenewal($this->truck, $late, ['renewing' => '0']);
        self::assertSame('expired', $board()[$inspection]['state']);

        // « Non applicable » : motif obligatoire, refusé si un document existe, distinct de « non renseigné ».
        $trailer = $this->createEquipment($this->site, 'REM-01', 'available', 0);
        $boardFor = function (int $equipment) use ($repo): array {
            $rows = $repo->datatable(DataTableRequest::fromQuery(['length' => '100'], EquipmentDocumentRepository::SORTABLE, ['x.code', 'ASC']), [])['data'];

            return array_column(array_filter($rows, static fn (array $r): bool => (int) $r['equipment_id'] === $equipment), 'state', 'document_type_id');
        };
        self::assertSame('missing', $boardFor($trailer)[$inspection]);
        try {
            $documents->exempt($trailer, ['document_type_id' => $inspection]);
            self::fail('Exemption sans motif acceptée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reason', $e->errors);
        }
        try {
            $documents->exempt($this->truck, ['document_type_id' => $inspection, 'reason' => 'x']);
            self::fail('Exemption malgré un document existant');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('document_type_id', $e->errors);
        }
        $missingBefore = $repo->boardSummary([])['missing'];
        $documents->exempt($trailer, ['document_type_id' => $inspection, 'reason' => 'Remorque non soumise au contrôle']);
        self::assertSame('not_applicable', $boardFor($trailer)[$inspection]);
        self::assertSame($missingBefore - 1, $repo->boardSummary([])['missing']);
        // Saisir un document lève la déclaration ; on peut aussi la lever à la main.
        $documents->add($trailer, ['document_type_id' => $inspection, 'expires_at' => date('Y-m-d', strtotime('+100 days'))]);
        self::assertSame('valid', $boardFor($trailer)[$inspection]);
        $documents->exempt($trailer, ['document_type_id' => $insurance, 'reason' => 'Couverte par le tracteur']);
        $documents->unexempt($trailer, $insurance);
        self::assertSame('missing', $boardFor($trailer)[$insurance]);
    }

    public function testCostRankingByProjectAndModelReference(): void
    {
        $project = $this->project($this->site, 'PRJ-K1');
        $this->createEquipment($this->site, 'CAM-02', 'available', 100);
        $this->db->execute('UPDATE equipment SET project_id = :p WHERE id = :e', ['p' => $project, 'e' => $this->truck]);
        $costs = $this->service($this->manager, EquipmentCostRepository::class);
        $dt = DataTableRequest::fromQuery([], EquipmentCostRepository::SORTABLE, ['x.total', 'DESC']);
        $rows = $costs->monthly($dt, date('Y-m'), ['project_id' => $project])['data'];
        self::assertSame([['CAM-01', 'PRJ-K1']], array_map(static fn (array $r): array => [$r['code'], $r['project_code']], $rows));
        self::assertCount(2, $costs->monthly($dt, date('Y-m'))['data']);
    }
}
