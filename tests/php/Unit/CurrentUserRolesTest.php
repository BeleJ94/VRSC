<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Role;
use App\Security\CurrentUser;
use PHPUnit\Framework\TestCase;

/** Habilitations par rôle ET par site (cahier des charges § 2.2). */
final class CurrentUserRolesTest extends TestCase
{
    private function user(Role $role, array $siteIds, array $extra = [], bool $allSites = false): CurrentUser
    {
        return new CurrentUser(1, 'u@test.local', 'U', 'Test', $role, 'fr', $allSites, $siteIds, $extra);
    }

    public function testPrimaryRoleOnlyBehavesAsBefore(): void
    {
        $manager = $this->user(Role::Manager, [1, 2]);
        self::assertTrue($manager->can('equipment.manage'));
        self::assertTrue($manager->canAt('equipment.manage', 2));
        self::assertFalse($manager->canAt('equipment.manage', 3));
        self::assertSame([1, 2], $manager->scope()->siteIds());
        self::assertSame([1, 2], $manager->scopeFor('equipment.manage')->siteIds());
        self::assertTrue($this->user(Role::Admin, [])->scope()->isAll());
        self::assertTrue($this->user(Role::Viewer, [], [], true)->scope()->isAll());
    }

    public function testDifferentFunctionsOnDifferentSites(): void
    {
        // Consultation sur le site 1, magasinier sur le site 2.
        $user = $this->user(Role::Viewer, [1], [['role' => Role::Storekeeper, 'site_id' => 2, 'delegated_from' => null]]);

        self::assertSame([1, 2], $user->scope()->siteIds(), 'Voit les deux sites');
        self::assertTrue($user->canAt('reports.view', 1));
        self::assertFalse($user->canAt('reports.view', 2), 'Le magasinier n\'a pas les rapports direction');
        self::assertSame([1], $user->scopeFor('reports.view')->siteIds());
        self::assertNotNull($user->holds(Role::Storekeeper, 2));
        self::assertNull($user->holds(Role::Storekeeper, 1));
        self::assertFalse($user->can('reference.manage'));
    }

    public function testFunctionOnAllSitesAndUnknownSite(): void
    {
        $user = $this->user(Role::Requester, [1], [['role' => Role::Logistics, 'site_id' => null, 'delegated_from' => null]]);
        self::assertTrue($user->scopeFor('reference.manage')->isAll());
        self::assertTrue($user->canAt('approvals.override', 9));
        self::assertFalse($user->canAt('approvals.override', null), 'Sans site, aucune écriture');
    }

    public function testOwnAssignmentIsPreferredOverDelegation(): void
    {
        $user = $this->user(Role::Requester, [1], [
            ['role' => Role::Logistics, 'site_id' => 1, 'delegated_from' => 7],
            ['role' => Role::Logistics, 'site_id' => 1, 'delegated_from' => null],
            ['role' => Role::Supervisor, 'site_id' => 2, 'delegated_from' => 8],
        ]);
        self::assertNull($user->holds(Role::Logistics, 1)['delegated_from']);
        self::assertSame(8, $user->holds(Role::Supervisor, 2)['delegated_from']);
    }

    public function testEveryRoleHasALabelAndVrscRolesAreSeparated(): void
    {
        self::assertTrue(Role::Requester->can('dashboard.view'));
        self::assertFalse(Role::Requester->can('approvals.override'));
        self::assertFalse(Role::Cashier->can('reference.manage'));
        self::assertTrue(Role::Logistics->can('delegations.manage'));
        self::assertSame('/', Role::Storekeeper->homePath());
    }
}
