<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use App\Services\NavCounterService;
use Tests\DatabaseTestCase;

/** Menu de gauche : ordre des sections selon le métier, lien actif unique, compteurs « à traiter », bouton « Nouveau ». */
final class NavigationHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private int $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
    }

    private function session(int $userId): Session
    {
        return Session::memory(['auth_user_id' => $userId, '_csrf_token' => self::TOKEN]);
    }

    /** Barre latérale d'une page, pour ne pas confondre avec le contenu. */
    private function sidebar(int $userId, string $path): string
    {
        $response = $this->http($this->session($userId), 'GET', $path);
        self::assertSame(200, $response->status, $path);
        self::assertSame(1, preg_match('#<header class="appbar".*?</header>#s', $response->body, $m), $path);

        return $m[0];
    }

    /** @return list<string> titres de section dans l'ordre d'affichage */
    private function sectionsOf(string $sidebar): array
    {
        preg_match_all('#<span class="appnav__label">([^<]+)</span>#', $sidebar, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    public function testSectionsFollowTheBusinessFlowAndTheRole(): void
    {
        $admin = $this->sidebar($this->createUser('admin', [], true), '/');
        self::assertSame(['Mon travail', 'Besoins & achats', 'Stocks', 'Flotte', 'Maintenance', 'Finance', 'Rapports', 'Administration'], $this->sectionsOf($admin));
        foreach (['Mes validations', 'Mes demandes', 'Commandes &amp; réceptions', 'Fournisseurs', 'Transferts de stock', 'Équipements', 'Coûts par équipement', 'Sauvegardes'] as $label) {
            self::assertStringContainsString('>' . $label . '<', $admin, $label);
        }

        // Chaque métier commence par ce qu'il traite ; il ne voit pas les sections sans droit.
        $storekeeper = $this->sectionsOf($this->sidebar($this->createUser('storekeeper', [$this->site]), '/stock'));
        self::assertSame(['Mon travail', 'Stocks', 'Besoins & achats'], array_slice($storekeeper, 0, 3));
        $cashier = $this->sectionsOf($this->sidebar($this->createUser('cashier', [$this->site]), '/finance'));
        self::assertSame(['Mon travail', 'Finance'], array_slice($cashier, 0, 2));
        self::assertNotContains('Maintenance', $cashier);
        $technician = $this->sectionsOf($this->sidebar($this->createUser('technician', [$this->site]), '/work-orders'));
        self::assertSame(['Mon travail', 'Maintenance', 'Flotte'], array_slice($technician, 0, 3));
        self::assertNotContains('Finance', $technician);
    }

    public function testOnlyTheMostSpecificLinkIsActive(): void
    {
        $admin = $this->createUser('admin', [], true);
        foreach (['/stock/transfers' => 'Transferts de stock', '/stock' => 'État des stocks', '/edb/reports' => 'Rapports EDB', '/edb' => 'États de besoins',
            '/finance/payments' => 'Décaissements', '/references/suppliers' => 'Fournisseurs', '/costs' => 'Coûts par équipement'] as $path => $label) {
            $sidebar = $this->sidebar($admin, $path);
            preg_match_all('#aria-current="page"[^>]*>.*?<span class="nav__text">([^<]+)</span>#s', $sidebar, $m);
            self::assertSame([$label], array_map('html_entity_decode', $m[1]), $path);
        }
        // « Mes demandes » ouvre la liste filtrée sur le demandeur connecté.
        $page = $this->http($this->session($admin), 'GET', '/edb?mine=1')->body;
        self::assertMatchesRegularExpression('#<option value="' . $admin . '" selected>Mes demandes</option>#', $page);
        self::assertDoesNotMatchRegularExpression('#<option value="' . $admin . '" selected>#', $this->http($this->session($admin), 'GET', '/edb')->body);
    }

    public function testCountersShowWhatTheUserMustProcess(): void
    {
        $other = $this->createSite('LIKASI');
        $requester = $this->createUser('requester', [$this->site]);
        $storekeeper = $this->createUser('storekeeper', [$this->site]);
        $cashier = $this->createUser('cashier', [$this->site]);
        $supervisor = $this->createUser('supervisor', [$this->site]);

        // Deux états de besoins approuvés sur le site, un sur un autre site ; un transfert expédié vers le site ; un décaissement approuvé.
        $edb = "INSERT INTO purchase_requests (reference, site_id, status, requested_by, title) VALUES (:r, :s, 'approved', :u, 'Besoin')";
        foreach ([['EDB-A', $this->site], ['EDB-B', $this->site], ['EDB-C', $other]] as [$ref, $site]) {
            $this->insertLoose('purchase_requests', ['reference' => $ref, 'site_id' => $site, 'status' => 'approved', 'requested_by' => $requester]);
        }
        $this->insertLoose('stock_transfers', ['reference' => 'TRS-A', 'from_site_id' => $other, 'to_site_id' => $this->site, 'status' => 'shipped', 'requested_by' => $requester]);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-A', 'site_id' => $this->site, 'status' => 'approved', 'requested_by' => $requester, 'amount' => 10]);
        // Une validation en attente d'un avis hiérarchique.
        $this->service($requester, \App\Services\ApprovalService::class)->start('purchase_request', 'purchase_request', 999, $this->site, 100.0, 'EDB-Z', true);

        self::assertSame(['approvals' => 0, 'edb' => 2, 'stock_transfers' => 1], $this->service($storekeeper, NavCounterService::class)->counts());
        self::assertSame(['approvals' => 0, 'payments' => 1], $this->service($cashier, NavCounterService::class)->counts());
        self::assertSame(1, $this->service($supervisor, NavCounterService::class)->counts()['approvals']);
        // Le demandeur ne se voit pas proposer sa propre demande, et n'a pas de compteur sans objet pour lui.
        self::assertSame(['approvals' => 0], $this->service($requester, NavCounterService::class)->counts());

        $sidebar = $this->sidebar($storekeeper, '/stock');
        self::assertMatchesRegularExpression('#États de besoins</span><span class="nav__badge nav__badge--warn" title="2 à traiter">2</span>#', $sidebar);
        self::assertMatchesRegularExpression('#Transferts de stock</span><span class="nav__badge nav__badge--warn" title="1 à traiter">1</span>#', $sidebar);
        self::assertMatchesRegularExpression('#Mes validations</span><span class="nav__badge nav__badge--warn"[^>]*>1</span>#', $this->sidebar($supervisor, '/approvals'));
        self::assertDoesNotMatchRegularExpression('#Mes validations</span><span class="nav__badge nav__badge--warn"#', $this->sidebar($requester, '/edb'));
    }

    public function testNewButtonOffersOnlyAllowedEntries(): void
    {
        $requester = $this->sidebar($this->createUser('requester', [$this->site]), '/edb');
        self::assertStringContainsString('appbar__new', $requester);
        self::assertStringContainsString('/edb/create', $requester);
        self::assertStringContainsString('/finance/payments/create', $requester);
        self::assertStringNotContainsString('/work-orders/create', $requester);
        self::assertStringNotContainsString('/fuel/create', $requester);

        $technician = $this->sidebar($this->createUser('technician', [$this->site]), '/work-orders');
        self::assertStringContainsString('/work-orders/create', $technician);
        self::assertStringContainsString('/fuel/create', $technician);
        self::assertStringNotContainsString('/finance/payments/create', $technician);

        // Un lecteur ne saisit rien : pas de bouton.
        self::assertStringNotContainsString('appbar__new', $this->sidebar($this->createUser('viewer', [$this->site]), '/equipment'));
    }

    /** Insère une ligne en ne renseignant que l'utile : les colonnes obligatoires sans défaut reçoivent une valeur neutre. */
    private function insertLoose(string $table, array $values): void
    {
        foreach ($this->db->fetchAll("SHOW COLUMNS FROM $table") as $column) {
            $name = $column['Field'];
            if (array_key_exists($name, $values) || $column['Null'] === 'YES' || $column['Default'] !== null || $column['Extra'] !== '') {
                continue;
            }
            $type = strtolower($column['Type']);
            $values[$name] = match (true) {
                str_starts_with($type, 'enum') => trim(explode(',', substr($type, 5))[0], "'()"),
                str_contains($type, 'int'), str_contains($type, 'decimal') => 0,
                str_starts_with($type, 'date') => date('Y-m-d'),
                default => 'x',
            };
        }
        $columns = array_keys($values);
        $this->db->execute("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')', $values);
    }
}
