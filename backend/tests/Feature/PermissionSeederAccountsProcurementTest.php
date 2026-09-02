<?php

namespace Tests\Feature;

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** DEC-20260903-001: Accounts holds full procurement write; the seeder only ever adds it. */
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
}
