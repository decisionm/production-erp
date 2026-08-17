<?php

namespace Tests\Feature\Configuration;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Configuration\HardDeleteAuthority;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * THE DELETE TIER — DEC-20260817-002 §3: "hard delete is Super Admin / Owner
 * level only; other permitted configuration users may create, edit, activate
 * and deactivate according to RBAC."
 *
 * Phase 7.6 shipped the SEAM and refused every hard delete, because the repo
 * had no permission to name. This is the permission, and these are the four
 * things that have to be true about it — three of which are about the shape
 * of the grant rather than about any one master, which is why they live here
 * and not in the per-entity tests.
 */
class HardDeleteTierTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(string $code = 'TIER-1'): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => 'Tier Test '.$code, 'is_active' => true]);
    }

    /** A user who may manage inventory, and nothing else. */
    private function moduleManager(): User
    {
        $this->seed(PermissionSeeder::class);

        $role = Role::findOrCreate('Store Keeper', 'web');
        $role->givePermissionTo(Permission::findOrCreate('inventory.manage', 'web'));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function owner(): User
    {
        $this->seed(PermissionSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        // The owner logs in as Administrator, which PermissionSeeder syncs
        // the WHOLE catalogue onto — that is how the tier reaches them.
        $user->assignRole('Administrator');

        return $user;
    }

    /**
     * THE REASON THIS IS A CATALOG ENTRY AND NOT A HAND-CREATED PERMISSION.
     *
     * RoleService::validPermissions() intersects every grant with
     * PermissionService::allPermissionNames(). A permission outside that list
     * is silently STRIPPED from every role the next time somebody saves it on
     * the Roles screen — so the tier would work until an administrator opened
     * that page, and then stop working for everyone, with no error anywhere.
     * (The machine-master precedent's comment says exactly this.)
     */
    public function test_the_tier_is_in_the_permission_catalogue(): void
    {
        $this->assertArrayHasKey('configuration-delete', PermissionService::MODULES);

        $this->assertContains(
            HardDeleteAuthority::PERMISSION,
            app(PermissionService::class)->allPermissionNames(),
            'a permission outside allPermissionNames() is stripped from every role on the next save',
        );

        $this->assertContains(
            'configuration-delete',
            array_column(app(PermissionService::class)->catalog(), 'module'),
            'the Roles UI reads the catalogue — a tier nobody can tick is a tier nobody holds',
        );
    }

    /** The owner's own login receives it; the seeder needs no special case. */
    public function test_the_administrator_role_holds_the_tier_and_a_module_role_does_not(): void
    {
        $owner = $this->owner();
        $keeper = $this->moduleManager();

        $this->assertTrue($owner->can(HardDeleteAuthority::PERMISSION));
        $this->assertFalse($keeper->can(HardDeleteAuthority::PERMISSION));
    }

    /**
     * THE SPLIT THE DECISION ASKS FOR, end to end: the same user, the same
     * record, the same module permission — refused the delete, allowed the
     * archive. Anything that gated both on one permission would fail here.
     */
    public function test_a_module_manager_is_refused_the_delete_but_may_still_archive(): void
    {
        $warehouse = $this->warehouse();

        Sanctum::actingAs($this->moduleManager());

        $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);

        $this->postJson("/api/v1/inventory/warehouses/{$warehouse->id}/archive", ['reason' => 'not a real place'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    /** And the resource tells the screen so, rather than the screen guessing. */
    public function test_the_can_block_answers_false_for_a_user_without_the_tier(): void
    {
        $warehouse = $this->warehouse();

        Sanctum::actingAs($this->moduleManager());

        $this->getJson("/api/v1/inventory/warehouses/{$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonPath('data.can.archive', true);

        // FALSE, never null: "undetermined" would send the confirm dialog off
        // to count thirty tables for an answer no count can change.
        $this->getJson('/api/v1/inventory/warehouses')
            ->assertOk()
            ->assertJsonPath('data.0.can.delete', false);
    }

    /** The owner gets the real answer, and it is authoritative on show. */
    public function test_an_owner_is_offered_the_delete_on_an_unused_master(): void
    {
        $warehouse = $this->warehouse();

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/v1/inventory/warehouses/{$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', true);

        // …and undetermined, not assumed, on the list. The key must be
        // PRESENT and null: assertJsonPath(null) also passes for an absent
        // key, so the structure is asserted too — otherwise this half of the
        // pattern, the half WS-2 and WS-3 copy, is pinned by nothing.
        $list = $this->getJson('/api/v1/inventory/warehouses')
            ->assertOk()
            ->assertJsonStructure(['data' => [['can' => ['edit', 'activate', 'archive', 'delete']]]])
            ->assertJsonPath('data.0.can.delete', null);

        $this->assertArrayHasKey('delete', $list->json('data.0.can'));
    }

    /**
     * FAIL-CLOSED on a database where the permission row was never seeded —
     * spatie throws PermissionDoesNotExist from hasPermissionTo(), which
     * would turn a 403 into a 500. can() must answer false instead.
     */
    public function test_a_database_missing_the_permission_row_refuses_rather_than_erroring(): void
    {
        $warehouse = $this->warehouse();

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('inventory.manage', 'web'));

        $this->assertDatabaseMissing('permissions', ['name' => HardDeleteAuthority::PERMISSION]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/inventory/warehouses/{$warehouse->id}")
            ->assertForbidden();
    }
}
