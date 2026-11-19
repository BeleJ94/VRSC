<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\EquipmentCostRepository;
use App\Services\ApprovalWorkflow;
use App\Services\EquipmentCostService;
use App\Services\FluidService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\StockService;
use App\Services\WorkOrderService;
use Tests\DatabaseTestCase;

/**
 * Lot 6 — carburant et coût mensuel par équipement (cahier des charges § 8).
 * Recette R8 : carburant + pièces + maintenance + autres frais, une seule imputation ; données manquantes signalées.
 */
final class EquipmentCostTest extends DatabaseTestCase
{
    private int $site;
    private int $manager;
    private int $keeper;
    private int $finance;
    private int $truck;
    private int $fuel;
    private int $filter;
    private int $bearing;
    private int $oil;
    private string $period;
    private string $lastMonth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = date('Y-m');
        $this->lastMonth = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $this->site = $this->createSite('KOLWEZI');
        $this->manager = $this->createUser('manager', [$this->site]);
        $this->keeper = $this->createUser('storekeeper', [$this->site]);
        $this->finance = $this->createUser('finance', [], true);
        $this->truck = $this->createEquipment($this->site, 'CAM-01', 'available', 1000);
        // Index connu avant le début du mois : 1 000 h.
        $this->db->execute('INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, 1000, :at)', ['e' => $this->truck, 'at' => $this->lastMonth . '-20 08:00:00']);

        $stock = $this->service($this->keeper, StockService::class);
        $this->fuel = $this->article('GASOIL', 'fuel', 'L');
        $this->filter = $this->article('FLT-100', 'filter');
        $this->bearing = $this->article('RLT-200', 'part');
        $this->oil = $this->article('HUI-15W40', 'lubricant', 'L');
        foreach ([[$this->fuel, 500, 1.5], [$this->filter, 5, 20.0], [$this->bearing, 3, 30.0], [$this->oil, 50, 4.0]] as [$part, $qty, $cost]) {
            $stock->move($part, $this->site, 'receipt', $qty, null, null, null, ['unit_cost' => $cost]);
        }
    }

    private function article(string $reference, string $type, string $unit = 'u'): int
    {
        $id = $this->createPart($reference, 0.0);
        $this->db->execute('UPDATE parts SET part_type = :t, unit = :u WHERE id = :id', ['t' => $type, 'u' => $unit, 'id' => $id]);

        return $id;
    }

    private function costs(int $user): EquipmentCostService
    {
        return $this->service($user, EquipmentCostService::class);
    }

    private function costRow(?string $period = null, ?int $user = null): array
    {
        return $this->service($user ?? $this->finance, EquipmentCostRepository::class)->monthlyRow($this->truck, $period ?? $this->period) ?? [];
    }

    private function mechanic(string $name, ?float $rate): int
    {
        return $this->db->insert(
            "INSERT INTO personnel (site_id, first_name, last_name, job, hourly_rate) VALUES (:s, :f, 'Test', 'mechanic', :r)",
            ['s' => $this->site, 'f' => $name, 'r' => $rate]
        );
    }

    /** Scénario complet du mois : carburant (cuve + station), pièces, lubrifiant, main-d'œuvre, prestation, autre charge. */
    private function month(): array
    {
        $costs = $this->costs($this->manager);
        $tank = $costs->refill(['equipment_id' => $this->truck, 'litres' => '100', 'source' => 'stock', 'part_id' => $this->fuel, 'hour_meter' => '1040', 'voucher_ref' => 'BON-17']);
        $station = $costs->refill(['equipment_id' => $this->truck, 'litres' => '50', 'source' => 'external', 'station' => 'Total Kolwezi', 'unit_price' => '2', 'hour_meter' => '1060']);

        $orders = $this->service($this->manager, WorkOrderService::class);
        $wo = $orders->create(['equipment_id' => $this->truck, 'title' => 'Révision 1 000 h']);
        $orders->consumePart($wo, ['part_id' => $this->filter, 'quantity' => '2']);
        $orders->consumePart($wo, ['part_id' => $this->bearing, 'quantity' => '1']);
        $this->service($this->manager, FluidService::class)->log($this->truck, ['part_id' => $this->oil, 'quantity' => '10', 'kind' => 'top_up']);

        $paid = $this->mechanic('Kasongo', 10.0);
        $unknown = $this->mechanic('Ilunga', null);
        $orders->addTechnician($wo, ['personnel_id' => $paid, 'minutes' => '120']);
        $orders->addTechnician($wo, ['personnel_id' => $unknown, 'minutes' => '60']);
        $orders->addService($wo, ['description' => 'Réglage injection (atelier externe)', 'amount' => '80', 'document_ref' => 'FAC-9']);
        $orders->addService($wo, ['description' => 'Dépannage sur route']);
        $costs->addCharge(['equipment_id' => $this->truck, 'charge_date' => date('Y-m-d'), 'label' => 'Péage', 'amount' => '15']);

        return ['tank' => $tank, 'station' => $station, 'wo' => $wo, 'unknown' => $unknown];
    }

    public function testR8MonthlyCostAddsEveryComponentOnceAndFlagsMissingData(): void
    {
        $ids = $this->month();
        $row = $this->costRow();

        // Chaque composante, lue dans sa pièce source.
        self::assertSame(
            ['fuel' => 250.0, 'parts' => 30.0, 'lubricants' => 40.0, 'tyres_filters' => 40.0, 'labor' => 20.0, 'external' => 80.0, 'other' => 15.0],
            array_map('floatval', array_intersect_key($row, array_flip(EquipmentCostRepository::KINDS)))
        );
        self::assertSame(475.0, (float) $row['total'], '250 + 30 + 40 + 40 + 20 + 80 + 15');
        self::assertSame(475.0, array_sum(array_map(static fn (string $k) => (float) $row[$k], EquipmentCostRepository::KINDS)), 'Le total est la somme des composantes');

        // Données manquantes signalées, jamais comptées pour zéro en silence : mécanicien sans taux, prestation sans montant.
        self::assertSame(2, (int) $row['missing_count']);

        // Compteurs début / fin, usage, coût à l'heure, consommation.
        self::assertSame([1000, 1060, 60], [(int) $row['hours_start'], (int) $row['hours_end'], (int) $row['usage_value']]);
        self::assertSame([7.92, 150.0, 2.5], [(float) $row['unit_cost'], (float) $row['litres'], (float) $row['fuel_ratio']], '475 / 60 h ; 150 L / 60 h');
        self::assertSame('hours', $row['meter_basis']);

        // Une seule imputation : le plein à la cuve n'apparaît que par sa sortie de stock.
        $lines = $this->service($this->finance, EquipmentCostRepository::class)->detail($this->truck, date('Y-m-01'), date('Y-m-t'));
        $sources = array_count_values(array_column($lines, 'source_type'));
        self::assertEquals(['stock_movement' => 4, 'fuel_refill' => 1, 'work_order_technician' => 2, 'work_order_service' => 2, 'equipment_charge' => 1], $sources);
        $fuelLines = array_column(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'fuel'), 'amount', 'source_type');
        self::assertEquals(['stock_movement' => 150.0, 'fuel_refill' => 100.0], array_map('floatval', $fuelLines), 'Deux pleins, deux lignes — pas trois');
        self::assertSame(475.0, array_sum(array_map(static fn (array $l) => (float) $l['amount'], $lines)), 'Le détail retombe sur le total');
        self::assertCount(2, array_filter($lines, static fn (array $l): bool => $l['amount'] === null));
        $keys = array_map(static fn (array $l) => $l['source_type'] . ':' . $l['source_id'], $lines);
        self::assertSame($keys, array_unique($keys), 'Aucune pièce source en double');

        // Le ravitaillement à la cuve est une vraie sortie de stock, valorisée au coût moyen.
        $refill = $this->db->fetchOne('SELECT * FROM fuel_refills WHERE id = :id', ['id' => $ids['tank']]);
        self::assertMatchesRegularExpression('/^RAV-\d{4}-00001$/', $refill['reference']);
        self::assertNotNull($refill['stock_movement_id']);
        self::assertSame(400.0, (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p', ['p' => $this->fuel]));

        // Donnée complétée : le coût se met à jour, le signalement disparaît.
        $this->db->execute('UPDATE personnel SET hourly_rate = 20 WHERE id = :id', ['id' => $ids['unknown']]);
        $row = $this->costRow();
        self::assertSame([40.0, 495.0, 1], [(float) $row['labor'], (float) $row['total'], (int) $row['missing_count']]);

        // Totaux du site cohérents avec la ligne de l'engin.
        $totals = $this->service($this->finance, EquipmentCostRepository::class)->monthlyTotals($this->period);
        self::assertSame([495.0, 250.0, 1, 1], [(float) $totals['total'], (float) $totals['fuel'], (int) $totals['incomplete_count'], (int) $totals['equipment_count']]);
    }

    public function testPurchaseForAnEquipmentIsNeverCountedTwice(): void
    {
        $requester = $this->createUser('requester', [$this->site]);
        $supervisor = $this->createUser('supervisor', [$this->site]);
        $logistics = $this->createUser('logistics', [], true);
        $buyer = $this->createUser('buyer', [], true);
        $supplier = $this->db->insert("INSERT INTO suppliers (code, name) VALUES ('FRN-A', 'Atelier Kat')");

        // EDB pour l'engin : 1 filtre (servi du stock) et une prestation (achetée).
        $edb = $this->service($requester, PurchaseRequestService::class)->create([
            'site_id' => $this->site, 'equipment_id' => $this->truck, 'category' => 'services', 'urgency' => 'normal', 'subject' => 'Révision', 'reason' => 'Panne',
            'lines' => [['part_id' => $this->filter, 'quantity' => '1'], ['description' => 'Rectification culasse', 'quantity' => '1', 'est_unit_cost' => '300']],
        ]);
        foreach ([$supervisor, $logistics] as $approver) {
            $request = (int) $this->value("SELECT id FROM approval_requests WHERE entity_type = 'purchase_request' AND entity_id = :e", ['e' => $edb]);
            $this->service($approver, ApprovalWorkflow::class)->act($request, ['decision' => 'favorable']);
        }
        $lines = array_column($this->db->fetchAll('SELECT id, part_id FROM purchase_request_lines WHERE request_id = :r ORDER BY id', ['r' => $edb]), 'id');
        $this->service($this->keeper, PurchaseRequestService::class)->issueFromStock($edb, ['line_id' => $lines[0], 'quantity' => '1']);
        $orders = $this->service($buyer, PurchaseOrderService::class);
        $orders->selectQuote($edb, ['quote_id' => $orders->addQuote($edb, ['supplier_id' => $supplier, 'amount' => '280'])]);
        $order = $orders->createOrder($edb, ['lines' => [$lines[1] => ['quantity' => '1', 'unit_price' => '280']]]);
        $orderLine = (int) $this->value('SELECT id FROM purchase_order_lines WHERE order_id = :o', ['o' => $order]);
        $this->service($this->keeper, PurchaseOrderService::class)->receive($order, ['delivery_note' => 'PV-1', 'accepted' => [$orderLine => '1']]);

        // Le filtre compte une fois (sa sortie de stock), la prestation une fois (sa réception) ; l'EDB lui-même n'ajoute rien.
        $row = $this->costRow();
        self::assertSame([20.0, 280.0, 300.0, 0], [(float) $row['tyres_filters'], (float) $row['external'], (float) $row['total'], (int) $row['missing_count']]);
        $detail = $this->service($this->finance, EquipmentCostRepository::class)->detail($this->truck, date('Y-m-01'), date('Y-m-t'));
        self::assertSame(['purchase_fulfilment', 'stock_movement'], array_values(array_unique(array_column($detail, 'source_type'))));
        self::assertCount(2, $detail);
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM purchase_fulfilments'), 'Deux satisfactions d\'EDB, mais seule la prestation non stockée est un coût direct');
    }

    public function testComparisonCumulativeAlertsAndMeterControls(): void
    {
        $this->month();
        // Mois précédent : une charge de 100.
        $this->db->execute("INSERT INTO equipment_charges (equipment_id, site_id, charge_date, label, amount) VALUES (:e, :s, :d, 'Assurance', 100)",
            ['e' => $this->truck, 's' => $this->site, 'd' => $this->lastMonth . '-15']);
        $row = $this->costRow();
        self::assertSame([100.0, 375.0], [(float) $row['prev_total'], (float) $row['variation']], 'Comparaison au mois précédent');
        self::assertSame(date('m') === '01' ? 475.0 : 575.0, (float) $row['ytd_total'], 'Cumul annuel');
        $history = $this->service($this->finance, EquipmentCostRepository::class)->history($this->truck, $this->period);
        self::assertCount(12, $history);
        self::assertSame([$this->lastMonth, 100.0, $this->period, 475.0], [$history[10]['period'], $history[10]['total'], $history[11]['period'], $history[11]['total']]);

        // Consommation atypique par rapport à la référence paramétrable (tolérance ± 20 %).
        self::assertNull($row['fuel_alert'], 'Sans référence, pas d\'alerte');
        $this->db->execute('UPDATE equipment SET fuel_reference = 1.0 WHERE id = :id', ['id' => $this->truck]);
        self::assertSame('over', $this->costRow()['fuel_alert'], '2,5 L/h pour une référence de 1,0');
        $this->db->execute('UPDATE equipment SET fuel_reference = 2.4 WHERE id = :id', ['id' => $this->truck]);
        self::assertNull($this->costRow()['fuel_alert']);
        $this->db->execute('UPDATE equipment SET fuel_reference = 4.0 WHERE id = :id', ['id' => $this->truck]);
        self::assertSame('under', $this->costRow()['fuel_alert']);

        // Compteurs incohérents : recul refusé, compteur de suivi obligatoire, saut inhabituel signalé.
        $costs = $this->costs($this->manager);
        foreach ([[['hour_meter' => '900'], 'hour_meter'], [[], 'hour_meter'], [['hour_meter' => '1101', 'source' => 'stock', 'part_id' => $this->filter], 'part_id'],
            [['hour_meter' => '1101', 'refill_date' => date('Y-m-d', strtotime('+1 day'))], 'refill_date']] as [$override, $field]) {
            try {
                $costs->refill($override + ['equipment_id' => $this->truck, 'litres' => '10', 'source' => 'external', 'unit_price' => '2']);
                self::fail("Validation attendue : $field");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }
        self::assertSame(0, (int) $this->costRow()['meter_alert']);
        $jump = $costs->refill(['equipment_id' => $this->truck, 'litres' => '10', 'source' => 'external', 'hour_meter' => '1500']);
        self::assertStringContainsString('440', (string) $this->value('SELECT meter_alert FROM fuel_refills WHERE id = :id', ['id' => $jump]));
        $row = $this->costRow();
        self::assertSame([1, 3], [(int) $row['meter_alert'], (int) $row['missing_count']], 'Saut de compteur signalé ; plein sans prix = coût manquant');
        self::assertSame(250.0, (float) $row['fuel'], 'Le plein sans prix n\'est pas compté pour zéro en silence : il est signalé');

        // Un magasinier d'un autre site n'enregistre pas de plein pour cet engin.
        $other = $this->createUser('storekeeper', [$this->createSite('LIKASI')]);
        $this->expectException(HttpException::class);
        $this->costs($other)->refill(['equipment_id' => $this->truck, 'litres' => '10', 'source' => 'external', 'hour_meter' => '1600']);
    }

    public function testMonthlyValidationFreezesTheReport(): void
    {
        $this->db->execute("INSERT INTO equipment_charges (equipment_id, site_id, charge_date, label, amount) VALUES (:e, :s, :d, 'Assurance', 100)",
            ['e' => $this->truck, 's' => $this->site, 'd' => $this->lastMonth . '-15']);
        $this->db->execute('INSERT INTO work_order_sequences (year, last_number) VALUES (:y, 0) ON DUPLICATE KEY UPDATE last_number = last_number', ['y' => (int) date('Y')]);
        $costs = $this->costs($this->finance);

        try {
            $this->costs($this->manager)->close(['site_id' => $this->site, 'period' => $this->lastMonth]);
            self::fail('Un responsable maintenance ne valide pas les coûts');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $costs->close(['site_id' => $this->site, 'period' => $this->period]);
            self::fail('Mois en cours');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period', $e->errors);
        }
        $this->costs($this->manager)->saveNote($this->truck, ['period' => $this->lastMonth, 'note' => 'Assurance annuelle imputée ce mois']);
        self::assertSame(1, $costs->close(['site_id' => $this->site, 'period' => $this->lastMonth, 'note' => 'Validé en comité']));
        $closing = $this->service($this->finance, EquipmentCostRepository::class)->closing($this->site, $this->lastMonth);
        self::assertSame(['Validé en comité', $this->finance], [$closing['note'], (int) $closing['closed_by']]);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'cost_close'"));
        try {
            $costs->close(['site_id' => $this->site, 'period' => $this->lastMonth]);
            self::fail('Double clôture');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period', $e->errors);
        }

        // Période close : plus de saisie datée dedans, plus d'observation.
        try {
            $this->costs($this->manager)->addCharge(['equipment_id' => $this->truck, 'charge_date' => $this->lastMonth . '-28', 'label' => 'Oubli', 'amount' => '50']);
            self::fail('Charge dans une période validée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period', $e->errors);
        }
        try {
            $this->costs($this->manager)->saveNote($this->truck, ['period' => $this->lastMonth, 'note' => 'Après coup']);
            self::fail('Observation après validation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period', $e->errors);
        }

        // Le rapport figé ne bouge plus, même si une pièce tardive apparaît dans les sources.
        $this->db->execute("INSERT INTO equipment_charges (equipment_id, site_id, charge_date, label, amount) VALUES (:e, :s, :d, 'Pièce tardive', 999)",
            ['e' => $this->truck, 's' => $this->site, 'd' => $this->lastMonth . '-20']);
        $frozen = $this->costRow($this->lastMonth);
        self::assertSame([100.0, 100.0, 1, 'Assurance annuelle imputée ce mois'], [(float) $frozen['total'], (float) $frozen['other'], (int) $frozen['is_closed'], $frozen['observation']]);

        // Le mois courant reste ouvert et vivant.
        $this->costs($this->manager)->addCharge(['equipment_id' => $this->truck, 'charge_date' => date('Y-m-d'), 'label' => 'Péage', 'amount' => '15']);
        self::assertSame([15.0, 0], [(float) $this->costRow()['total'], (int) $this->costRow()['is_closed']]);
    }
}
