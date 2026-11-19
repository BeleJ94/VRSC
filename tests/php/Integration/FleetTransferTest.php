<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\EquipmentRepository;
use App\Repositories\FleetTransferRepository;
use App\Services\ApprovalWorkflow;
use App\Services\EquipmentService;
use App\Services\FleetTransferService;
use Tests\DatabaseTestCase;

/**
 * Lot 4 — transferts de flotte entre sites (cahier des charges § 4).
 * Recette R3 : avis distinct de l'autorisation, check-list, OTV, départ, PV d'arrivée ; changement de site uniquement après réception.
 * Recette R4 : engin inapte → départ refusé sans nouvelle validation technique ; trace des contrôles.
 */
final class FleetTransferTest extends DatabaseTestCase
{
    private int $kolwezi;
    private int $likasi;
    private int $requester;
    private int $supervisor;
    private int $technician;
    private int $logistics;
    private int $managerK;
    private int $managerL;
    private int $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kolwezi = $this->createSite('KOLWEZI');
        $this->likasi = $this->createSite('LIKASI');
        $this->requester = $this->createUser('requester', [$this->kolwezi]);
        $this->supervisor = $this->createUser('supervisor', [$this->kolwezi]);
        $this->technician = $this->createUser('technician', [$this->kolwezi]);
        $this->logistics = $this->createUser('logistics', [], true);
        $this->managerK = $this->createUser('manager', [$this->kolwezi]);
        $this->managerL = $this->createUser('manager', [$this->likasi]);
        $this->truck = $this->createEquipment($this->kolwezi, 'CAM-01', 'available', 100);
    }

    private function transfers(int $user): FleetTransferService
    {
        return $this->service($user, FleetTransferService::class);
    }

    private function act(int $user, int $transferId, string $decision = 'favorable', ?string $comment = null): array
    {
        $requestId = (int) $this->value("SELECT id FROM approval_requests WHERE entity_type = 'fleet_transfer' AND entity_id = :e ORDER BY id DESC LIMIT 1", ['e' => $transferId]);

        return $this->service($user, ApprovalWorkflow::class)->act($requestId, ['decision' => $decision, 'comment' => $comment]);
    }

    private function request(int $user = 0, array $extra = []): int
    {
        return $this->transfers($user ?: $this->requester)->request($extra + [
            'equipment_id' => $this->truck, 'to_site_id' => $this->likasi, 'reason' => 'Renfort du chantier de Likasi',
            'transport_mode' => 'own_power', 'driver_name' => 'Kabila J.', 'driver_phone' => '+243 970 000 001',
            'planned_departure' => date('Y-m-d\TH:i', strtotime('+1 day')), 'planned_arrival' => date('Y-m-d\TH:i', strtotime('+2 days')),
        ]);
    }

    /** @param array<string, array{0: string, 1?: string}> $overrides point => [constat, note] */
    private function checklist(string $conclusion, array $overrides = [], array $extra = []): array
    {
        $results = array_fill_keys(FleetTransferService::ITEMS, 'ok');
        $notes = [];
        foreach ($overrides as $item => $entry) {
            $results[$item] = $entry[0];
            $notes[$item] = $entry[1] ?? '';
        }

        return $extra + ['results' => $results, 'notes' => $notes, 'conclusion' => $conclusion, 'hour_meter' => '120'];
    }

    private function field(int $id, string $column): mixed
    {
        return $this->value("SELECT $column FROM fleet_transfers WHERE id = :id", ['id' => $id]);
    }

    private function site(): int
    {
        return (int) $this->value('SELECT site_id FROM equipment WHERE id = :id', ['id' => $this->truck]);
    }

    private function stage(int $id): ?string
    {
        return $this->service($this->logistics, FleetTransferRepository::class)->find($id)['stage'] ?? null;
    }

    public function testR3TransferFromAToBChangesSiteOnlyAfterReception(): void
    {
        $this->db->execute('INSERT INTO equipment_site_history (equipment_id, site_id, started_at) VALUES (:e, :s, NOW() - INTERVAL 30 DAY)', ['e' => $this->truck, 's' => $this->kolwezi]);
        $this->db->execute("INSERT INTO preventive_plans (site_id, equipment_id, title, interval_days, next_due_date) VALUES (:s, :e, 'Révision', 90, CURDATE())", ['s' => $this->kolwezi, 'e' => $this->truck]);

        $id = $this->request();
        self::assertMatchesRegularExpression('/^OTV-\d{4}-00001$/', (string) $this->field($id, 'reference'));
        self::assertSame(['requested', 'opinion'], [$this->field($id, 'status'), $this->stage($id)]);

        // Une demande n'autorise pas le départ ; le contrôle attend l'avis hiérarchique.
        try {
            $this->transfers($this->managerK)->depart($id, ['departure_hours' => '125', 'handover_to' => 'Kabila J.']);
            self::fail('Départ sur simple demande');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        try {
            $this->transfers($this->technician)->recordCheck($id, $this->checklist('fit'));
            self::fail('Contrôle avant l\'avis');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('conclusion', $e->errors);
        }

        // 1. Avis hiérarchique — par le responsable hiérarchique, pas par un technicien.
        try {
            $this->act($this->technician, $id);
            self::fail('Un technicien ne donne pas l\'avis hiérarchique');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame('control', $this->act($this->supervisor, $id)['next_step']);

        // 2. Contrôle technique : seulement par la check-list, jamais par un simple clic d'approbation.
        try {
            $this->act($this->logistics, $id);
            self::fail('Le contrôle technique ne se valide pas sans check-list');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('decision', $e->errors);
        }
        try {
            $this->transfers($this->supervisor)->recordCheck($id, $this->checklist('fit'));
            self::fail('Le responsable hiérarchique ne fait pas le contrôle technique');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $this->transfers($this->technician)->recordCheck($id, $this->checklist('fit'));
        self::assertSame(['requested', 'authorization'], [$this->field($id, 'status'), $this->stage($id)]);
        try {
            $this->transfers($this->managerK)->depart($id, ['departure_hours' => '125', 'handover_to' => 'Kabila J.']);
            self::fail('Avis et contrôle ne valent pas autorisation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }

        // 3. Autorisation de la Direction Logistique : acte distinct de l'avis.
        self::assertSame('approved', $this->act($this->logistics, $id)['status']);
        self::assertSame('authorized', $this->field($id, 'status'));
        $actions = $this->db->fetchAll('SELECT step_code, step_kind, acted_by FROM approval_actions ORDER BY id');
        self::assertSame(['opinion', 'control', 'authorization'], array_column($actions, 'step_code'));
        self::assertSame(['opinion', 'control', 'authorization'], array_column($actions, 'step_kind'));
        self::assertSame([$this->supervisor, $this->technician, $this->logistics], array_map('intval', array_column($actions, 'acted_by')), 'Trois actes, trois personnes');

        // 4. Départ confirmé par le site d'origine : l'engin est en route mais reste affecté à Kolwezi.
        try {
            $this->transfers($this->managerL)->depart($id, ['departure_hours' => '125', 'handover_to' => 'Kabila J.']);
            self::fail('Départ confirmé par un autre site');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $this->transfers($this->managerK)->depart($id, ['departure_hours' => '90', 'handover_to' => 'Kabila J.']);
            self::fail('Compteur inférieur à l\'index connu');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('departure_hours', $e->errors);
        }
        $this->transfers($this->managerK)->depart($id, ['departure_hours' => '125', 'departure_fuel' => '80', 'handover_to' => 'Kabila J.']);
        self::assertSame(['departed', 'reception'], [$this->field($id, 'status'), $this->stage($id)]);
        self::assertSame($this->kolwezi, $this->site(), 'Toujours affecté au site d\'origine pendant le trajet');
        self::assertSame(125, (int) $this->value('SELECT hour_meter FROM equipment WHERE id = :id', ['id' => $this->truck]));

        // 5. Réception contradictoire à destination, par une autre personne.
        try {
            $this->transfers($this->managerK)->receive($id, ['arrival_hours' => '131'] + $this->checklist('fit'));
            self::fail('Réception par le site d\'origine');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $this->transfers($this->managerL)->receive($id, ['arrival_hours' => '120'] + $this->checklist('fit'));
            self::fail('Compteur d\'arrivée inférieur au départ');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('arrival_hours', $e->errors);
        }
        $this->transfers($this->managerL)->receive($id, ['arrival_hours' => '131', 'arrival_fuel' => '55'] + $this->checklist('fit'));

        // Le changement d'affectation n'a lieu qu'ici.
        self::assertSame('received', $this->field($id, 'status'));
        self::assertSame($this->likasi, $this->site());
        self::assertSame([0, $this->managerL, 131], [(int) $this->field($id, 'has_discrepancy'), (int) $this->field($id, 'received_by'), (int) $this->field($id, 'arrival_hours')]);
        $history = $this->db->fetchAll('SELECT site_id, ended_at, transfer_id FROM equipment_site_history WHERE equipment_id = :e ORDER BY id', ['e' => $this->truck]);
        self::assertSame([$this->kolwezi, $this->likasi], array_map('intval', array_column($history, 'site_id')));
        self::assertNotNull($history[0]['ended_at']);
        self::assertSame([null, $id], [$history[1]['ended_at'], (int) $history[1]['transfer_id']], 'Historique lié à l\'engin, ventilé par site');
        self::assertSame($this->likasi, (int) $this->value('SELECT site_id FROM preventive_plans WHERE equipment_id = :e', ['e' => $this->truck]));
        self::assertSame(131, (int) $this->value('SELECT hour_meter FROM equipment WHERE id = :id', ['id' => $this->truck]));

        // Périmètre : l'engin est désormais visible de Likasi, plus de Kolwezi.
        self::assertNotNull($this->service($this->managerL, EquipmentRepository::class)->find($this->truck));
        self::assertNull($this->service($this->managerK, EquipmentRepository::class)->find($this->truck));
        self::assertNotNull($this->service($this->managerK, FleetTransferRepository::class)->find($id), 'Le site d\'origine garde l\'accès à l\'ordre de transfert');

        // Contrôles conservés sous le même numéro : check-list préalable et PV d'arrivée.
        $checks = $this->service($this->logistics, FleetTransferRepository::class)->checks($id);
        self::assertSame(['pre_departure', 'arrival'], array_column($checks, 'kind'));
        self::assertCount(count(FleetTransferService::ITEMS), $checks[0]['items']);
    }

    public function testSiteCannotBeChangedOutsideATransfer(): void
    {
        $admin = $this->createUser('admin');
        try {
            $this->service($admin, EquipmentService::class)->update($this->truck, ['site_id' => $this->likasi, 'code' => 'CAM-01', 'name' => 'Engin CAM-01']);
            self::fail('Changement de site par la fiche');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('site_id', $e->errors);
        }
        self::assertSame($this->kolwezi, $this->site());
        $this->service($admin, EquipmentService::class)->update($this->truck, ['site_id' => $this->kolwezi, 'code' => 'CAM-01', 'name' => 'Camion benne']);
        self::assertSame('Camion benne', $this->value('SELECT name FROM equipment WHERE id = :id', ['id' => $this->truck]));

        // Un engin créé par l'écran reçoit sa première période d'affectation.
        $new = $this->service($admin, EquipmentService::class)->create(['site_id' => $this->kolwezi, 'code' => 'CAM-02', 'name' => 'Camion']);
        self::assertSame($this->kolwezi, (int) $this->value('SELECT site_id FROM equipment_site_history WHERE equipment_id = :e AND ended_at IS NULL', ['e' => $new]));
    }

    public function testR4UnfitEquipmentCannotLeaveWithoutANewTechnicalValidation(): void
    {
        $id = $this->request();
        $this->act($this->supervisor, $id);

        // Check-list cohérente : une non-conformité interdit « apte » et doit être décrite.
        try {
            $this->transfers($this->technician)->recordCheck($id, $this->checklist('fit', ['brakes' => ['nok', 'Fuite au maître-cylindre']]));
            self::fail('Non-conformité et conclusion « apte »');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('conclusion', $e->errors);
        }
        try {
            $this->transfers($this->technician)->recordCheck($id, $this->checklist('unfit', ['brakes' => ['nok']], ['remarks' => 'Freinage']));
            self::fail('Non-conformité non décrite');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('notes', $e->errors);
        }
        try {
            $this->transfers($this->technician)->recordCheck($id, $this->checklist('unfit', ['brakes' => ['nok', 'Fuite']]));
            self::fail('Inaptitude non motivée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('remarks', $e->errors);
        }

        // Inapte : enregistré, mais le circuit n'avance pas.
        $this->transfers($this->technician)->recordCheck($id, $this->checklist('unfit', ['brakes' => ['nok', 'Fuite au maître-cylindre']], ['remarks' => 'Freinage défaillant, immobiliser']));
        self::assertSame(['requested', 'control'], [$this->field($id, 'status'), $this->stage($id)]);
        try {
            $this->act($this->logistics, $id);
            self::fail('Pas d\'autorisation sur un engin inapte');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('decision', $e->errors);
        }
        try {
            $this->transfers($this->managerK)->depart($id, ['departure_hours' => '125', 'handover_to' => 'Kabila J.']);
            self::fail('Départ d\'un engin inapte');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        self::assertSame($this->kolwezi, $this->site());

        // Nouvelle validation technique après réparation → le circuit reprend.
        $this->transfers($this->technician)->recordCheck($id, $this->checklist('fit'));
        $this->act($this->logistics, $id);
        self::assertSame('authorized', $this->field($id, 'status'));

        // Même autorisé : un contrôle de dernière minute « inapte » bloque le départ.
        $this->transfers($this->managerK)->recordCheck($id, $this->checklist('unfit', ['tyres' => ['nok', 'Pneu AVD éclaté']], ['remarks' => 'Pneu à remplacer']));
        try {
            $this->transfers($this->managerK)->depart($id, ['departure_hours' => '125', 'handover_to' => 'Kabila J.']);
            self::fail('Départ refusé sans nouvelle validation technique');
        } catch (ValidationException $e) {
            self::assertStringContainsString('inapte', $e->errors['status']);
        }
        self::assertSame('authorized', $this->field($id, 'status'));
        $this->transfers($this->technician)->recordCheck($id, $this->checklist('conditional', ['tyres' => ['reserve', 'Roue de secours montée']], ['remarks' => 'Vitesse limitée à 40 km/h']));
        $this->transfers($this->managerK)->depart($id, ['departure_hours' => '125', 'handover_to' => 'Kabila J.']);
        self::assertSame('departed', $this->field($id, 'status'));

        // Trace de tous les contrôles, dans l'ordre.
        self::assertSame(['unfit', 'fit', 'unfit', 'conditional'], array_column($this->db->fetchAll("SELECT conclusion FROM fleet_transfer_checks WHERE kind = 'pre_departure' ORDER BY id"), 'conclusion'));
        self::assertSame(4, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'fleet_check'"));

        // Arrivée : un point moins bon qu'au départ est un écart, à décrire.
        try {
            $this->transfers($this->managerL)->receive($id, ['arrival_hours' => '130'] + $this->checklist('fit', ['tyres' => ['reserve', 'Roue de secours'], 'body' => ['reserve', 'Aile droite enfoncée']]));
            self::fail('Écart à l\'arrivée non décrit');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('arrival_note', $e->errors);
        }
        $this->transfers($this->managerL)->receive($id, ['arrival_hours' => '130', 'arrival_note' => 'Aile droite enfoncée pendant le trajet']
            + $this->checklist('fit', ['tyres' => ['reserve', 'Roue de secours'], 'body' => ['reserve', 'Aile droite enfoncée']]));
        self::assertSame(['received', 1], [$this->field($id, 'status'), (int) $this->field($id, 'has_discrepancy')]);
        self::assertSame($this->likasi, $this->site());
    }

    public function testRequestRulesRejectionAndCancellation(): void
    {
        // Porte-char : conditions à documenter ; destination différente ; un seul transfert en cours par engin.
        foreach ([[['transport_mode' => 'low_loader'], 'transport_conditions'], [['to_site_id' => $this->kolwezi], 'to_site_id'], [['reason' => ''], 'reason']] as [$override, $field]) {
            try {
                $this->request(0, $override);
                self::fail("Validation attendue : $field");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }
        // Demande émise par le site de destination, sur porte-char documenté.
        $id = $this->request($this->managerL, ['transport_mode' => 'low_loader', 'transport_conditions' => 'Arrimage 4 points, escorte, itinéraire RN39']);
        self::assertSame($this->kolwezi, (int) $this->field($id, 'from_site_id'));
        try {
            $this->request();
            self::fail('Second transfert en cours pour le même engin');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('equipment_id', $e->errors);
        }
        try {
            $this->transfers($this->createUser('requester', [$this->createSite('KIPUSHI')]))->request(['equipment_id' => $this->truck, 'to_site_id' => $this->likasi, 'reason' => 'x', 'transport_mode' => 'own_power']);
            self::fail('Demande par un site étranger au transfert');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }

        // Annulation motivée par le demandeur, avant le départ.
        try {
            $this->transfers($this->requester)->cancel($id, 'Pas le mien');
            self::fail('Annulation par un tiers');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $this->transfers($this->managerL)->cancel($id, 'Chantier reporté');
        self::assertSame(['cancelled', 'Chantier reporté'], [$this->field($id, 'status'), $this->field($id, 'cancel_reason')]);
        self::assertSame('cancelled', $this->value("SELECT status FROM approval_requests WHERE entity_id = :e AND entity_type = 'fleet_transfer'", ['e' => $id]));

        // Refus de la Direction Logistique : l'engin reste sur son site.
        $refused = $this->request();
        $this->act($this->supervisor, $refused, 'unfavorable', 'Engin nécessaire à Kolwezi');
        self::assertSame('control', $this->stage($refused), 'L\'avis défavorable est consigné, l\'autorité décide');
        $this->transfers($this->technician)->recordCheck($refused, $this->checklist('fit'));
        $this->act($this->logistics, $refused, 'unfavorable', 'Avis hiérarchique suivi');
        self::assertSame('rejected', $this->field($refused, 'status'));
        self::assertSame($this->kolwezi, $this->site());
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM fleet_transfers'), 'Rien n\'est supprimé');
    }
}
