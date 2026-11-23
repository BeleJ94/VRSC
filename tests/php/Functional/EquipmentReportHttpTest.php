<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use Tests\DatabaseTestCase;

/** Fiche exécutive d'un engin (PDF / Excel) : données, droits sur les coûts, périmètre. */
final class EquipmentReportHttpTest extends DatabaseTestCase
{
    public function testReportGathersSectionsAndHidesCostsWithoutPermission(): void
    {
        $site = $this->createSite('KOL');
        $equipment = $this->createEquipment($site, 'ADT-01', 'available', 1200);
        $this->db->insert("INSERT INTO work_orders (reference, site_id, equipment_id, type, priority, status, title, reported_at) VALUES ('OT-T-1', :s, :e, 'corrective', 'high', 'open', 'Fuite <b>hydraulique</b>', NOW())",
            ['s' => $site, 'e' => $equipment]);

        $manager = Session::memory(['auth_user_id' => $this->createUser('manager', [$site])]);
        $res = $this->http($manager, 'GET', "/equipment/$equipment/report", [], ['accept' => 'application/json']);
        self::assertSame(200, $res->status);
        $p = json_decode($res->body, true);
        self::assertSame('ADT-01', $p['code']);
        self::assertTrue($p['show_costs']);
        self::assertSame('OT-T-1', $p['work_orders']['rows'][0][0]);
        self::assertSame('Fuite <b>hydraulique</b>', $p['work_orders']['rows'][0][1], 'Texte brut : l\'échappement est fait par le PDF / Excel');
        self::assertSame('1 ouvert(s) · 1 panne(s)', $p['kpis'][2]['hint']);
        foreach (['identity', 'cost_kinds', 'months', 'cost_lines', 'preventive', 'documents', 'history', 'readings'] as $key) {
            self::assertArrayHasKey($key, $p, $key);
        }
        self::assertStringStartsWith('fiche-engin-ADT-01-', $p['filename']);

        $tech = Session::memory(['auth_user_id' => $this->createUser('technician', [$site])]);
        $t = json_decode($this->http($tech, 'GET', "/equipment/$equipment/report", [], ['accept' => 'application/json'])->body, true);
        self::assertFalse($t['show_costs']);
        self::assertSame([], $t['months']['rows']);
        self::assertSame([], $t['cost_lines']['rows']);
        self::assertCount(3, $t['kpis'], 'Pas d\'indicateur de coût');

        $other = Session::memory(['auth_user_id' => $this->createUser('manager', [$this->createSite('LUB')])]);
        self::assertSame(404, $this->http($other, 'GET', "/equipment/$equipment/report", [], ['accept' => 'application/json'])->status);
    }
}
