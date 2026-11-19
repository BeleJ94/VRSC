<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Lot 6 vu par HTTP : ravitaillement, prestation sur OT, rapport mensuel des coûts, validation, droits. */
final class EquipmentCostHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];
    private const AJAX = ['x-requested-with' => 'XMLHttpRequest'];

    private int $site;
    private int $truck;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->createSite('KOLWEZI');
        $this->truck = $this->createEquipment($this->site, 'CAM-01', 'available', 1000);
        $this->db->execute('INSERT INTO meter_readings (equipment_id, hour_meter, recorded_at) VALUES (:e, 1000, NOW() - INTERVAL 40 DAY)', ['e' => $this->truck]);
    }

    private function session(string $role, array $sites = [], bool $all = false): Session
    {
        return Session::memory(['auth_user_id' => $this->createUser($role, $sites, $all), '_csrf_token' => self::TOKEN]);
    }

    private function json(Session $session, string $path): array
    {
        $response = $this->http($session, 'GET', $path, [], self::AJAX);
        self::assertSame(200, $response->status, $path . ' ' . substr($response->body, 0, 300));

        return json_decode($response->body, true);
    }

    public function testRefillServiceAndMonthlyReportOverHttp(): void
    {
        $manager = $this->session('manager', [$this->site]);
        foreach (['/fuel', '/fuel/create', "/fuel/create?equipment_id={$this->truck}", '/costs', "/costs/{$this->truck}", '/costs?flag=missing'] as $path) {
            $page = $this->http($manager, 'GET', $path);
            self::assertSame(200, $page->status, $path);
            self::assertStringContainsString('</html>', $page->body, $path);
        }

        // Ravitaillement en station.
        $res = $this->http($manager, 'POST', '/fuel', self::CSRF + ['equipment_id' => $this->truck, 'refill_date' => date('Y-m-d'), 'litres' => '80',
            'source' => 'external', 'station' => 'Station <b>Total</b>', 'unit_price' => '2', 'hour_meter' => '1040', 'voucher_ref' => 'BON-1']);
        self::assertSame(302, $res->status);
        $refills = $this->json($manager, '/fuel/data?draw=1');
        self::assertSame([[80.0, 160.0, 'external', 'Station <b>Total</b>']], array_map(static fn (array $r) => [(float) $r['litres'], (float) $r['amount'], $r['source'], $r['station']], $refills['data']));
        // Compteur en recul : refusé, retour au formulaire.
        $this->http($manager, 'POST', '/fuel', self::CSRF + ['equipment_id' => $this->truck, 'litres' => '10', 'source' => 'external', 'hour_meter' => '900'], ['referer' => 'http://localhost/fuel/create']);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM fuel_refills'));

        // OT avec une prestation externe sans montant, puis une charge saisie.
        $this->http($manager, 'POST', '/work-orders', self::CSRF + ['equipment_id' => $this->truck, 'title' => 'Fuite hydraulique', 'priority' => 'normal']);
        $wo = (int) $this->value('SELECT id FROM work_orders');
        $page = $this->http($manager, 'GET', "/work-orders/$wo")->body;
        self::assertStringContainsString("/work-orders/$wo/services", $page);
        $this->http($manager, 'POST', "/work-orders/$wo/services", self::CSRF + ['description' => 'Flexible refait <i>atelier</i>']);
        $page = $this->http($manager, 'GET', "/work-orders/$wo")->body;
        self::assertStringContainsString('Flexible refait &lt;i&gt;atelier&lt;/i&gt;', $page);
        self::assertStringContainsString('manquante', $page);
        $this->http($manager, 'POST', '/costs/charges', self::CSRF + ['equipment_id' => $this->truck, 'charge_date' => date('Y-m-d'), 'label' => 'Péage', 'amount' => '15']);
        $this->http($manager, 'POST', "/costs/{$this->truck}/note", self::CSRF + ['period' => date('Y-m'), 'note' => 'Engin en renfort ce mois']);

        // Rapport mensuel : total, donnée manquante signalée, coût à l'heure.
        $report = $this->json($manager, '/costs/data?draw=1&period=' . date('Y-m'));
        $row = $report['data'][0];
        self::assertSame(['CAM-01', 160.0, 15.0, 175.0, 1, 40], [$row['code'], (float) $row['fuel'], (float) $row['other'], (float) $row['total'], (int) $row['missing_count'], (int) $row['usage_value']]);
        self::assertSame(1, $this->json($manager, '/costs/data?draw=1&flag=missing')['recordsFiltered']);
        self::assertSame(0, $this->json($manager, '/costs/data?draw=1&flag=alert')['recordsFiltered']);
        self::assertSame(0, $this->json($manager, '/costs/data?draw=1&period=2020-01')['data'][0]['total'] <=> 0.0);
        $detail = $this->http($manager, 'GET', "/costs/{$this->truck}")->body;
        foreach (['Station &lt;b&gt;Total&lt;/b&gt;', 'Péage', 'Engin en renfort ce mois', 'Flexible refait', 'RAV-', '1&#8239;000 → 1&#8239;040 h'] as $needle) {
            self::assertStringContainsString($needle, str_replace("\u{202F}", '&#8239;', $detail), $needle);
        }
        self::assertStringNotContainsString('<b>Total</b>', $detail);

        // Taux horaire dans la fiche du personnel.
        self::assertStringContainsString('name="hourly_rate"', $this->http($manager, 'GET', '/personnel/create')->body);
        $this->http($manager, 'POST', '/personnel', self::CSRF + ['site_id' => $this->site, 'first_name' => 'Jean', 'last_name' => 'Kasongo', 'job' => 'mechanic', 'hourly_rate' => '12.5', 'is_active' => '1']);
        self::assertSame(12.5, (float) $this->value('SELECT hourly_rate FROM personnel'));
    }

    public function testValidationAndRights(): void
    {
        $lastMonth = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $finance = $this->session('finance', [], true);
        $page = $this->http($finance, 'GET', "/costs?site_id={$this->site}&period=$lastMonth")->body;
        self::assertStringContainsString('/costs/close', $page);
        $manager = $this->session('manager', [$this->site]);
        self::assertStringNotContainsString('/costs/close', $this->http($manager, 'GET', "/costs?site_id={$this->site}&period=$lastMonth")->body);
        self::assertSame(403, $this->http($manager, 'POST', '/costs/close', self::CSRF + ['site_id' => $this->site, 'period' => $lastMonth])->status);

        $res = $this->http($finance, 'POST', '/costs/close', self::CSRF + ['site_id' => $this->site, 'period' => $lastMonth, 'note' => 'RAS']);
        self::assertSame(302, $res->status);
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM equipment_cost_periods'));
        $closed = $this->http($finance, 'GET', "/costs?site_id={$this->site}&period=$lastMonth")->body;
        self::assertStringContainsString('Validé par', $closed);
        self::assertStringNotContainsString('/costs/close', $closed);
        // Période validée : plus de ravitaillement daté dedans.
        $this->http($manager, 'POST', '/fuel', self::CSRF + ['equipment_id' => $this->truck, 'refill_date' => $lastMonth . '-28', 'litres' => '10', 'source' => 'external', 'hour_meter' => '1010'],
            ['referer' => 'http://localhost/fuel/create']);
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM fuel_refills'));

        // Droits : un encodeur enregistre un plein mais ne voit pas les coûts ; un lecteur consulte sans saisir ; hors périmètre : 404.
        $encoder = $this->session('encoder', [$this->site]);
        self::assertSame(200, $this->http($encoder, 'GET', '/fuel/create')->status);
        self::assertSame(403, $this->http($encoder, 'GET', '/costs')->status);
        $viewer = $this->session('viewer', [$this->site]);
        self::assertSame(200, $this->http($viewer, 'GET', '/costs')->status);
        self::assertSame(403, $this->http($viewer, 'GET', '/fuel/create')->status);
        self::assertSame(403, $this->http($viewer, 'POST', '/costs/charges', self::CSRF + ['equipment_id' => $this->truck, 'charge_date' => date('Y-m-d'), 'label' => 'x', 'amount' => '1'])->status);
        self::assertSame(404, $this->http($this->session('viewer', [$this->createSite('LIKASI')]), 'GET', "/costs/{$this->truck}")->status);
        self::assertSame(419, $this->http($manager, 'POST', '/fuel', ['equipment_id' => $this->truck])->status);
    }
}
