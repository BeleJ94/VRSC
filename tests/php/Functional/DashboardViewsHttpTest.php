<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use App\Repositories\ExecutiveDashboardRepository;
use Tests\DatabaseTestCase;

/** Tableaux de bord par profil : vues et onglets selon les droits, indicateurs dans le périmètre, périodes, bandeau « À traiter ». */
final class DashboardViewsHttpTest extends DatabaseTestCase
{
    private int $site;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->other = $this->createSite('LIKASI');
    }

    private function page(string $role, array $sites, bool $all, string $path = '/'): string
    {
        $response = $this->http(Session::memory(['auth_user_id' => $this->createUser($role, $sites, $all)]), 'GET', $path);
        self::assertSame(200, $response->status, "$role $path");

        return $response->body;
    }

    /** @return list<string> onglets affichés */
    private static function tabs(string $html): array
    {
        preg_match_all('#<nav class="xdash-tabs".*?</nav>#s', $html, $nav);
        if ($nav[0] === []) {
            return [];
        }
        preg_match_all('#view=([a-z]+)#', $nav[0][0], $m);

        return array_values(array_unique($m[1]));
    }

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
                default => 'x' . bin2hex(random_bytes(3)),
            };
        }
        $columns = array_keys($values);
        $this->db->execute("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')', $values);
    }

    public function testDefaultViewAndTabsFollowTheProfile(): void
    {
        // Direction Logistique : vue exécutive par défaut, trois onglets.
        $logistics = $this->page('logistics', [], true);
        self::assertStringContainsString('Dépenses engagées', $logistics);
        self::assertStringContainsString('Comparaison des sites', $logistics);
        self::assertSame(['executive', 'finance', 'purchasing', 'stores', 'maintenance'], self::tabs($logistics));

        // Finance et caissier : vue finance par défaut.
        self::assertStringContainsString('Trésorerie des caisses', $this->page('finance', [], true));
        $cashier = $this->page('cashier', [$this->site], false);
        self::assertStringContainsString('Trésorerie des caisses', $cashier);
        self::assertSame(['finance', 'cash'], self::tabs($cashier));

        // Responsable maintenance : Maintenance par défaut, Magasin en plus (il mouvemente le stock de son site) ;
        // une vue non autorisée demandée est ignorée.
        $manager = $this->page('manager', [$this->site], false, '/?view=executive');
        self::assertStringNotContainsString('Dépenses engagées', $manager);
        self::assertStringContainsString('Disponibilité du parc', $manager);
        self::assertSame(['stores', 'maintenance', 'requests'], self::tabs($manager));

        // Magasinier : vue Magasin seule ; acheteur : Finance et Achats, Achats par défaut.
        $keeper = $this->page('storekeeper', [$this->site], false);
        foreach (['Valeur du stock', 'Sorties de stock', 'Cuves de gasoil', 'Réceptions attendues', 'Transferts en cours'] as $needle) {
            self::assertStringContainsString($needle, $keeper, $needle);
        }
        self::assertSame(['stores', 'requests'], self::tabs($keeper));
        $buyer = $this->page('buyer', [], true);
        foreach (['EDB émis', 'EDB à acheter', 'Commandes en cours', 'Délai EDB', 'Fournisseurs de la période'] as $needle) {
            self::assertStringContainsString($needle, $buyer, $needle);
        }
        self::assertSame(['finance', 'purchasing'], self::tabs($buyer));
        self::assertSame(['executive', 'finance', 'purchasing', 'stores', 'maintenance'], self::tabs($this->page('logistics', [], true)));

        // Profil sans vue dédiée (demandeur) : page servie, bandeau présent.
        self::assertStringContainsString('xdash-todo', $this->page('requester', [$this->site], false));

        // Onglet finance choisi explicitement, période trimestre.
        $quarter = $this->page('director', [], true, '/?view=finance&period=quarter');
        self::assertStringContainsString('Factures à payer', $quarter);
        self::assertMatchesRegularExpression('#<option value="quarter" selected>#', $quarter);
    }

    public function testFiguresStayWithinScopeAndPeriod(): void
    {
        $requester = $this->createUser('requester', [$this->site]);
        $now = date('Y-m-d H:i:s');
        // Payé ce mois-ci : 1 200 sur Kolwezi, 800 sur Likasi ; 500 payés l'an dernier ne comptent pas dans le mois.
        $this->insertLoose('payment_requests', ['reference' => 'DDP-1', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'paid', 'amount' => 1200, 'paid_at' => $now, 'approved_at' => $now, 'requested_by' => $requester]);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-2', 'site_id' => $this->other, 'kind' => 'expense', 'status' => 'paid', 'amount' => 800, 'paid_at' => $now, 'approved_at' => $now, 'requested_by' => $requester]);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-3', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'paid', 'amount' => 500,
            'paid_at' => date('Y-m-d H:i:s', strtotime('-13 months')), 'approved_at' => date('Y-m-d H:i:s', strtotime('-13 months')), 'requested_by' => $requester]);
        // Décaissement approuvé à payer.
        $this->insertLoose('payment_requests', ['reference' => 'DDP-4', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'approved', 'amount' => 75, 'approved_at' => $now, 'requested_by' => $requester]);

        $all = $this->page('director', [], true, '/?view=executive');
        self::assertStringContainsString('2' . "\u{202F}" . '000,00', str_replace(' ', "\u{202F}", $all), 'Payé du mois, tous sites');
        $kolwezi = $this->page('finance', [$this->site], false, '/?view=finance');
        $normalized = str_replace(' ', "\u{202F}", $kolwezi);
        self::assertStringContainsString('1' . "\u{202F}" . '200,00', $normalized, 'Payé du mois, Kolwezi seul');
        self::assertStringNotContainsString('2' . "\u{202F}" . '000,00', $normalized, 'Likasi hors périmètre');
        self::assertStringContainsString('1 à payer', $kolwezi);
        // Sur l'année glissante précédente, le paiement de l'an dernier n'est pas dans l'année en cours.
        self::assertStringNotContainsString('1' . "\u{202F}" . '700,00', str_replace(' ', "\u{202F}", $this->page('finance', [$this->site], false, '/?view=finance&period=year')));
    }

    public function testTodoBandListsWhatMustBeDone(): void
    {
        $cashierId = $this->createUser('cashier', [$this->site]);
        $requester = $this->createUser('requester', [$this->site]);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-9', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'approved', 'amount' => 40, 'approved_at' => date('Y-m-d H:i:s'), 'requested_by' => $requester]);
        $html = $this->http(Session::memory(['auth_user_id' => $cashierId]), 'GET', '/')->body;
        preg_match('#<section class="xdash-todo".*?</section>#s', $html, $band);
        self::assertStringContainsString('décaissement(s) à payer', $band[0]);
        self::assertStringContainsString('clôture(s) du mois en retard', $band[0], 'Mois précédent non clôturé');
        self::assertStringContainsString('/finance/payments', $band[0]);

        // Clôtures faites : l'entrée disparaît.
        $period = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $this->db->execute('INSERT INTO stock_periods (site_id, period) VALUES (:s, :p)', ['s' => $this->site, 'p' => $period]);
        $this->db->execute('INSERT INTO equipment_cost_periods (site_id, period) VALUES (:s, :p)', ['s' => $this->site, 'p' => $period]);
        $html = $this->http(Session::memory(['auth_user_id' => $cashierId]), 'GET', '/')->body;
        self::assertStringNotContainsString('clôture(s) du mois en retard', $html);
    }

    public function testCashAndRequestsViews(): void
    {
        $requester = $this->createUser('requester', [$this->site]);
        $other = $this->createUser('requester', [$this->site]);
        $now = date('Y-m-d H:i:s');
        $register = $this->db->insert("INSERT INTO cash_registers (site_id, name, balance) VALUES (:s, 'Caisse <b>K</b>', 450)", ['s' => $this->site]);
        $this->db->execute("INSERT INTO cash_entries (register_id, entry_date, reference, direction, amount, balance_after, label) VALUES (:r, CURDATE(), 'C-1', 'out', 50, 450, 'Mission')", ['r' => $register]);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-A', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'approved', 'amount' => 80, 'approved_at' => $now, 'requested_by' => $requester, 'beneficiary' => 'Hôtel']);
        $this->insertLoose('payment_requests', ['reference' => 'DDP-B', 'site_id' => $this->site, 'kind' => 'expense', 'status' => 'paid', 'amount' => 30, 'paid_at' => $now, 'requested_by' => $other]);

        // Caissier : vue Caisse par défaut ; caisse, sorties du jour, décaissement à payer.
        $cashier = $this->http(\App\Core\Session::memory(['auth_user_id' => $this->createUser('cashier', [$this->site])]), 'GET', '/')->body;
        foreach (['Trésorerie des caisses', 'Sorties de caisse du jour', 'Caisse &lt;b&gt;K&lt;/b&gt;', 'DDP-A', 'Décaissements approuvés à payer'] as $needle) {
            self::assertStringContainsString($needle, $cashier, $needle);
        }
        self::assertStringNotContainsString('<b>K</b>', $cashier);

        // Demandeur : uniquement ses EDB et ses décaissements, avec le suivi par étapes.
        $this->insertLoose('purchase_requests', ['reference' => 'EDB-MOI', 'site_id' => $this->site, 'status' => 'approved', 'requested_by' => $requester, 'subject' => 'Filtres', 'requested_at' => $now, 'approved_at' => $now]);
        $this->insertLoose('purchase_requests', ['reference' => 'EDB-AUTRE', 'site_id' => $this->site, 'status' => 'pending', 'requested_by' => $other, 'subject' => 'Autre', 'requested_at' => $now]);
        $mine = $this->http(\App\Core\Session::memory(['auth_user_id' => $requester]), 'GET', '/')->body;
        foreach (['Suivi de mes demandes', 'EDB-MOI', 'xsteps', 'Mes décaissements', 'DDP-A'] as $needle) {
            self::assertStringContainsString($needle, $mine, $needle);
        }
        self::assertStringNotContainsString('EDB-AUTRE', $mine);
        self::assertStringNotContainsString('DDP-B', $mine);
        self::assertSame([], self::tabs($mine), 'Le demandeur n\'a que sa vue');
        // « Mes décaissements » : la liste de détail se limite au compte connecté.
        $json = json_decode($this->http(\App\Core\Session::memory(['auth_user_id' => $requester]), 'GET', '/dashboard/drill/my-payments?draw=1', [], ['x-requested-with' => 'XMLHttpRequest'])->body, true);
        self::assertSame(['DDP-A'], array_column($json['data'], 'reference'));
        // Superviseur : Mes demandes par défaut.
        self::assertStringContainsString('Suivi de mes demandes', $this->page('supervisor', [$this->site], false));
    }

    public function testPeriodBounds(): void
    {
        $month = ExecutiveDashboardRepository::period('month', '2026-03-31');
        self::assertSame(['2026-03-01', '2026-03-31', '2026-02-01', '2026-02-28'], [$month['from'], $month['to'], $month['prev_from'], $month['prev_to']]);
        $last = ExecutiveDashboardRepository::period('last_month', '2026-01-15');
        self::assertSame(['2025-12-01', '2025-12-31', '2025-11-01', '2025-11-30'], [$last['from'], $last['to'], $last['prev_from'], $last['prev_to']]);
        $quarter = ExecutiveDashboardRepository::period('quarter', '2026-11-20');
        self::assertSame(['2026-10-01', '2026-11-20', '2026-07-01'], [$quarter['from'], $quarter['to'], $quarter['prev_from']]);
        self::assertSame(['2026-10', '2026-11'], $quarter['months']);
        $year = ExecutiveDashboardRepository::period('year', '2026-11-20');
        self::assertSame(['2026-01-01', '2026-11-20', '2025-01-01', '2025-11-20'], [$year['from'], $year['to'], $year['prev_from'], $year['prev_to']]);
        self::assertSame('month', ExecutiveDashboardRepository::period('nimporte', '2026-11-20')['key']);
        self::assertSame(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], ExecutiveDashboardRepository::trendMonths('2026-11-20'));
    }
}
