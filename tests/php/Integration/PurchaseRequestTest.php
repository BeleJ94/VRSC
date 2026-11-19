<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\PurchaseRepository;
use App\Services\ApprovalWorkflow;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Tests\DatabaseTestCase;

/**
 * Lot 3 — états de besoins et approvisionnements (cahier des charges § 6).
 * Recette R1 : EDB approuvé, recherche stock, commande, réception et statut exact (ni commande ni paiement ne clôturent).
 * Recette R2 : réception partielle → sous-statut « exécution partielle », quantités et montants restants exacts, report mensuel.
 */
final class PurchaseRequestTest extends DatabaseTestCase
{
    private int $kolwezi;
    private int $likasi;
    private int $requester;
    private int $supervisor;
    private int $logistics;
    private int $finance;
    private int $buyer;
    private int $keeperK;
    private int $keeperL;
    private int $part;
    private int $supplierA;
    private int $supplierB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kolwezi = $this->createSite('KOLWEZI');
        $this->likasi = $this->createSite('LIKASI');
        $this->requester = $this->createUser('requester', [$this->kolwezi]);
        $this->supervisor = $this->createUser('supervisor', [$this->kolwezi]);
        $this->logistics = $this->createUser('logistics', [], true);
        $this->finance = $this->createUser('finance', [], true);
        $this->buyer = $this->createUser('buyer', [], true);
        $this->keeperK = $this->createUser('storekeeper', [$this->kolwezi]);
        $this->keeperL = $this->createUser('storekeeper', [$this->likasi]);
        $this->part = $this->createPart('FLT-100', 0.0);
        $this->supplierA = $this->db->insert("INSERT INTO suppliers (code, name) VALUES ('FRN-A', 'Katanga Diesel')");
        $this->supplierB = $this->db->insert("INSERT INTO suppliers (code, name) VALUES ('FRN-B', 'Congo Pièces')");
    }

    private function requests(int $user): PurchaseRequestService
    {
        return $this->service($user, PurchaseRequestService::class);
    }

    private function orders(int $user): PurchaseOrderService
    {
        return $this->service($user, PurchaseOrderService::class);
    }

    private function approve(int $user, string $entity, int $id, string $decision = 'favorable', ?string $comment = null): array
    {
        $requestId = (int) $this->value('SELECT id FROM approval_requests WHERE entity_type = :t AND entity_id = :e ORDER BY id DESC LIMIT 1', ['t' => $entity, 'e' => $id]);

        return $this->service($user, ApprovalWorkflow::class)->act($requestId, ['decision' => $decision, 'comment' => $comment]);
    }

    private function edb(array $lines = null, array $extra = []): int
    {
        return $this->requests($this->requester)->create($extra + [
            'site_id' => $this->kolwezi, 'category' => 'parts', 'urgency' => 'urgent', 'subject' => 'Filtres pour la flotte',
            'reason' => 'Révision des 500 h de trois tombereaux',
            'lines' => $lines ?? [
                ['part_id' => $this->part, 'quantity' => '10', 'est_unit_cost' => '20'],
                ['description' => 'Location d\'une grue 1 jour', 'quantity' => '1', 'unit' => 'j', 'est_unit_cost' => '300'],
                ['part_id' => '', 'description' => '', 'quantity' => ''],
            ],
        ]);
    }

    private function edbStatus(int $id): string
    {
        return (string) $this->value('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $id]);
    }

    /** Ligne du rapport pour un EDB (null si absent du rapport). */
    private function reportRow(string $report, int $id, ?int $user = null): ?array
    {
        $dt = DataTableRequest::fromQuery(['length' => 200], PurchaseRepository::SORTABLE, ['x.requested_at', 'DESC']);
        $rows = $this->service($user ?? $this->logistics, PurchaseRepository::class)->datatable($dt, ['report' => $report, 'from' => date('Y-m-01'), 'to' => date('Y-m-t')])['data'];

        return array_column($rows, null, 'id')[$id] ?? null;
    }

    private function approveEdb(int $id): void
    {
        $this->approve($this->supervisor, PurchaseRequestService::ENTITY, $id);
        $this->approve($this->logistics, PurchaseRequestService::ENTITY, $id);
    }

    public function testR1FromRequestToFullExecutionThroughStockTransferAndPurchase(): void
    {
        // Stock existant : 3 à Kolwezi, 4 à Likasi.
        $this->service($this->keeperK, StockService::class)->move($this->part, $this->kolwezi, 'receipt', 3, null, null, null, ['unit_cost' => 18.0]);
        $this->service($this->keeperL, StockService::class)->move($this->part, $this->likasi, 'receipt', 4, null, null, null, ['unit_cost' => 19.0]);

        $id = $this->edb();
        $edb = $this->row('SELECT * FROM purchase_requests WHERE id = :id', ['id' => $id]);
        self::assertMatchesRegularExpression('/^EDB-\d{4}-00001$/', $edb['reference']);
        self::assertSame(['pending', 500.0], [$edb['status'], (float) $edb['estimated_amount']]);
        $lines = $this->service($this->logistics, PurchaseRepository::class)->lines($id);
        self::assertSame([[3.0, 4.0], [0.0, 0.0]], array_map(static fn (array $l) => [(float) $l['local_available'], (float) $l['other_available']], $lines), 'Recherche stock local puis autres sites');
        $partLine = (int) $lines[0]['id'];
        $serviceLine = (int) $lines[1]['id'];

        // En attente : rien n'est permis.
        foreach ([
            fn () => $this->requests($this->keeperK)->issueFromStock($id, ['line_id' => $partLine, 'quantity' => '1']),
            fn () => $this->orders($this->buyer)->addQuote($id, ['supplier_id' => $this->supplierA, 'amount' => '100']),
        ] as $action) {
            try {
                $action();
                self::fail('EDB non approuvé');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('status', $e->errors);
            }
        }

        // Visa hiérarchique puis approbation Logistique (500 < seuil Finance).
        self::assertSame('logistics', $this->approve($this->supervisor, PurchaseRequestService::ENTITY, $id)['next_step']);
        self::assertSame('pending', $this->edbStatus($id));
        self::assertSame('approved', $this->approve($this->logistics, PurchaseRequestService::ENTITY, $id)['status']);
        self::assertSame('approved', $this->edbStatus($id));
        self::assertSame('approved', $this->reportRow('open', $id)['display_status']);

        // 1. Stock local : 3 servis, imputés au besoin.
        try {
            $this->requests($this->requester)->issueFromStock($id, ['line_id' => $partLine, 'quantity' => '1']);
            self::fail('Le demandeur ne sort pas le stock');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $this->requests($this->keeperK)->issueFromStock($id, ['line_id' => $partLine, 'quantity' => '3']);
        self::assertSame('partial', $this->reportRow('open', $id)['display_status'], 'Sous-statut « exécution partielle »');

        // 2. Autre magasin : transfert de 4 depuis Likasi, avec son propre circuit.
        $transfer = $this->requests($this->keeperK)->requestTransfer($id, ['line_id' => $partLine, 'from_site_id' => $this->likasi, 'quantity' => '4']);
        try {
            $this->requests($this->keeperK)->issueFromStock($id, ['line_id' => $partLine, 'quantity' => '4']);
            self::fail('Pas plus que le reste à sourcer (3)');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('quantity', $e->errors);
        }
        $this->approve($this->logistics, StockTransferService::ENTITY, $transfer);
        $this->service($this->keeperL, StockTransferService::class)->ship($transfer, []);
        self::assertSame(8.0, $this->service($this->logistics, PurchaseRepository::class)->remaining($id), 'Le transit ne satisfait pas encore le besoin (11 demandés − 3 servis)');
        $this->service($this->keeperK, StockTransferService::class)->receive($transfer, []);

        // 3. Achat du reste : devis comparés, commande référencée à l'EDB.
        $orders = $this->orders($this->buyer);
        try {
            $orders->createOrder($id, ['lines' => [$partLine => ['quantity' => '3', 'unit_price' => '21']]]);
            self::fail('Pas de commande sans devis retenu');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('lines', $e->errors);
        }
        $cheap = $orders->addQuote($id, ['supplier_id' => $this->supplierA, 'reference' => 'DV-12', 'amount' => '343', 'delivery_days' => '5']);
        $dear = $orders->addQuote($id, ['supplier_id' => $this->supplierB, 'amount' => '390']);
        try {
            $orders->selectQuote($id, ['quote_id' => $dear]);
            self::fail('Choix d\'un devis plus cher non motivé');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('selection_reason', $e->errors);
        }
        $orders->selectQuote($id, ['quote_id' => $cheap]);
        try {
            $orders->createOrder($id, ['lines' => [$partLine => ['quantity' => '4', 'unit_price' => '21']]]);
            self::fail('Commande supérieure au reste à sourcer');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('lines', $e->errors);
        }
        $order = $orders->createOrder($id, ['expected_on' => date('Y-m-d', strtotime('+5 days')),
            'lines' => [$partLine => ['quantity' => '3', 'unit_price' => '21'], $serviceLine => ['quantity' => '1', 'unit_price' => '280']]]);
        $po = $this->row('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $order]);
        self::assertMatchesRegularExpression('/^BC-\d{4}-00001$/', $po['reference']);
        self::assertSame([$id, $this->supplierA, 343.0, 'issued'], [(int) $po['request_id'], (int) $po['supplier_id'], (float) $po['total_amount'], $po['status']]);

        // La commande seule ne clôture pas l'EDB.
        self::assertSame('approved', $this->edbStatus($id));
        $row = $this->reportRow('open', $id);
        self::assertSame(['partial', 'delivery', 343.0, 4.0], [$row['display_status'], $row['stage'], (float) $row['engaged_amount'], (float) $row['qty_remaining']]);

        // 4. Réception : séparation acheteur / réceptionnaire, bon de livraison obligatoire.
        try {
            $orders->receive($order, ['delivery_note' => 'BL-1', 'accepted' => [1 => '1']]);
            self::fail('L\'acheteur ne réceptionne pas');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $receiving = $this->orders($this->keeperK);
        $orderLines = array_column($this->db->fetchAll('SELECT id, request_line_id FROM purchase_order_lines WHERE order_id = :o', ['o' => $order]), 'id', 'request_line_id');
        try {
            $receiving->receive($order, ['accepted' => [$orderLines[$partLine] => '3']]);
            self::fail('Bon de livraison obligatoire');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('delivery_note', $e->errors);
        }
        $receiving->receive($order, ['delivery_note' => 'BL-7781', 'vehicle_plate' => '4521AB05', 'driver_name' => 'Mwamba',
            'accepted' => [$orderLines[$partLine] => '3', $orderLines[$serviceLine] => '1']]);

        // Intégralité satisfaite et justifiée → « approuvé exécuté ».
        $edb = $this->row('SELECT * FROM purchase_requests WHERE id = :id', ['id' => $id]);
        self::assertSame('executed', $edb['status']);
        self::assertNotNull($edb['executed_at']);
        self::assertSame('received', $this->value('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $order]));
        self::assertSame(['stock', 'transfer', 'purchase', 'purchase'], array_column($this->db->fetchAll('SELECT kind FROM purchase_fulfilments ORDER BY id'), 'kind'));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM purchase_fulfilments WHERE document_ref IS NULL'), 'Chaque satisfaction porte sa pièce');
        self::assertNull($this->reportRow('open', $id));
        $done = $this->reportRow('executed', $id);
        self::assertSame(['executed', 500.0, 343.0, 0.0], [$done['display_status'], (float) $done['estimated_amount'], (float) $done['engaged_amount'], (float) $done['qty_remaining']]);
        self::assertStringContainsString($po['reference'], (string) $done['orders']);
        self::assertStringContainsString('BR-', (string) $done['proofs']);

        // Stock de Kolwezi : 3 − 3 servis + 4 transférés + 3 achetés ; la prestation n'entre pas en stock.
        self::assertSame(7.0, (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $this->part, 's' => $this->kolwezi]));
        $receipt = $this->row("SELECT * FROM stock_movements WHERE type = 'receipt' AND supplier_id IS NOT NULL");
        self::assertSame([3.0, 21.0, $this->supplierA], [(float) $receipt['delta'], (float) $receipt['unit_cost'], (int) $receipt['supplier_id']]);
        $out = $this->row("SELECT * FROM stock_movements WHERE type = 'out'");
        self::assertMatchesRegularExpression('/^BS-/', (string) $out['document_ref']);
    }

    public function testR2PartialReceiptKeepsExactRemainingAndIsCarriedForward(): void
    {
        $id = $this->edb([['part_id' => $this->part, 'quantity' => '10', 'est_unit_cost' => '20']]);
        $this->approveEdb($id);
        $line = (int) $this->value('SELECT id FROM purchase_request_lines WHERE request_id = :r', ['r' => $id]);
        $orders = $this->orders($this->buyer);
        $orders->selectQuote($id, ['quote_id' => $orders->addQuote($id, ['supplier_id' => $this->supplierA, 'amount' => '200'])]);
        $order = $orders->createOrder($id, ['lines' => [$line => ['quantity' => '10', 'unit_price' => '20']]]);
        $orderLine = (int) $this->value('SELECT id FROM purchase_order_lines WHERE order_id = :o', ['o' => $order]);

        // Réception partielle : 6 acceptés, 1 refusé (motif obligatoire), 3 non livrés.
        $receiving = $this->orders($this->keeperK);
        try {
            $receiving->receive($order, ['delivery_note' => 'BL-1', 'accepted' => [$orderLine => '6'], 'rejected' => [$orderLine => '1']]);
            self::fail('Refus non motivé');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reject_reason', $e->errors);
        }
        $receiving->receive($order, ['delivery_note' => 'BL-1', 'accepted' => [$orderLine => '6'], 'rejected' => [$orderLine => '1'],
            'reject_reason' => [$orderLine => 'Emballage éventré']]);

        self::assertSame('approved', $this->edbStatus($id), 'Toujours « approuvé non exécuté »');
        self::assertSame('partially_received', $this->value('SELECT status FROM purchase_orders'));
        $row = $this->reportRow('open', $id);
        self::assertSame('partial', $row['display_status']);
        self::assertSame([10.0, 6.0, 4.0], [(float) $row['qty_requested'], (float) $row['qty_satisfied'], (float) $row['qty_remaining']], 'Quantités restantes exactes');
        self::assertSame([200.0, 200.0, 120.0, 80.0], [(float) $row['estimated_amount'], (float) $row['engaged_amount'], (float) $row['received_value'], (float) $row['remaining_amount']], 'Montants restants exacts');
        self::assertSame('delivery', $row['stage']);
        self::assertNull($this->reportRow('executed', $id));
        self::assertSame(6.0, (float) $this->value('SELECT quantity FROM part_stocks WHERE part_id = :p', ['p' => $this->part]), 'Le refusé n\'entre pas en stock');

        // Reste chez le fournisseur (§ 5.1) : 4 dus, dont l'article refusé.
        $outstanding = $this->service($this->logistics, PurchaseRepository::class)->outstanding();
        self::assertSame([[10.0, 6.0, 4.0, 80.0]], array_map(static fn (array $o) => [(float) $o['qty_ordered'], (float) $o['qty_received'], (float) $o['qty_outstanding'], (float) $o['value_outstanding']], $outstanding));

        // Synthèse mensuelle : dossier nouveau, non exécuté, reporté dans le solde de clôture.
        $summary = $this->service($this->logistics, PurchaseRepository::class)->periodSummary(date('Y-m-01'), date('Y-m-t'), []);
        self::assertSame([0, 1, 0, 1, 1], [(int) $summary['opening_count'], (int) $summary['new_count'], (int) $summary['executed_count'], (int) $summary['closing_count'], (int) $summary['partial_count']]);
        self::assertSame([200.0, 0.0], [(float) $summary['closing_value'], (float) $summary['rate_count']]);
        self::assertSame('Site KOLWEZI', $summary['breakdown']['site'][0]['label']);

        // Le mois suivant, il figure au solde d'ouverture : son solde reste ouvert.
        $next = date('Y-m', strtotime(date('Y-m-01') . ' +1 month'));
        $this->db->execute('UPDATE purchase_requests SET requested_at = requested_at - INTERVAL 40 DAY, approved_at = approved_at - INTERVAL 40 DAY');
        $carried = $this->service($this->logistics, PurchaseRepository::class)->periodSummary(date('Y-m-01'), date('Y-m-t'), []);
        self::assertSame([1, 0, 1], [(int) $carried['opening_count'], (int) $carried['new_count'], (int) $carried['closing_count']]);
        self::assertNotSame('', $next);

        // Une commande entamée ne s'annule plus ; l'EDB non plus tant qu'elle est en cours.
        try {
            $orders->cancelOrder($order, 'Erreur');
            self::fail('Commande partiellement reçue');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        try {
            $this->requests($this->requester)->cancel($id, 'Plus besoin');
            self::fail('EDB avec commande en cours');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }

        // Le solde : 4 livrés ensuite → exécuté, taux d'exécution 100 %.
        $receiving->receive($order, ['delivery_note' => 'BL-2', 'accepted' => [$orderLine => '4']]);
        self::assertSame('executed', $this->edbStatus($id));
        $final = $this->service($this->logistics, PurchaseRepository::class)->periodSummary(date('Y-m-01'), date('Y-m-t'), []);
        self::assertSame([1, 0, 100.0, 100.0], [(int) $final['executed_count'], (int) $final['closing_count'], (float) $final['rate_count'], (float) $final['rate_value']]);
    }

    public function testFinanceThresholdRejectionAndCancellation(): void
    {
        // Montant ≥ seuil : l'approbation Finance s'ajoute après la Logistique.
        $big = $this->edb([['part_id' => $this->part, 'quantity' => '400', 'est_unit_cost' => '20']]);
        $this->approve($this->supervisor, PurchaseRequestService::ENTITY, $big);
        self::assertSame('finance', $this->approve($this->logistics, PurchaseRequestService::ENTITY, $big)['next_step']);
        self::assertSame('pending', $this->edbStatus($big));
        self::assertSame('approved', $this->approve($this->finance, PurchaseRequestService::ENTITY, $big)['status']);
        self::assertSame('approved', $this->edbStatus($big));

        // Refus : annulé, motif conservé.
        $refused = $this->edb();
        $this->approve($this->supervisor, PurchaseRequestService::ENTITY, $refused, 'unfavorable', 'Stock suffisant selon moi');
        self::assertSame('pending', $this->edbStatus($refused), 'Le visa défavorable est un avis');
        $this->approve($this->logistics, PurchaseRequestService::ENTITY, $refused, 'unfavorable', 'Besoin non justifié ce mois-ci');
        $row = $this->row('SELECT * FROM purchase_requests WHERE id = :id', ['id' => $refused]);
        self::assertSame(['cancelled', 1, 'Besoin non justifié ce mois-ci'], [$row['status'], (int) $row['is_rejected'], $row['cancel_reason']]);

        // Annulation par le demandeur : motivée.
        $own = $this->edb();
        try {
            $this->requests($this->requester)->cancel($own, ' ');
            self::fail('Annulation non motivée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('cancel_reason', $e->errors);
        }
        try {
            $this->requests($this->keeperK)->cancel($own, 'Pas mon EDB');
            self::fail('Annulation par un tiers');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $this->requests($this->requester)->cancel($own, 'Besoin levé');
        self::assertSame('cancelled', $this->edbStatus($own));
        self::assertSame('cancelled', $this->value("SELECT status FROM approval_requests WHERE entity_type = 'purchase_request' AND entity_id = :e", ['e' => $own]));
        self::assertSame(3, (int) $this->value('SELECT COUNT(*) FROM purchase_requests'), 'Rien n\'est supprimé');

        $summary = $this->service($this->logistics, PurchaseRepository::class)->periodSummary(date('Y-m-01'), date('Y-m-t'), []);
        self::assertSame([3, 2, 1], [(int) $summary['new_count'], (int) $summary['cancelled_count'], (int) $summary['closing_count']]);
    }

    public function testValidationOfRequestAndPerimeter(): void
    {
        // Estimation « manquante » si une ligne n'est pas chiffrée.
        $id = $this->edb([['description' => 'Soudure du châssis', 'quantity' => '1']]);
        self::assertNull($this->value('SELECT estimated_amount FROM purchase_requests WHERE id = :id', ['id' => $id]));

        foreach ([
            [['lines' => []], 'lines'],
            [['lines' => [['part_id' => $this->part, 'quantity' => '0']]], 'lines'],
            [['lines' => [['quantity' => '2']]], 'lines'],
            [['site_id' => $this->likasi], 'site_id'],
            [['reason' => ''], 'reason'],
        ] as [$override, $field]) {
            try {
                $this->requests($this->requester)->create($override + [
                    'site_id' => $this->kolwezi, 'category' => 'parts', 'urgency' => 'normal', 'subject' => 'Test', 'reason' => 'Test',
                    'lines' => [['part_id' => $this->part, 'quantity' => '1']],
                ]);
                self::fail("Validation attendue : $field");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->errors);
            }
        }

        // Un demandeur d'un autre site ne voit pas l'EDB.
        $other = $this->createUser('requester', [$this->likasi]);
        self::assertNull($this->service($other, PurchaseRepository::class)->find($id));
        self::assertNull($this->reportRow('issued', $id, $other));
        self::assertNotNull($this->reportRow('issued', $id, $this->requester));
    }
}
