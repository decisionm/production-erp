<?php

namespace Tests\Feature;

use App\Console\Commands\DefineStoreRole;
use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Store role. Most of what is asserted here is restraint: that a dry
 * run writes nothing, that the role assigns nobody unless one login is named
 * exactly, that a rename keeps its holders, and that the permissions which
 * keep Administrator administrative (and HRMS out of the Store) are never
 * granted.
 */
class DefineStoreRoleTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalogue(): void
    {
        foreach (app(PermissionService::class)->allPermissionNames() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function login(string $name, string $email, bool $active = true): User
    {
        return User::factory()->create(['name' => $name, 'email' => $email, 'is_active' => $active]);
    }

    private function storeRole(): Role
    {
        return Role::query()->where('name', DefineStoreRole::ROLE)->firstOrFail();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-store')
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->assertNull(Role::query()->where('name', DefineStoreRole::ROLE)->first());
    }

    public function test_write_creates_the_role_with_exactly_the_declared_set(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-store', ['--write' => true])->assertSuccessful();

        $this->assertSame(
            collect(DefineStoreRole::PERMISSIONS)->sort()->values()->all(),
            $this->storeRole()->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_the_store_holds_full_procurement_and_inventory(): void
    {
        foreach (['inventory.view', 'inventory.manage', 'procurement.view', 'procurement.manage'] as $name) {
            $this->assertContains($name, DefineStoreRole::PERMISSIONS);
        }
    }

    public function test_it_assigns_no_user_unless_one_is_named(): void
    {
        $this->seedCatalogue();
        $this->login('Vasanth', 'vasanth@example.com');

        $this->artisan('roles:define-store', ['--write' => true])->assertSuccessful();

        $this->assertSame(0, $this->storeRole()->users()->count());
    }

    /**
     * The whole separation between this role and Administrator is the absence
     * of these. An absence is exactly the kind of thing that erodes silently,
     * so it is asserted rather than described. HRMS is in the list on the
     * owner's word of 02-Sep-2026: "no hrms for Store".
     */
    public function test_it_never_grants_the_permissions_that_keep_administrator_administrative(): void
    {
        $this->seedCatalogue();
        $this->artisan('roles:define-store', ['--write' => true])->assertSuccessful();

        $held = $this->storeRole()->permissions->pluck('name')->all();

        foreach ([
            'configuration-delete.manage',
            'configuration-delete.view',
            'users.manage',
            'roles.manage',
            'finance.view',
            'finance.manage',
            'hrms.view',
            'hrms.manage',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $held, "the Store must never hold {$forbidden}");
        }
    }

    /**
     * FC-06 is a hard line, so it gets its own assertion rather than riding
     * on the list above: no permission this role holds may be a finance one.
     */
    public function test_no_finance_permission_reaches_the_store(): void
    {
        $this->seedCatalogue();
        $this->artisan('roles:define-store', ['--write' => true])->assertSuccessful();

        foreach ($this->storeRole()->permissions as $permission) {
            $this->assertStringStartsNotWith('finance.', $permission->name);
        }
    }

    /**
     * RoleService intersects every grant against PermissionService, so a name
     * outside the catalogue would be stripped on the next save through the
     * Roles screen and then 403 everyone holding it. Every declared
     * permission must therefore be a catalogue entry.
     */
    public function test_every_declared_permission_is_in_the_catalogue(): void
    {
        $catalogue = app(PermissionService::class)->allPermissionNames();

        foreach (DefineStoreRole::PERMISSIONS as $name) {
            $this->assertContains($name, $catalogue, "{$name} is not in PermissionService and would be stripped");
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-store', ['--write' => true])->assertSuccessful();
        $this->artisan('roles:define-store')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }

    public function test_a_dry_run_names_what_it_would_remove_from_an_existing_role(): void
    {
        $this->seedCatalogue();

        $role = Role::findOrCreate(DefineStoreRole::ROLE, 'web');
        $role->syncPermissions([Permission::findOrCreate('finance.view', 'web')]);

        $this->artisan('roles:define-store')
            ->expectsOutputToContain('would be removed')
            ->assertSuccessful();

        // Still there: a dry run reports, it does not correct.
        $this->assertContains('finance.view', $role->fresh()->permissions->pluck('name')->all());
    }

    // ── the rename from "Storekeeper" ──────────────────────────────────────

    public function test_an_existing_storekeeper_role_is_renamed_in_place_keeping_its_holders(): void
    {
        $this->seedCatalogue();
        $former = Role::findOrCreate(DefineStoreRole::FORMER_ROLE, 'web');
        $former->syncPermissions([Permission::findOrCreate('inventory.manage', 'web')]);
        $holder = $this->login('Existing Keeper', 'keeper@example.com');
        $holder->assignRole($former);

        $this->artisan('roles:define-store')
            ->expectsOutputToContain('RENAMED')
            ->assertSuccessful();
        // A dry run renames nothing.
        $this->assertNotNull(Role::query()->where('name', DefineStoreRole::FORMER_ROLE)->first());
        $this->assertNull(Role::query()->where('name', DefineStoreRole::ROLE)->first());

        $this->artisan('roles:define-store', ['--write' => true])->assertSuccessful();

        $this->assertNull(Role::query()->where('name', DefineStoreRole::FORMER_ROLE)->first());
        $store = $this->storeRole();
        $this->assertSame($former->id, $store->id);
        $this->assertTrue($holder->fresh()->hasRole(DefineStoreRole::ROLE));
        $this->assertSame(
            collect(DefineStoreRole::PERMISSIONS)->sort()->values()->all(),
            $store->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_when_both_names_exist_the_former_role_is_left_untouched(): void
    {
        $this->seedCatalogue();
        $former = Role::findOrCreate(DefineStoreRole::FORMER_ROLE, 'web');
        $former->syncPermissions([Permission::findOrCreate('inventory.manage', 'web')]);
        Role::findOrCreate(DefineStoreRole::ROLE, 'web');

        $this->artisan('roles:define-store', ['--write' => true])
            ->expectsOutputToContain('left untouched')
            ->assertSuccessful();

        $this->assertSame(['inventory.manage'], $former->fresh()->permissions->pluck('name')->all());
        $this->assertNotSame($former->id, $this->storeRole()->id);
    }

    // ── --assign ───────────────────────────────────────────────────────────

    public function test_a_dry_run_names_the_login_but_does_not_assign_it(): void
    {
        $this->seedCatalogue();
        $vasanth = $this->login('Vasanth', 'vasanth@example.com');

        $this->artisan('roles:define-store', ['--assign' => 'vasanth@example.com'])
            ->expectsOutputToContain('would be assigned')
            ->assertSuccessful();

        $this->assertNull(Role::query()->where('name', DefineStoreRole::ROLE)->first());
        $this->assertSame(0, $vasanth->fresh()->roles()->count());
    }

    public function test_write_with_assign_gives_exactly_that_login_the_role(): void
    {
        $this->seedCatalogue();
        $vasanth = $this->login('Vasanth', 'vasanth@example.com');
        $other = $this->login('Someone Else', 'else@example.com');

        $this->artisan('roles:define-store', ['--write' => true, '--assign' => 'Vasanth@Example.com'])
            ->expectsOutputToContain('Vasanth now holds it')
            ->assertSuccessful();

        $this->assertTrue($vasanth->fresh()->hasRole(DefineStoreRole::ROLE));
        $this->assertFalse($other->fresh()->hasRole(DefineStoreRole::ROLE));
        $this->assertSame(1, $this->storeRole()->users()->count());
    }

    public function test_assign_by_exact_name_works_and_is_idempotent(): void
    {
        $this->seedCatalogue();
        $vasanth = $this->login('Vasanth', 'vasanth@example.com');

        $this->artisan('roles:define-store', ['--write' => true, '--assign' => 'vasanth'])->assertSuccessful();
        $this->artisan('roles:define-store', ['--write' => true, '--assign' => 'vasanth'])
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertTrue($vasanth->fresh()->hasRole(DefineStoreRole::ROLE));
        $this->assertSame(1, $vasanth->fresh()->roles()->count());
    }

    public function test_assign_refuses_a_login_that_does_not_exist_and_writes_nothing(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-store', ['--write' => true, '--assign' => 'nobody@example.com'])
            ->expectsOutputToContain('never created here')
            ->assertFailed();

        $this->assertNull(Role::query()->where('name', DefineStoreRole::ROLE)->first());
    }

    public function test_assign_refuses_a_substring_rather_than_guessing(): void
    {
        $this->seedCatalogue();
        $this->login('Vasanthi', 'vasanthi@example.com');

        $this->artisan('roles:define-store', ['--write' => true, '--assign' => 'Vasanth'])
            ->assertFailed();

        $this->assertNull(Role::query()->where('name', DefineStoreRole::ROLE)->first());
    }

    public function test_assign_refuses_when_more_than_one_login_matches(): void
    {
        $this->seedCatalogue();
        $this->login('Vasanth', 'vasanth.a@example.com');
        $this->login('Vasanth', 'vasanth.b@example.com');

        $this->artisan('roles:define-store', ['--write' => true, '--assign' => 'Vasanth'])
            ->expectsOutputToContain('matches 2 logins')
            ->assertFailed();

        $this->assertNull(Role::query()->where('name', DefineStoreRole::ROLE)->first());
    }

    public function test_assign_refuses_an_inactive_login(): void
    {
        $this->seedCatalogue();
        $this->login('Vasanth', 'vasanth@example.com', active: false);

        $this->artisan('roles:define-store', ['--write' => true, '--assign' => 'vasanth@example.com'])
            ->expectsOutputToContain('INACTIVE')
            ->assertFailed();

        $this->assertNull(Role::query()->where('name', DefineStoreRole::ROLE)->first());
    }
}
