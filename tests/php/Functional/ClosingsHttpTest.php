<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Étape 3 du menu : page « Clôtures du mois » et onglets des compteurs. */
final class ClosingsHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];

    private int $site;
    private string $lastMonth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->lastMonth = date('Y-m', (int) strtotime(date('Y-m-01') . ' -1 month'));
    }

    private function session(string $role, array $sites = [], bool $all = false): Session
    {
        return Session::memory(['auth_user_id' => $this->createUser($role, $sites, $all), '_csrf_token' => self::TOKEN]);
    }

    public function testClosingsPageGathersStockCostsAndCash(): void
    {
        $finance = $this->session('finance', [], true);
        $register = $this->db->insert("INSERT INTO cash_registers (site_id, name) VALUES (:s, 'Caisse <b>principale</b>')", ['s' => $this->site]);
        $day = $this->lastMonth . '-10';
        $this->db->execute("INSERT INTO cash_entries (register_id, entry_date, reference, direction, amount, balance_after, label) VALUES (:r, :d, 'CAI-1', 'in', 100, 100, 'Approvisionnement')",
            ['r' => $register, 'd' => $day]);

        $page = $this->http($finance, 'GET', '/closings')->body;
        foreach (['Clôtures du mois — ' . $this->lastMonth, 'KOLWEZI', 'Clôturer le stock', 'Valider les coûts', 'Caisse &lt;b&gt;principale&lt;/b&gt;', '1 journée(s) à arrêter', '0 / 2'] as $needle) {
            self::assertStringContainsString($needle, $page, $needle);
        }
        self::assertStringNotContainsString('<b>principale</b>', $page);
        self::assertStringContainsString('register_id=' . $register . '&amp;date=' . $day, $page);
        // Lien actif dans le menu.
        self::assertMatchesRegularExpression('#aria-current="page"[^>]*><i data-lucide="lock"></i><span class="nav__text">Clôtures du mois#', $page);

        // Clôture du stock depuis la page : retour sur la page, case passée « Clôturé ».
        $res = $this->http($finance, 'POST', '/stock/periods', self::CSRF + ['site_id' => $this->site, 'period' => $this->lastMonth, 'return' => 'closings']);
        self::assertSame(302, $res->status);
        self::assertStringContainsString('/closings?period=' . $this->lastMonth, $res->headers['Location']);
        $res = $this->http($finance, 'POST', '/costs/close', self::CSRF + ['site_id' => $this->site, 'period' => $this->lastMonth, 'return' => 'closings']);
        self::assertStringContainsString('/closings?period=' . $this->lastMonth, $res->headers['Location']);
        $page = $this->http($finance, 'GET', '/closings?period=' . $this->lastMonth)->body;
        self::assertStringContainsString('2 / 2', $page);
        self::assertStringNotContainsString('Clôturer le stock', $page);
        // Sans « return », chaque écran garde son propre retour.
        $other = $this->createSite('LIKASI');
        $res = $this->http($finance, 'POST', '/stock/periods', self::CSRF + ['site_id' => $other, 'period' => $this->lastMonth]);
        self::assertStringContainsString('/stock/periods', $res->headers['Location']);

        // Mois en cours : rien ne se clôture ; paramètre invalide : mois précédent.
        $current = $this->http($finance, 'GET', '/closings?period=' . date('Y-m'))->body;
        self::assertStringContainsString('pas terminé', $current);
        self::assertStringNotContainsString('Clôturer le stock', $current);
        self::assertStringContainsString('— ' . $this->lastMonth, $this->http($finance, 'GET', '/closings?period=2099-01')->body);
    }

    public function testBlockersAndRights(): void
    {
        $logistics = $this->session('logistics', [], true);
        $this->db->execute("INSERT INTO stock_inventories (reference, site_id, status, counted_on) VALUES ('INV-1', :s, 'draft', :d)", ['s' => $this->site, 'd' => $this->lastMonth . '-05']);
        $page = $this->http($logistics, 'GET', '/closings')->body;
        self::assertStringContainsString('1 inventaire(s) non validé(s)', $page);
        self::assertStringNotContainsString('Clôturer le stock', $page, 'Clôture proposée malgré un inventaire ouvert');
        self::assertStringContainsString('Valider les coûts', $page);

        // Le caissier voit la page mais ne clôture ni le stock ni les coûts.
        $cashier = $this->http($this->session('cashier', [$this->site]), 'GET', '/closings');
        self::assertSame(200, $cashier->status);
        self::assertStringNotContainsString('Valider les coûts', $cashier->body);
        foreach (['manager', 'storekeeper', 'viewer', 'requester'] as $role) {
            self::assertSame(403, $this->http($this->session($role, [$this->site]), 'GET', '/closings')->status, $role);
        }
        // Hors périmètre : le site n'apparaît pas.
        self::assertStringNotContainsString('KOLWEZI', $this->http($this->session('finance', [$this->createSite('LIKASI')]), 'GET', '/closings')->body);
    }

    public function testMeterScreensAreTabsOfASingleMenuEntry(): void
    {
        $admin = $this->session('admin', [], true);
        $meters = $this->http($admin, 'GET', '/meters')->body;
        foreach (['class="tabs"', '/saisie', '/readings', '/meters/screen'] as $needle) {
            self::assertStringContainsString($needle, $meters, $needle);
        }
        preg_match('#<aside class="sidebar".*?</aside>#s', $meters, $m);
        self::assertStringNotContainsString('/meters/screen', $m[0], 'Écran mural retiré du menu');
        self::assertStringNotContainsString('>Répertoire des index<', $m[0], 'Répertoire retiré de l\'administration');
        self::assertStringNotContainsString('>Saisie des index<', $m[0]);
        // Le répertoire allume le lien « Compteurs ».
        $readings = $this->http($admin, 'GET', '/readings')->body;
        self::assertMatchesRegularExpression('#aria-current="page"[^>]*><i data-lucide="gauge"></i><span class="nav__text">Compteurs#', $readings);
        self::assertStringContainsString('class="tabs"', $readings);

        // Un technicien n'a pas l'onglet du répertoire (administration).
        preg_match('#<nav class="tabs".*?</nav>#s', $this->http($this->session('technician', [$this->site]), 'GET', '/meters')->body, $tabs);
        self::assertStringContainsString('/meters/screen', $tabs[0]);
        self::assertStringNotContainsString('/readings', $tabs[0]);
        // L'encodeur, sans supervision, garde son lien direct vers la saisie.
        $encoder = $this->http($this->session('encoder', [$this->site]), 'GET', '/fuel/create')->body;
        preg_match('#<aside class="sidebar".*?</aside>#s', $encoder, $m);
        self::assertStringContainsString('>Saisie des index<', $m[0]);
    }
}
