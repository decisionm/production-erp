<?php

namespace Tests\Feature;

use App\Console\Commands\DefineStorekeeperRole;
use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Storekeeper role. Most of what is asserted here is restraint: that a
 * dry run writes nothing, that the role assigns nobody, and that the four
 * permissions which keep Administrator administrative are never granted.
 */
class DefineStorekeeperRoleTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalogue(): void
    {
        foreach (app(PermissionService::class)->allPermissionNames() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-storekeeper')
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->assertNull(Role::query()->where('name', DefineStorekeeperRole::ROLE)->first());
    }

    public function test_write_creates_the_role_with_exactly_the_declared_set(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-storekeeper', ['--write' => true])->assertSuccessful();

        $role = Role::query()->where('name', DefineStorekeeperRole::ROLE)->firstOrFail();

        $this->assertSame(
            collect(DefineStorekeeperRole::PERMISSIONS)->sort()->values()->all(),
            $role->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    public function test_it_assigns_no_user_to_the_role(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-storekeeper', ['--write' => true])->assertSuccessful();

        $this->assertSame(0, Role::query()->where('name', DefineStorekeeperRole::ROLE)->firstOrFail()->users()->count());
    }

    /**
     * The whole separation between this role and Administrator is the absence
     * of these four. An absence is exactly the kind of thing that erodes
     * silently, so it is asserted rather than described.
     */
    public function test_it_never_grants_the_permissions_that_keep_administrator_administrative(): void
    {
        $this->seedCatalogue();
        $this->artisan('roles:define-storekeeper', ['--write' => true])->assertSuccessful();

        $held = Role::query()->where('name', DefineStorekeeperRole::ROLE)->firstOrFail()
            ->permissions->pluck('name')->all();

        foreach ([
            'configuration-delete.manage',
            'configuration-delete.view',
            'users.manage',
            'roles.manage',
            'finance.view',
            'finance.manage',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $held, "the Storekeeper must never hold {$forbidden}");
        }
    }

    /**
     * FC-06 is a hard line, so it gets its own assertion rather than riding
     * on the list above: no permission this role holds may be a finance one.
     */
    public function test_no_finance_permission_reaches_the_store(): void
    {
        $this->seedCatalogue();
        $this->artisan('roles:define-storekeeper', ['--write' => true])->assertSuccessful();

        foreach (Role::query()->where('name', DefineStorekeeperRole::ROLE)->firstOrFail()->permissions as $permission) {
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

        foreach (DefineStorekeeperRole::PERMISSIONS as $name) {
            $this->assertContains($name, $catalogue, "{$name} is not in PermissionService and would be stripped");
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedCatalogue();

        $this->artisan('roles:define-storekeeper', ['--write' => true])->assertSuccessful();
        $this->artisan('roles:define-storekeeper')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();
    }

    public function test_a_dry_run_names_what_it_would_remove_from_an_existing_role(): void
    {
        $this->seedCatalogue();

        $role = Role::findOrCreate(DefineStorekeeperRole::ROLE, 'web');
        $role->syncPermissions([Permission::findOrCreate('finance.view', 'web')]);

        $this->artisan('roles:define-storekeeper')
            ->expectsOutputToContain('would be removed')
            ->assertSuccessful();

        // Still there: a dry run reports, it does not correct.
        $this->assertContains('finance.view', $role->fresh()->permissions->pluck('name')->all());
    }
}
