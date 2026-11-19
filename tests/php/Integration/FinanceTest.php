<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DataTableRequest;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\FinanceRepository;
use App\Repositories\PurchaseRepository;
use App\Services\ApprovalWorkflow;
use App\Services\AttachmentService;
use App\Services\CashService;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseRequestService;
use Tests\DatabaseTestCase;

/**
 * Lot 5 — finance, caisse et contrôle des engagements (cahier des charges § 7).
 * Fin de la recette R1 : preuve de paiement et statut exact — le paiement seul ne clôture pas l'EDB.
 */
final class FinanceTest extends DatabaseTestCase
{
    private int $site;
    private int $requester;
    private int $supervisor;
    private int $logistics;
    private int $finance;
    private int $director;
    private int $buyer;
    private int $keeper;
    private int $cashier;
    private int $part;
    private int $supplier;
    private string $dir;
    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->requester = $this->createUser('requester', [$this->site]);
        $this->supervisor = $this->createUser('supervisor', [$this->site]);
        $this->logistics = $this->createUser('logistics', [], true);
        $this->finance = $this->createUser('finance', [], true);
        $this->director = $this->createUser('director', [], true);
        $this->buyer = $this->createUser('buyer', [], true);
        $this->keeper = $this->createUser('storekeeper', [$this->site]);
        $this->cashier = $this->createUser('cashier', [$this->site]);
        $this->part = $this->createPart('FLT-100', 0.0);
        $this->supplier = $this->db->insert("INSERT INTO suppliers (code, name) VALUES ('FRN-A', 'Katanga Diesel')");
        $this->db->execute("INSERT IGNORE INTO payment_methods (code, name) VALUES ('CAISSE', 'Espèces (caisse)'), ('VIREMENT', 'Virement bancaire')");
        $this->dir = sys_get_temp_dir() . '/vrsc-fin-' . bin2hex(random_bytes(4));
        $this->pdf = $this->dir . '-src.pdf';
        file_put_contents($this->pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        @unlink($this->pdf);
        parent::tearDown();
    }

    /** Service dont les pièces jointes vont dans un répertoire temporaire. */
    private function svc(int $user, string $class): object
    {
        $container = $this->appAs($user)->container();
        $container->get(AttachmentService::class)->useDirectory($this->dir);

        return $container->get($class);
    }

    private function payments(int $user): PaymentService
    {
        return $this->svc($user, PaymentService::class);
    }

    private function file(string $name = 'preuve.pdf'): array
    {
        return ['tmp_name' => $this->pdf, 'name' => $name, 'size' => filesize($this->pdf), 'error' => UPLOAD_ERR_OK];
    }

    private function method(string $code): int
    {
        return (int) $this->value('SELECT id FROM payment_methods WHERE code = :c', ['c' => $code]);
    }

    private function act(int $user, string $entity, int $id, string $decision = 'favorable', ?string $comment = null): array
    {
        $requestId = (int) $this->value('SELECT id FROM approval_requests WHERE entity_type = :t AND entity_id = :e ORDER BY id DESC LIMIT 1', ['t' => $entity, 'e' => $id]);

        return $this->service($user, ApprovalWorkflow::class)->act($requestId, ['decision' => $decision, 'comment' => $comment]);
    }

    /** Décaissement vérifié par la Finance puis approuvé par la Direction. */
    private function approvePayment(int $id): void
    {
        $this->act($this->finance, PaymentService::ENTITY, $id);
        $this->act($this->director, PaymentService::ENTITY, $id);
    }

    /** EDB approuvé de 10 filtres à 20, et sa commande de 200. @return array{0: int, 1: int, 2: int} [EDB, commande, ligne de commande] */
    private function order(): array
    {
        $edb = $this->service($this->requester, PurchaseRequestService::class)->create([
            'site_id' => $this->site, 'category' => 'parts', 'urgency' => 'normal', 'subject' => 'Filtres', 'reason' => 'Révision',
            'lines' => [['part_id' => $this->part, 'quantity' => '10', 'est_unit_cost' => '20']],
        ]);
        $this->act($this->supervisor, PurchaseRequestService::ENTITY, $edb);
        $this->act($this->logistics, PurchaseRequestService::ENTITY, $edb);
        $line = (int) $this->value('SELECT id FROM purchase_request_lines WHERE request_id = :r', ['r' => $edb]);
        $orders = $this->service($this->buyer, PurchaseOrderService::class);
        $orders->selectQuote($edb, ['quote_id' => $orders->addQuote($edb, ['supplier_id' => $this->supplier, 'amount' => '200'])]);
        $order = $orders->createOrder($edb, ['lines' => [$line => ['quantity' => '10', 'unit_price' => '20']]]);

        return [$edb, $order, (int) $this->value('SELECT id FROM purchase_order_lines WHERE order_id = :o', ['o' => $order])];
    }

    private function reconciliationRow(int $order): array
    {
        return array_column($this->service($this->finance, FinanceRepository::class)->reconciliation(), null, 'order_id')[$order];
    }

    public function testR1PaymentNeedsProofAndNeverClosesTheRequest(): void
    {
        [$edb, $order, $orderLine] = $this->order();

        // Facture : contre la commande, numéro unique par fournisseur, sans dépasser la commande.
        try {
            $this->payments($this->requester)->recordInvoice(['order_id' => $order, 'invoice_number' => 'F1', 'invoice_date' => date('Y-m-d'), 'amount' => '200']);
            self::fail('Un demandeur n\'enregistre pas de facture');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $invoice = $this->payments($this->finance)->recordInvoice(['order_id' => $order, 'invoice_number' => 'f 2026/001', 'invoice_date' => date('Y-m-d'), 'amount' => '120'], $this->file('facture.pdf'));
        self::assertSame('F2026/001', $this->value('SELECT invoice_number FROM supplier_invoices WHERE id = :id', ['id' => $invoice]));
        try {
            $this->payments($this->finance)->recordInvoice(['order_id' => $order, 'invoice_number' => 'F2026/001', 'invoice_date' => date('Y-m-d'), 'amount' => '50']);
            self::fail('Même facture saisie deux fois');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('invoice_number', $e->errors);
        }
        try {
            $this->payments($this->finance)->recordInvoice(['order_id' => $order, 'invoice_number' => 'F2026/002', 'invoice_date' => date('Y-m-d'), 'amount' => '90']);
            self::fail('Facturé au-delà de la commande');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors);
        }

        // Demande de décaissement : pas plus que le reste à payer, demandes en cours comprises.
        $payment = $this->payments($this->buyer)->request(['kind' => 'invoice', 'invoice_id' => $invoice, 'amount' => '120', 'reason' => 'Règlement facture']);
        $row = $this->row('SELECT * FROM payment_requests WHERE id = :id', ['id' => $payment]);
        self::assertMatchesRegularExpression('/^DD-\d{4}-00001$/', $row['reference']);
        self::assertSame([$edb, $order, $this->supplier, 'parts', 'pending'], [(int) $row['request_id'], (int) $row['order_id'], (int) $row['supplier_id'], $row['category'], $row['status']]);
        try {
            $this->payments($this->buyer)->request(['kind' => 'invoice', 'invoice_id' => $invoice, 'amount' => '1', 'reason' => 'Doublon']);
            self::fail('Seconde demande pour une facture déjà couverte');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors);
        }

        // Pas de paiement sans approbation ; vérification Finance puis approbation Direction.
        try {
            $this->payments($this->cashier)->pay($payment, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-77'], $this->file());
            self::fail('Paiement d\'une demande non approuvée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors);
        }
        try {
            $this->act($this->director, PaymentService::ENTITY, $payment);
            self::fail('L\'approbation ne précède pas la vérification');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertSame('approval', $this->act($this->finance, PaymentService::ENTITY, $payment)['next_step']);
        self::assertSame('approved', $this->act($this->director, PaymentService::ENTITY, $payment)['status']);
        self::assertSame('approved', $this->value('SELECT status FROM payment_requests WHERE id = :id', ['id' => $payment]));

        // Paiement : par un caissier, avec référence et preuve.
        try {
            $this->payments($this->finance)->pay($payment, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-77'], $this->file());
            self::fail('La Finance vérifie mais ne paie pas');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        try {
            $this->payments($this->cashier)->pay($payment, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-77'], null);
            self::fail('Paiement sans preuve');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('attachment', $e->errors);
        }
        try {
            $this->payments($this->cashier)->pay($payment, ['payment_method_id' => $this->method('VIREMENT')], $this->file());
            self::fail('Virement sans référence');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('payment_reference', $e->errors);
        }
        $this->payments($this->cashier)->pay($payment, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-77'], $this->file('ordre-virement.pdf'));
        $row = $this->row('SELECT * FROM payment_requests WHERE id = :id', ['id' => $payment]);
        self::assertSame(['paid', $this->cashier, 'VIR-77'], [$row['status'], (int) $row['paid_by'], $row['payment_reference']]);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM attachments WHERE entity_type = 'payment_request' AND entity_id = :id AND category = 'payment_proof'", ['id' => $payment]));

        // R1 : le paiement seul ne clôture pas l'EDB ; il est signalé « payé non reçu ».
        self::assertSame('approved', $this->value('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $edb]));
        $recon = $this->reconciliationRow($order);
        self::assertSame([200.0, 0.0, 120.0, 120.0], [(float) $recon['ordered'], (float) $recon['received'], (float) $recon['invoiced'], (float) $recon['paid']]);
        self::assertContains('paid_not_received', $recon['flags']);
        self::assertFalse($recon['matched']);
        $dt = DataTableRequest::fromQuery(['length' => 50], PurchaseRepository::SORTABLE, ['x.requested_at', 'DESC']);
        $report = $this->service($this->finance, PurchaseRepository::class)->datatable($dt, ['report' => 'open', 'from' => date('Y-m-01'), 'to' => date('Y-m-t')])['data'][0];
        self::assertSame(['approved', 120.0], [$report['display_status'], (float) $report['paid_amount']], 'Décaissé visible, statut inchangé');

        // Double paiement : même référence de virement refusée ; solde de la commande facturé puis réglé.
        $second = $this->payments($this->finance)->recordInvoice(['order_id' => $order, 'invoice_number' => 'F2026/002', 'invoice_date' => date('Y-m-d'), 'amount' => '80']);
        $payment2 = $this->payments($this->buyer)->request(['kind' => 'invoice', 'invoice_id' => $second, 'amount' => '80', 'reason' => 'Solde']);
        $this->approvePayment($payment2);
        try {
            $this->payments($this->cashier)->pay($payment2, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-77'], $this->file());
            self::fail('Référence de virement déjà utilisée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('payment_reference', $e->errors);
        }
        $this->payments($this->cashier)->pay($payment2, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-78'], $this->file());

        // Tout est payé, rien n'est reçu : l'EDB reste ouvert. La réception seule l'exécute.
        self::assertSame('approved', $this->value('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $edb]));
        $this->service($this->keeper, PurchaseOrderService::class)->receive($order, ['delivery_note' => 'BL-1', 'accepted' => [$orderLine => '10']]);
        self::assertSame('executed', $this->value('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $edb]));
        $recon = $this->reconciliationRow($order);
        self::assertTrue($recon['matched'], 'Commandé = reçu = facturé = payé');
        self::assertSame([], $this->service($this->finance, FinanceRepository::class)->reconciliation(null, true));

        // Une facture réglée ne s'annule plus.
        $this->expectException(ValidationException::class);
        $this->payments($this->finance)->cancelInvoice($invoice, 'Erreur');
    }

    public function testPayerIsNeitherRequesterNorApprover(): void
    {
        // La Direction cumule ici la fonction de caissier : elle approuve, donc ne paie pas cette demande.
        $this->db->execute("INSERT INTO user_roles (user_id, role, site_id) VALUES (:u, 'cashier', NULL)", ['u' => $this->director]);
        $this->db->execute("INSERT INTO user_roles (user_id, role, site_id) VALUES (:u, 'cashier', :s)", ['u' => $this->requester, 's' => $this->site]);
        $id = $this->payments($this->requester)->request(['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'Hôtel Kolwezi', 'amount' => '90', 'reason' => 'Mission', 'category' => 'other']);
        try {
            $this->act($this->finance, PaymentService::ENTITY, $id, 'unfavorable');
            self::fail('Un refus doit être motivé');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('comment', $e->errors);
        }
        $this->approvePayment($id);
        foreach ([$this->director, $this->requester] as $actor) {
            try {
                $this->payments($actor)->pay($id, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-1'], $this->file());
                self::fail('Demandeur ou approbateur payeur');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('status', $e->errors);
            }
        }
        $this->payments($this->cashier)->pay($id, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-1'], $this->file());
        self::assertSame('paid', $this->value('SELECT status FROM payment_requests WHERE id = :id', ['id' => $id]));

        // Refus à la vérification : rien à payer.
        $refused = $this->payments($this->requester)->request(['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'X', 'amount' => '10', 'reason' => 'Sans pièce']);
        $this->act($this->finance, PaymentService::ENTITY, $refused, 'unfavorable', 'Pièces manquantes');
        self::assertSame('rejected', $this->value('SELECT status FROM payment_requests WHERE id = :id', ['id' => $refused]));
        $this->expectException(ValidationException::class);
        $this->payments($this->cashier)->pay($refused, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'VIR-2'], $this->file());
    }

    public function testCashJournalBalancesClosingAndLock(): void
    {
        $cash = fn (int $user): CashService => $this->svc($user, CashService::class);
        try {
            $cash($this->requester)->createRegister(['site_id' => $this->site, 'name' => 'Caisse']);
            self::fail('Un demandeur n\'ouvre pas de caisse');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('site_id', $e->errors);
        }
        $register = $cash($this->cashier)->createRegister(['site_id' => $this->site, 'name' => 'Caisse principale']);
        $cash($this->cashier)->manualEntry($register, ['direction' => 'in', 'amount' => '500', 'label' => 'Approvisionnement siège']);

        // Paiement en espèces : la caisse doit avoir les fonds.
        $big = $this->payments($this->requester)->request(['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'Garage', 'amount' => '1000', 'reason' => 'Trop cher', 'category' => 'maintenance']);
        $this->approvePayment($big);
        try {
            $this->payments($this->cashier)->pay($big, ['payment_method_id' => $this->method('CAISSE'), 'cash_register_id' => $register], $this->file());
            self::fail('Fonds insuffisants');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors);
        }
        self::assertSame('approved', $this->value('SELECT status FROM payment_requests WHERE id = :id', ['id' => $big]));
        try {
            $this->payments($this->cashier)->pay($big, ['payment_method_id' => $this->method('CAISSE')], $this->file());
            self::fail('Espèces sans caisse');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('cash_register_id', $e->errors);
        }

        $small = $this->payments($this->requester)->request(['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'Quincaillerie', 'amount' => '120', 'reason' => 'Boulons', 'category' => 'consumables']);
        $this->approvePayment($small);
        $this->payments($this->cashier)->pay($small, ['payment_method_id' => $this->method('CAISSE'), 'cash_register_id' => $register], $this->file());
        $paid = $this->row('SELECT * FROM payment_requests WHERE id = :id', ['id' => $small]);
        self::assertMatchesRegularExpression('/^PC-\d{4}-00002$/', (string) $paid['payment_reference'], 'La pièce de caisse sert de référence');
        self::assertSame(380.0, (float) $this->value('SELECT balance FROM cash_registers WHERE id = :id', ['id' => $register]));

        // Journal du jour : ouverture, entrées, sorties, clôture.
        $journal = $this->service($this->cashier, FinanceRepository::class)->journal($register, date('Y-m-d'));
        self::assertSame([0.0, 500.0, 120.0, 380.0], [$journal['opening'], $journal['total_in'], $journal['total_out'], $journal['closing']]);
        self::assertSame(['in', 'out'], array_column($journal['entries'], 'direction'));
        self::assertSame($small, (int) $journal['entries'][1]['payment_request_id']);

        // Arrêté : pas dans le futur, écart à expliquer, puis journée verrouillée.
        try {
            $cash($this->cashier)->close($register, ['closing_date' => date('Y-m-d', strtotime('+1 day')), 'counted_balance' => '380']);
            self::fail('Arrêté d\'un jour futur');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('closing_date', $e->errors);
        }
        try {
            $cash($this->cashier)->close($register, ['closing_date' => date('Y-m-d'), 'counted_balance' => '370']);
            self::fail('Écart de caisse non expliqué');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('gap_note', $e->errors);
        }
        $cash($this->cashier)->close($register, ['closing_date' => date('Y-m-d'), 'counted_balance' => '370', 'gap_note' => 'Erreur de rendu de monnaie']);
        $closing = $this->row('SELECT * FROM cash_closings WHERE register_id = :r', ['r' => $register]);
        self::assertSame([0.0, 500.0, 120.0, 380.0, 370.0, -10.0], array_map('floatval', [$closing['opening_balance'], $closing['total_in'], $closing['total_out'], $closing['closing_balance'], $closing['counted_balance'], $closing['gap']]));
        try {
            $cash($this->cashier)->manualEntry($register, ['direction' => 'in', 'amount' => '10', 'label' => 'Après arrêté']);
            self::fail('Écriture dans une journée arrêtée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors);
        }
        try {
            $cash($this->cashier)->close($register, ['closing_date' => date('Y-m-d'), 'counted_balance' => '380']);
            self::fail('Double arrêté');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('closing_date', $e->errors);
        }
        self::assertSame(2, (int) $this->value('SELECT COUNT(*) FROM cash_entries'), 'Aucune écriture modifiée ni supprimée');
    }

    public function testCashDaysCloseInOrder(): void
    {
        $cash = $this->svc($this->cashier, CashService::class);
        $register = $cash->createRegister(['site_id' => $this->site, 'name' => 'Caisse']);
        $cash->manualEntry($register, ['direction' => 'in', 'amount' => '100', 'label' => 'Appro']);
        $this->db->execute('UPDATE cash_entries SET entry_date = CURDATE() - INTERVAL 2 DAY');
        $cash->manualEntry($register, ['direction' => 'out', 'amount' => '30', 'label' => 'Remise en banque']);
        try {
            $cash->close($register, ['closing_date' => date('Y-m-d'), 'counted_balance' => '70']);
            self::fail('Jour antérieur non arrêté');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('closing_date', $e->errors);
        }
        $cash->close($register, ['closing_date' => date('Y-m-d', strtotime('-2 days')), 'counted_balance' => '100']);
        $cash->close($register, ['closing_date' => date('Y-m-d'), 'counted_balance' => '70']);
        $today = $this->service($this->cashier, FinanceRepository::class)->journal($register, date('Y-m-d'));
        self::assertSame([100.0, 0.0, 30.0, 70.0], [$today['opening'], $today['total_in'], $today['total_out'], $today['closing']], 'Le solde d\'ouverture reprend la clôture précédente');
        self::assertNotNull($today['closed']);
    }

    public function testAdvanceMustBeJustifiedAndTheBalanceReturned(): void
    {
        [$edb] = $this->order();
        $register = $this->svc($this->cashier, CashService::class)->createRegister(['site_id' => $this->site, 'name' => 'Caisse']);
        $this->svc($this->cashier, CashService::class)->manualEntry($register, ['direction' => 'in', 'amount' => '1000', 'label' => 'Appro']);

        // Une avance se rattache à un EDB approuvé.
        $pending = $this->service($this->requester, PurchaseRequestService::class)->create(['site_id' => $this->site, 'category' => 'parts', 'urgency' => 'normal',
            'subject' => 'Autre', 'reason' => 'x', 'lines' => [['part_id' => $this->part, 'quantity' => '1']]]);
        try {
            $this->payments($this->requester)->request(['kind' => 'advance', 'request_id' => $pending, 'beneficiary' => 'Kabongo', 'amount' => '300', 'reason' => 'Achat local']);
            self::fail('Avance sur un EDB non approuvé');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('request_id', $e->errors);
        }
        $id = $this->payments($this->requester)->request(['kind' => 'advance', 'request_id' => $edb, 'beneficiary' => 'Kabongo', 'amount' => '300', 'reason' => 'Achat local urgent']);
        $this->approvePayment($id);
        $this->payments($this->cashier)->pay($id, ['payment_method_id' => $this->method('CAISSE'), 'cash_register_id' => $register], $this->file());
        $finance = fn (): FinanceRepository => $this->service($this->finance, FinanceRepository::class);
        self::assertSame(300.0, (float) $finance()->dashboard()['advances_to_justify']);
        self::assertCount(1, $finance()->payments(['to_justify' => true]));

        try {
            $this->payments($this->cashier)->justify($id, ['justified_amount' => '250'], null);
            self::fail('Justification sans pièce');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('attachment', $e->errors);
        }
        try {
            $this->payments($this->cashier)->justify($id, ['justified_amount' => '280', 'returned_amount' => '50', 'cash_register_id' => $register], $this->file());
            self::fail('Plus que l\'avance');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('justified_amount', $e->errors);
        }
        $this->payments($this->cashier)->justify($id, ['justified_amount' => '250'], $this->file('recus.pdf'));
        self::assertSame(50.0, (float) $finance()->dashboard()['advances_to_justify']);
        self::assertNull($this->value('SELECT justified_at FROM payment_requests WHERE id = :id', ['id' => $id]));

        // Reliquat rendu à la caisse : l'avance est soldée.
        $this->payments($this->cashier)->justify($id, ['returned_amount' => '50', 'cash_register_id' => $register], null);
        $row = $this->row('SELECT * FROM payment_requests WHERE id = :id', ['id' => $id]);
        self::assertSame([250.0, 50.0], [(float) $row['justified_amount'], (float) $row['returned_amount']]);
        self::assertNotNull($row['justified_at']);
        self::assertSame(750.0, (float) $this->value('SELECT balance FROM cash_registers WHERE id = :id', ['id' => $register]), '1000 − 300 + 50');
        self::assertSame(0.0, (float) $finance()->dashboard()['advances_to_justify']);
        self::assertSame('approved', $this->value('SELECT status FROM purchase_requests WHERE id = :id', ['id' => $edb]), 'Une avance n\'exécute pas l\'EDB');
    }

    public function testSpendReportDistinguishesRequestedApprovedEngagedPaid(): void
    {
        [, $order] = $this->order(); // engagement de 200 (pièces)
        $invoice = $this->payments($this->finance)->recordInvoice(['order_id' => $order, 'invoice_number' => 'F1', 'invoice_date' => date('Y-m-d'), 'amount' => '200']);
        $paid = $this->payments($this->buyer)->request(['kind' => 'invoice', 'invoice_id' => $invoice, 'amount' => '150', 'reason' => 'Acompte']);
        $this->approvePayment($paid);
        $this->payments($this->cashier)->pay($paid, ['payment_method_id' => $this->method('VIREMENT'), 'payment_reference' => 'V1'], $this->file());
        $approved = $this->payments($this->requester)->request(['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'Station', 'amount' => '60', 'reason' => 'Gasoil', 'category' => 'fuel']);
        $this->approvePayment($approved);
        $this->payments($this->requester)->request(['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'Divers', 'amount' => '40', 'reason' => 'En attente', 'category' => 'fuel']);
        $cancelled = $this->payments($this->requester)->request(['kind' => 'expense', 'site_id' => $this->site, 'beneficiary' => 'Annulé', 'amount' => '999', 'reason' => 'Erreur', 'category' => 'fuel']);
        $this->payments($this->requester)->cancel($cancelled, 'Saisie en double');

        $repo = $this->service($this->finance, FinanceRepository::class);
        [$from, $to] = [date('Y-m-01'), date('Y-m-t')];
        $totals = $repo->spendTotals($from, $to);
        // demandé 150 + 60 + 40 ; approuvé 150 + 60 ; engagé 200 (commande) + 60 (dépense directe) ; payé 150 ; restant 110.
        self::assertSame([250.0, 210.0, 260.0, 150.0, 110.0], array_map('floatval', [$totals['requested'], $totals['approved'], $totals['engaged'], $totals['paid'], $totals['remaining']]));

        $dt = DataTableRequest::fromQuery(['length' => 50], FinanceRepository::SORTABLE, ['x.label', 'ASC']);
        $byCategory = array_column($repo->spend($dt, 'category', $from, $to)['data'], null, 'label');
        self::assertSame([150.0, 200.0, 150.0], [(float) $byCategory['parts']['requested'], (float) $byCategory['parts']['engaged'], (float) $byCategory['parts']['paid']]);
        self::assertSame([100.0, 60.0, 60.0, 0.0], [(float) $byCategory['fuel']['requested'], (float) $byCategory['fuel']['approved'], (float) $byCategory['fuel']['engaged'], (float) $byCategory['fuel']['paid']]);
        self::assertSame(['Site KOLWEZI'], array_column($repo->spend($dt, 'site', $from, $to)['data'], 'label'));
        self::assertSame(0, $repo->spend($dt, 'site', '2020-01-01', '2020-01-31')['recordsTotal']);

        $dashboard = $repo->dashboard();
        self::assertSame([1, 40.0, 1, 60.0, 50.0, 50.0], [(int) $dashboard['pending_count'], (float) $dashboard['pending_amount'], (int) $dashboard['to_pay_count'],
            (float) $dashboard['to_pay_amount'], (float) $dashboard['unpaid_invoices'], (float) $dashboard['engaged_unpaid']]);

        // Périmètre : un caissier d'un autre site ne voit rien de Kolwezi.
        $other = $this->createUser('cashier', [$this->createSite('LIKASI')]);
        self::assertSame([], $this->service($other, FinanceRepository::class)->payments());
        self::assertSame(0.0, (float) $this->service($other, FinanceRepository::class)->spendTotals($from, $to)['paid']);
    }
}
