<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\StockLedgerRepository;
use App\Services\ApprovalWorkflow;
use App\Services\StockInventoryService;
use App\Services\StockPeriodService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Tests\DatabaseTestCase;

/**
 * Lot 2 — stocks multisites (cahier des charges § 5).
 * Recette R6 : sortie, transit, réception et écarts sans double comptage ; états magasin et consolidé cohérents.
 * Recette R7 : écart d'inventaire non modifiable sans approbation ; conservation de l'historique et clôture.
 */
final class StockLedgerTest extends DatabaseTestCase
{
    private int $kolwezi;
    private int $likasi;
    private int $keeperK;
    private int $keeperL;
    private int $logistics;
    private int $finance;
    private int $part;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kolwezi = $this->createSite('KOLWEZI');
        $this->likasi = $this->createSite('LIKASI');
        $this->keeperK = $this->createUser('storekeeper', [$this->kolwezi]);
        $this->keeperL = $this->createUser('storekeeper', [$this->likasi]);
        $this->logistics = $this->createUser('logistics', [], true);
        $this->finance = $this->createUser('finance', [], true);
        $this->part = $this->createPart('FLT-100', 0.0);
    }

    private function stock(int $user): StockService
    {
        return $this->service($user, StockService::class);
    }

    private function qty(int $site, ?int $part = null): float
    {
        return (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $part ?? $this->part, 's' => $site]);
    }

    /** @return array<string, array> lignes de l'état, indexées par « site » (ou référence en consolidé) */
    private function statement(int $user, bool $consolidated = false, ?string $period = null): array
    {
        [$from, $to] = StockPeriodService::bounds($period ?? date('Y-m'));
        $dt = DataTableRequest::fromQuery(['length' => 200], StockLedgerRepository::SORTABLE, ['x.reference', 'ASC']);
        $rows = $this->service($user, StockLedgerRepository::class)->statement($dt, $from, $to, ['consolidated' => $consolidated])['data'];

        return array_column($rows, null, $consolidated ? 'reference' : 'site');
    }

    private function approve(int $user, string $entity, int $id, string $decision = 'favorable', ?string $comment = null): array
    {
        $requestId = (int) $this->value('SELECT id FROM approval_requests WHERE entity_type = :t AND entity_id = :e ORDER BY id DESC LIMIT 1', ['t' => $entity, 'e' => $id]);

        return $this->service($user, ApprovalWorkflow::class)->act($requestId, ['decision' => $decision, 'comment' => $comment]);
    }

    // --- Mouvements et valorisation ---------------------------------------------

    public function testWeightedAverageCostAndMissingValueIsNeverZero(): void
    {
        $stock = $this->stock($this->keeperK);
        $stock->move($this->part, $this->kolwezi, 'receipt', 10, 'BL 1', null, null, ['unit_cost' => 20.0]);
        $stock->move($this->part, $this->kolwezi, 'receipt', 10, 'BL 2', null, null, ['unit_cost' => 30.0]);
        self::assertSame(25.0, (float) $this->value('SELECT avg_cost FROM part_stocks WHERE part_id = :p', ['p' => $this->part]), 'Coût moyen pondéré');
        self::assertSame(25.0, $stock->costOf($this->part, $this->kolwezi));

        $stock->move($this->part, $this->kolwezi, 'out', 4);
        $out = $this->row("SELECT * FROM stock_movements WHERE type = 'out'");
        self::assertSame([-4.0, 25.0, -100.0], [(float) $out['delta'], (float) $out['unit_cost'], (float) $out['value']]);
        self::assertSame(date('Y-m-d'), $out['moved_on']);
        self::assertSame(25.0, (float) $this->value('SELECT avg_cost FROM part_stocks WHERE part_id = :p', ['p' => $this->part]), 'Une sortie ne change pas le coût moyen');

        // Article sans coût connu : la valeur est « manquante », jamais zéro.
        $unknown = $this->createPart('INC-1', 0.0);
        $stock->move($unknown, $this->kolwezi, 'receipt', 5);
        self::assertNull($stock->costOf($unknown, $this->kolwezi));
        self::assertNull($this->value("SELECT value FROM stock_movements WHERE part_id = :p", ['p' => $unknown]));
        $rows = $this->statement($this->keeperK);
        self::assertCount(0, array_filter($rows, static fn (array $r): bool => $r['reference'] === 'INC-1' && $r['value_close'] !== null));
        $summary = $this->service($this->keeperK, StockLedgerRepository::class)->statementSummary(date('Y-m-01'), date('Y-m-t'), null);
        self::assertSame([400.0, 1], [(float) $summary['value_close'], (int) $summary['value_missing']]);
    }

    public function testManualMovementsAreNumberedAllocatedAndCannotAdjust(): void
    {
        $stock = $this->stock($this->keeperK);
        $equipment = $this->createEquipment($this->kolwezi, 'CAM-01');
        $foreign = $this->createEquipment($this->likasi, 'CAM-99');
        $base = ['site_id' => $this->kolwezi];

        $stock->moveFromInput($this->part, $base + ['type' => 'receipt', 'quantity' => '12', 'unit_cost' => '15.5']);
        self::assertMatchesRegularExpression('/^BR-\d{4}-00001$/', (string) $this->value("SELECT document_ref FROM stock_movements WHERE type = 'receipt'"));

        try {
            $stock->moveFromInput($this->part, $base + ['type' => 'out', 'quantity' => '2']);
            self::fail('Sortie sans bénéficiaire');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('equipment_id', $e->errors);
        }
        try {
            $stock->moveFromInput($this->part, $base + ['type' => 'out', 'quantity' => '2', 'equipment_id' => $foreign]);
            self::fail('Bénéficiaire d\'un autre site');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('equipment_id', $e->errors);
        }
        $stock->moveFromInput($this->part, $base + ['type' => 'out', 'quantity' => '2', 'equipment_id' => $equipment, 'comment' => 'Vidange']);
        $out = $this->row("SELECT * FROM stock_movements WHERE type = 'out'");
        self::assertSame([$equipment, -31.0], [(int) $out['equipment_id'], (float) $out['value']]);
        self::assertMatchesRegularExpression('/^BS-\d{4}-00001$/', (string) $out['document_ref']);

        // Un écart de stock ne se saisit pas à l'écran : il passe par un inventaire approuvé (R7).
        try {
            $stock->moveFromInput($this->part, $base + ['type' => 'adjust', 'quantity' => '3']);
            self::fail('Ajustement direct interdit');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('type', $e->errors);
        }
        try {
            $stock->moveFromInput($this->part, $base + ['type' => 'receipt', 'quantity' => '1', 'moved_on' => date('Y-m-d', strtotime('+2 days'))]);
            self::fail('Mouvement daté dans le futur');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('moved_on', $e->errors);
        }
        self::assertSame(10.0, $this->qty($this->kolwezi));
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM stock_movements'));
    }

    // --- R6 : transfert intermagasins -------------------------------------------

    public function testR6TransferGoesThroughTransitWithoutDoubleCounting(): void
    {
        $this->stock($this->keeperK)->move($this->part, $this->kolwezi, 'receipt', 10, null, null, null, ['unit_cost' => 20.0]);
        $transfers = fn (int $user): StockTransferService => $this->service($user, StockTransferService::class);

        // Contrôle de disponibilité dès la demande.
        try {
            $transfers($this->keeperL)->request(['from_site_id' => $this->kolwezi, 'to_site_id' => $this->likasi, 'reason' => 'Trop',
                'lines' => [['part_id' => $this->part, 'quantity' => '11']]]);
            self::fail('Quantité indisponible');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('lines', $e->errors);
        }

        // 1. Demande du magasin bénéficiaire : elle n'autorise pas la sortie.
        $id = $transfers($this->keeperL)->request(['from_site_id' => $this->kolwezi, 'to_site_id' => $this->likasi, 'reason' => 'Rupture de filtres à Likasi',
            'lines' => [['part_id' => $this->part, 'quantity' => '4']]]);
        $transfer = $this->row('SELECT * FROM stock_transfers WHERE id = :id', ['id' => $id]);
        self::assertSame('requested', $transfer['status']);
        self::assertMatchesRegularExpression('/^BT-\d{4}-00001$/', $transfer['reference']);
        try {
            $transfers($this->keeperK)->ship($id, []);
            self::fail('Sortie sans autorisation');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        self::assertSame(10.0, $this->qty($this->kolwezi));

        // 2. Autorisation Logistique : la quantité est réservée à l'origine.
        try {
            $this->approve($this->keeperK, StockTransferService::ENTITY, $id);
            self::fail('Un magasinier n\'autorise pas');
        } catch (HttpException $e) {
            self::assertContains($e->status, [403, 404], 'Refusé (403) ou invisible hors de son périmètre (404)');
        }
        self::assertSame('approved', $this->approve($this->logistics, StockTransferService::ENTITY, $id)['status']);
        self::assertSame('authorized', $this->value('SELECT status FROM stock_transfers WHERE id = :id', ['id' => $id]));
        self::assertSame(4.0, (float) $this->value('SELECT reserved FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $this->part, 's' => $this->kolwezi]));
        try {
            $this->stock($this->keeperK)->move($this->part, $this->kolwezi, 'out', 7);
            self::fail('Le réservé n\'est pas disponible pour un autre besoin');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('quantity', $e->errors);
        }

        // 3. Sortie : le magasin d'origine est débité, la marchandise est en transit, la destination n'a encore rien.
        try {
            $transfers($this->keeperL)->ship($id, []);
            self::fail('Seul le magasin d\'origine expédie');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $transfers($this->keeperK)->ship($id, ['shipped' => [$this->part => '4']]);
        self::assertSame([6.0, 0.0], [$this->qty($this->kolwezi), $this->qty($this->likasi)]);
        self::assertSame(0.0, (float) $this->value('SELECT reserved FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $this->part, 's' => $this->kolwezi]));

        $bySite = $this->statement($this->logistics);
        self::assertSame([6.0, -4.0, 0.0], [(float) $bySite['Site KOLWEZI']['qty_close'], (float) $bySite['Site KOLWEZI']['transfers'], (float) $bySite['Site KOLWEZI']['in_transit']]);
        self::assertSame([0.0, 4.0], [(float) $bySite['Site LIKASI']['qty_close'], (float) $bySite['Site LIKASI']['in_transit']]);
        $consolidated = $this->statement($this->logistics, true)['FLT-100'];
        self::assertSame(10.0, (float) $consolidated['qty_close'] + (float) $consolidated['in_transit'], 'Magasins + transit = total, sans double comptage');
        self::assertSame([6.0, 4.0], [(float) $consolidated['qty_close'], (float) $consolidated['in_transit']]);

        // 4. Réception contradictoire : 3 reçus sur 4, l'écart doit être expliqué.
        try {
            $transfers($this->keeperK)->receive($id, []);
            self::fail('Le magasin d\'origine ne réceptionne pas');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $transfers($this->keeperL)->receive($id, ['received' => [$this->part => '3']]);
            self::fail('Écart non expliqué');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('discrepancy_note', $e->errors);
        }
        try {
            $transfers($this->keeperL)->receive($id, ['received' => [$this->part => '5'], 'discrepancy_note' => 'x']);
            self::fail('Plus que l\'expédié');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('quantity', $e->errors);
        }
        $transfers($this->keeperL)->receive($id, ['received' => [$this->part => '3'], 'discrepancy_note' => 'Un carton endommagé au déchargement']);

        $transfer = $this->row('SELECT * FROM stock_transfers WHERE id = :id', ['id' => $id]);
        self::assertSame(['received', 1, $this->keeperL], [$transfer['status'], (int) $transfer['has_discrepancy'], (int) $transfer['received_by']]);
        $line = $this->row('SELECT * FROM stock_transfer_lines WHERE transfer_id = :id', ['id' => $id]);
        self::assertSame([4.0, 4.0, 3.0, 20.0], [(float) $line['qty_requested'], (float) $line['qty_shipped'], (float) $line['qty_received'], (float) $line['unit_cost']]);

        // 5. États cohérents : rien en transit, l'écart (1) est tracé sur le bon et n'est dans aucun magasin.
        self::assertSame([6.0, 3.0], [$this->qty($this->kolwezi), $this->qty($this->likasi)]);
        self::assertSame(20.0, (float) $this->value('SELECT avg_cost FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $this->part, 's' => $this->likasi]), 'Valorisé au coût d\'origine');
        $consolidated = $this->statement($this->logistics, true)['FLT-100'];
        self::assertSame([9.0, 0.0, -1.0, 180.0], [(float) $consolidated['qty_close'], (float) $consolidated['in_transit'], (float) $consolidated['transfers'], (float) $consolidated['value_close']]);
        self::assertSame(['receipt', 'transfer_out', 'transfer_in'], array_column($this->db->fetchAll('SELECT type FROM stock_movements ORDER BY id'), 'type'));
        self::assertSame([$transfer['reference'], $transfer['reference']], array_column($this->db->fetchAll("SELECT document_ref FROM stock_movements WHERE transfer_id = :id", ['id' => $id]), 'document_ref'));

        // Le magasinier de Likasi ne voit que son magasin.
        self::assertSame(['Site LIKASI'], array_keys($this->statement($this->keeperL)));
    }

    public function testRejectedOrCancelledTransferLeavesStockUntouched(): void
    {
        $this->stock($this->keeperK)->move($this->part, $this->kolwezi, 'receipt', 10);
        $transfers = fn (int $user): StockTransferService => $this->service($user, StockTransferService::class);
        $request = ['from_site_id' => $this->kolwezi, 'to_site_id' => $this->likasi, 'reason' => 'Besoin', 'lines' => [['part_id' => $this->part, 'quantity' => '4']]];

        $rejected = $transfers($this->keeperL)->request($request);
        $this->approve($this->logistics, StockTransferService::ENTITY, $rejected, 'unfavorable', 'Stock nécessaire à Kolwezi');
        self::assertSame('rejected', $this->value('SELECT status FROM stock_transfers WHERE id = :id', ['id' => $rejected]));

        $cancelled = $transfers($this->keeperL)->request($request);
        $this->approve($this->logistics, StockTransferService::ENTITY, $cancelled);
        self::assertSame(4.0, (float) $this->value('SELECT reserved FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $this->part, 's' => $this->kolwezi]));
        $transfers($this->keeperL)->cancel($cancelled, 'Besoin levé');
        self::assertSame('cancelled', $this->value('SELECT status FROM stock_transfers WHERE id = :id', ['id' => $cancelled]));
        self::assertSame([10.0, 0.0], [$this->qty($this->kolwezi), (float) $this->value('SELECT reserved FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $this->part, 's' => $this->kolwezi])]);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM stock_movements'));
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM stock_transfers'), 'Rien n\'est supprimé');
    }

    // --- R7 : inventaire ---------------------------------------------------------

    public function testR7InventoryGapChangesStockOnlyAfterApproval(): void
    {
        $this->stock($this->keeperK)->move($this->part, $this->kolwezi, 'receipt', 10, null, null, null, ['unit_cost' => 20.0]);
        $other = $this->createPart('HUI-200', 5.0);
        $this->stock($this->keeperK)->move($other, $this->kolwezi, 'receipt', 50);
        $inventories = fn (int $user): StockInventoryService => $this->service($user, StockInventoryService::class);

        try {
            $inventories($this->keeperL)->open(['site_id' => $this->kolwezi]);
            self::fail('Inventaire d\'un autre magasin');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('site_id', $e->errors);
        }
        $id = $inventories($this->keeperK)->open(['site_id' => $this->kolwezi, 'note' => 'Inventaire tournant']);
        self::assertSame([10.0, 50.0], array_map('floatval', array_column($this->db->fetchAll('SELECT qty_theoretical FROM stock_inventory_lines WHERE inventory_id = :i ORDER BY part_id', ['i' => $id]), 'qty_theoretical')));
        try {
            $inventories($this->keeperK)->open(['site_id' => $this->kolwezi]);
            self::fail('Un seul inventaire ouvert par magasin');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('site_id', $e->errors);
        }

        // Comptage : 8 au lieu de 10 (écart), 50 conforme.
        $inventories($this->keeperK)->saveCounts($id, ['counted' => [$this->part => '8', $other => '50']]);
        try {
            $inventories($this->keeperK)->submit($id);
            self::fail('Écart non justifié');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('justification', $e->errors);
        }
        $inventories($this->keeperK)->saveCounts($id, ['counted' => [$this->part => '8'], 'justification' => [$this->part => 'Deux filtres cassés non déclarés']]);
        self::assertSame('submitted', $inventories($this->keeperK)->submit($id));

        // Soumis : stock théorique inchangé, comptage verrouillé.
        self::assertSame(10.0, $this->qty($this->kolwezi), 'Stock constaté ≠ stock théorique tant que l\'écart n\'est pas approuvé');
        try {
            $inventories($this->keeperK)->saveCounts($id, ['counted' => [$this->part => '10']]);
            self::fail('Écart non modifiable après soumission');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        self::assertSame(8.0, (float) $this->value('SELECT qty_counted FROM stock_inventory_lines WHERE inventory_id = :i AND part_id = :p', ['i' => $id, 'p' => $this->part]));

        // Une sortie réelle survient entre le comptage et l'approbation : l'écart s'applique en plus.
        $this->stock($this->keeperK)->move($this->part, $this->kolwezi, 'out', 1);

        // Le magasinier qui a compté n'approuve pas ses écarts.
        try {
            $this->approve($this->keeperK, StockInventoryService::ENTITY, $id);
            self::fail('Séparation compteur / approbateur');
        } catch (ValidationException | HttpException $e) {
            self::assertSame(9.0, $this->qty($this->kolwezi));
        }
        self::assertSame('approved', $this->approve($this->logistics, StockInventoryService::ENTITY, $id)['status']);

        self::assertSame('approved', $this->value('SELECT status FROM stock_inventories WHERE id = :i', ['i' => $id]));
        self::assertSame(7.0, $this->qty($this->kolwezi), '10 − 1 sortie − 2 d\'écart');
        $movement = $this->row("SELECT * FROM stock_movements WHERE type = 'inventory'");
        self::assertSame([-2.0, $id, -40.0], [(float) $movement['delta'], (int) $movement['inventory_id'], (float) $movement['value']]);
        self::assertStringContainsString('Deux filtres cassés', (string) $movement['comment']);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM stock_movements WHERE type = 'inventory'"), 'Aucun mouvement pour la ligne conforme');
        // Historique conservé : les mouvements d'origine sont toujours là, inchangés.
        self::assertSame(['receipt', 'receipt', 'out', 'inventory'], array_column($this->db->fetchAll('SELECT type FROM stock_movements ORDER BY id'), 'type'));
        self::assertSame(10.0, (float) $this->value("SELECT stock_after FROM stock_movements WHERE part_id = :p AND type = 'receipt'", ['p' => $this->part]));
        $row = $this->statement($this->keeperK);
        self::assertSame(-2.0, (float) array_values(array_filter($row, static fn (array $r) => $r['reference'] === 'FLT-100') ?: $this->statementRows($this->keeperK, 'FLT-100'))[0]['adjustments']);
    }

    /** Lignes de l'état pour une référence (plusieurs articles sur un même site). */
    private function statementRows(int $user, string $reference): array
    {
        $dt = DataTableRequest::fromQuery(['length' => 200], StockLedgerRepository::SORTABLE, ['x.reference', 'ASC']);
        $rows = $this->service($user, StockLedgerRepository::class)->statement($dt, date('Y-m-01'), date('Y-m-t'), [])['data'];

        return array_values(array_filter($rows, static fn (array $r): bool => $r['reference'] === $reference));
    }

    public function testRejectedInventoryLeavesStockUnchangedAndCountWithoutGapClosesDirectly(): void
    {
        $this->stock($this->keeperK)->move($this->part, $this->kolwezi, 'receipt', 10);
        $inventories = fn (int $user): StockInventoryService => $this->service($user, StockInventoryService::class);

        $id = $inventories($this->keeperK)->open(['site_id' => $this->kolwezi]);
        $inventories($this->keeperK)->saveCounts($id, ['counted' => [$this->part => '12'], 'justification' => [$this->part => 'Erreur de comptage ?']]);
        $inventories($this->keeperK)->submit($id);
        $this->approve($this->logistics, StockInventoryService::ENTITY, $id, 'unfavorable', 'Recompter : écart inexpliqué');
        self::assertSame(['rejected', 10.0], [$this->value('SELECT status FROM stock_inventories WHERE id = :i', ['i' => $id]), $this->qty($this->kolwezi)]);
        self::assertSame(12.0, (float) $this->value('SELECT qty_counted FROM stock_inventory_lines WHERE inventory_id = :i', ['i' => $id]), 'Le comptage refusé reste consultable');

        $clean = $inventories($this->keeperK)->open(['site_id' => $this->kolwezi]);
        $inventories($this->keeperK)->saveCounts($clean, ['counted' => [$this->part => '10']]);
        self::assertSame('approved', $inventories($this->keeperK)->submit($clean));
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM approval_requests WHERE entity_id = :i AND entity_type = 'stock_inventory'", ['i' => $clean]));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM stock_movements'));
    }

    // --- Clôture -----------------------------------------------------------------

    public function testClosedPeriodIsLockedAndItsBalancesAreFrozen(): void
    {
        $lastMonth = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $stock = $this->stock($this->keeperK);
        $stock->move($this->part, $this->kolwezi, 'receipt', 10, null, null, null, ['unit_cost' => 20.0, 'moved_on' => $lastMonth . '-05']);
        $stock->move($this->part, $this->kolwezi, 'out', 3, null, null, null, ['moved_on' => $lastMonth . '-20']);
        $periods = fn (int $user): StockPeriodService => $this->service($user, StockPeriodService::class);

        try {
            $periods($this->keeperK)->close(['site_id' => $this->kolwezi, 'period' => $lastMonth]);
            self::fail('Un magasinier ne clôture pas');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $periods($this->finance)->close(['site_id' => $this->kolwezi, 'period' => date('Y-m')]);
            self::fail('Mois en cours');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period', $e->errors);
        }

        $periods($this->finance)->close(['site_id' => $this->kolwezi, 'period' => $lastMonth]);
        $balance = $this->row('SELECT * FROM stock_period_balances WHERE site_id = :s AND period = :p', ['s' => $this->kolwezi, 'p' => $lastMonth]);
        self::assertSame([0.0, 10.0, 3.0, 7.0, 140.0], [(float) $balance['qty_open'], (float) $balance['qty_in'], (float) $balance['qty_out'], (float) $balance['qty_close'], (float) $balance['value_close']]);
        try {
            $periods($this->finance)->close(['site_id' => $this->kolwezi, 'period' => $lastMonth]);
            self::fail('Double clôture');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('period', $e->errors);
        }

        // Période verrouillée : plus aucune écriture datée dedans ; la régularisation se date dans la période ouverte.
        try {
            $stock->move($this->part, $this->kolwezi, 'receipt', 5, 'Oubli', null, null, ['moved_on' => $lastMonth . '-28']);
            self::fail('Écriture dans une période clôturée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('moved_on', $e->errors);
        }
        $stock->move($this->part, $this->kolwezi, 'receipt', 5, 'Régularisation', null, null, ['unit_cost' => 50.0]);
        self::assertSame(12.0, $this->qty($this->kolwezi));

        // État consultable à date : le mois clôturé garde ses quantités et sa valeur figée, malgré le nouveau coût moyen.
        $closed = $this->statement($this->finance, false, $lastMonth)['Site KOLWEZI'];
        self::assertSame([0.0, 10.0, 3.0, 7.0, 140.0], [(float) $closed['qty_open'], (float) $closed['receipts'], (float) $closed['issues'], (float) $closed['qty_close'], (float) $closed['value_close']]);
        $current = $this->statement($this->finance)['Site KOLWEZI'];
        self::assertSame([7.0, 5.0, 12.0], [(float) $current['qty_open'], (float) $current['receipts'], (float) $current['qty_close']]);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM activity_log WHERE action = 'stock_close'"));
    }

    public function testPeriodsCloseInOrderAndNotWithGoodsInTransit(): void
    {
        $twoAgo = date('Y-m', strtotime(date('Y-m-01') . ' -2 month'));
        $lastMonth = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $this->stock($this->keeperK)->move($this->part, $this->kolwezi, 'receipt', 10, null, null, null, ['moved_on' => $twoAgo . '-10']);
        $periods = $this->service($this->finance, StockPeriodService::class);
        try {
            $periods->close(['site_id' => $this->kolwezi, 'period' => $lastMonth]);
            self::fail('Période antérieure encore ouverte');
        } catch (ValidationException $e) {
            self::assertStringContainsString($twoAgo, $e->errors['period']);
        }
        $periods->close(['site_id' => $this->kolwezi, 'period' => $twoAgo]);

        // Un transfert en transit bloque la clôture des deux magasins concernés.
        $id = $this->service($this->keeperL, StockTransferService::class)->request(['from_site_id' => $this->kolwezi, 'to_site_id' => $this->likasi,
            'reason' => 'Test', 'lines' => [['part_id' => $this->part, 'quantity' => '2']]]);
        $this->approve($this->logistics, StockTransferService::ENTITY, $id);
        $this->service($this->keeperK, StockTransferService::class)->ship($id, []);
        $this->db->execute("UPDATE stock_transfers SET shipped_at = :d WHERE id = :id", ['d' => $lastMonth . '-15 10:00:00', 'id' => $id]);
        $this->expectException(ValidationException::class);
        $this->service($this->finance, StockPeriodService::class)->close(['site_id' => $this->likasi, 'period' => $lastMonth]);
    }
}
