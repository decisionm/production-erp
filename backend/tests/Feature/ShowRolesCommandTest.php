<?php

namespace Tests\Feature;

use App\Console\Commands\ShowRoles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The read-only live role read (P1-01). It exists to answer one FC-06
 * question from evidence — did any live role hold procurement.* without
 * finance.* while the Procurement payloads served rates ungated? — so the
 * flag is the part under test, along with the promise that it writes nothing.
 */
class ShowRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $permissions */
    private function role(string $name, array $permissions, int $users = 0): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->syncPermissions($permissions);

        for ($i = 0; $i < $users; $i++) {
            User::factory()->create(['is_active' => true])->assignRole($role);
        }

        return $role;
    }

    public function test_prints_one_line_per_role_and_flags_procurement_without_finance(): void
    {
        $this->role('Store', ['procurement.view', 'procurement.manage', 'inventory.view'], users: 2);
        $this->role('Accounts', ['procurement.view', 'finance.view', 'finance.manage'], users: 1);

        $this->artisan('roles:show')
            // Roles by name, permissions sorted — the output is stable to read
            // and to diff between two runs. (A migration seeds an
            // 'Administrator' role with no permissions; it prints too.)
            ->expectsOutput('Accounts  users=1  permissions: finance.manage, finance.view, procurement.view')
            ->expectsOutput('Administrator  users=0  permissions: (none)')
            ->expectsOutput('Store  users=2  permissions: inventory.view, procurement.manage, procurement.view  '.ShowRoles::RATE_FLAG)
            ->expectsOutputToContain('VERDICT: 1 role(s) hold procurement.* without finance.*')
            ->assertSuccessful();
    }

    public function test_a_role_with_no_procurement_is_not_flagged_and_an_empty_role_says_so(): void
    {
        $this->role('Floor', ['production.view']);
        $this->role('Nobody', []);

        $this->artisan('roles:show')
            ->expectsOutput('Floor  users=0  permissions: production.view')
            ->expectsOutput('Nobody  users=0  permissions: (none)')
            ->doesntExpectOutputToContain(ShowRoles::RATE_FLAG)
            ->expectsOutputToContain('VERDICT: no role holds procurement.* without finance.*')
            ->assertSuccessful();
    }

    public function test_it_is_read_only(): void
    {
        $store = $this->role('Store', ['procurement.view'], users: 1);
        $before = [
            'roles' => Role::query()->orderBy('id')->get()->toArray(),
            'permissions' => Permission::query()->orderBy('id')->get()->toArray(),
            'role_permissions' => $store->permissions()->orderBy('id')->pluck('name')->all(),
            'users' => User::query()->orderBy('id')->get()->toArray(),
        ];

        $this->artisan('roles:show')->assertSuccessful();

        $this->assertEquals($before, [
            'roles' => Role::query()->orderBy('id')->get()->toArray(),
            'permissions' => Permission::query()->orderBy('id')->get()->toArray(),
            'role_permissions' => $store->fresh()->permissions()->orderBy('id')->pluck('name')->all(),
            'users' => User::query()->orderBy('id')->get()->toArray(),
        ]);
    }

    public function test_an_empty_database_is_reported_not_crashed(): void
    {
        // Migration 2026_07_27_000001 seeds 'Administrator'; clear it so the
        // no-roles branch is actually reached.
        Role::query()->delete();

        $this->artisan('roles:show')
            ->expectsOutputToContain('No roles in this database.')
            ->assertSuccessful();
    }
}
