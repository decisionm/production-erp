<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * DEC-20260903-006 (Accounts) and DEC-20260903-008 (Store): both roles hold
 * full procurement write, and the seeder only ever ADDS it — it re-runs on
 * every deploy and must never rewrite what an administrator configured on the
 * live Roles screen.
 */
class PermissionSeederAccountsProcurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_gains_procurement_write_and_keeps_what_an_administrator_configured(): void
    {
        $accounts = Role::findOrCreate('Accounts', 'web');
        $extra = Permission::findOrCreate('hrms.view', 'web');
        $accounts->givePermissionTo($extra); // configured by hand on the Roles screen

        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class); // idempotent

        $accounts->refresh();
        $this->assertTrue($accounts->hasPermissionTo('procurement.view', 'web'));
        $this->assertTrue($accounts->hasPermissionTo('procurement.manage', 'web'));
        $this->assertTrue($accounts->hasPermissionTo('hrms.view', 'web'), 'the seeder must never remove a hand-configured grant');
    }

    /**
     * DEC-20260903-008. Store already held procurement.view on live and was
     * refused at approval, which is gated on the WRITE permission. The five
     * other permissions the Roles screen gave it must survive the seeder —
     * that is the givePermissionTo-not-syncPermissions contract, and Store is
     * the role that actually exercises it.
     */
    public function test_store_gains_procurement_write_and_keeps_what_the_roles_screen_configured(): void
    {
        $store = Role::findOrCreate('Store', 'web');
        // The live set, as read by roles:show on 03-Sep-2026 — including the
        // procurement.view it already had.
        foreach (['consumption-substitute.view', 'inventory.view', 'maintenance.view', 'procurement.view', 'production.view', 'users.view'] as $name) {
            $store->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class); // idempotent

        $store->refresh();
        $this->assertTrue($store->hasPermissionTo('procurement.manage', 'web'), 'the storekeeper could not approve a requisition without it');
        $this->assertTrue($store->hasPermissionTo('procurement.view', 'web'));

        foreach (['consumption-substitute.view', 'inventory.view', 'maintenance.view', 'production.view', 'users.view'] as $name) {
            $this->assertTrue($store->hasPermissionTo($name, 'web'), "the seeder stripped {$name}, which the Roles screen granted");
        }

        // FC-06: the grant hands Store no finance permission, so the purchase
        // rate stays omitted from every procurement payload it can read.
        $this->assertFalse($store->hasPermissionTo('finance.view', 'web'));
        $this->assertFalse($store->hasPermissionTo('finance.manage', 'web'));
    }
}
