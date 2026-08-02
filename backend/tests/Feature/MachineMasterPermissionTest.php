<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;
use App\Modules\Production\Models\WorkCenter;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Supervisors view the machines, the office edits them."
 *
 * The machine master is one list with two audiences. Every supervisor has to
 * READ it — the Start Batch picker, the configuration forms, the downtime log
 * and the day bin all resolve a machine through the same index — but changing
 * what a machine IS (its code, its capabilities, whether it is in service) is
 * an office act with consequences the floor cannot see: a cavity limit
 * silently blocks approvals, and deactivating a machine stops every shift on
 * it.
 *
 * So the read stays under module:production and the writes move to their own
 * module:machine-master group.
 *
 * The reason it is a CATALOG module rather than a hand-created permission is
 * the trap this file exists to keep shut: RoleService intersects every grant
 * with PermissionService::allPermissionNames(), so a permission outside the
 * catalog is stripped from every role the next time that role is saved — and
 * the guard then refuses everyone, including the office.
 */
class MachineMasterPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    private function machine(): WorkCenter
    {
        return WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
    }

    public function test_a_production_only_user_can_read_the_machine_list(): void
    {
        $this->machine();
        $this->userWith(['production.view', 'production.manage']);

        // Not an admin screen's private data: a supervisor who cannot read
        // this cannot start a shift.
        $this->getJson('/api/v1/production/work-centers?active=1')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'MC-01');
    }

    public function test_a_production_only_user_cannot_add_a_machine(): void
    {
        $this->userWith(['production.view', 'production.manage']);

        $this->postJson('/api/v1/production/work-centers', [
            'code' => 'MC-99', 'name' => 'Machine 99',
        ])->assertForbidden();

        $this->assertDatabaseMissing('work_centers', ['code' => 'MC-99']);
    }

    public function test_a_production_only_user_cannot_change_a_machine(): void
    {
        $machine = $this->machine();
        $this->userWith(['production.view', 'production.manage']);

        // The dangerous one: production.manage is held by every supervisor,
        // and this is the request that retires a machine mid-shift.
        $this->putJson("/api/v1/production/work-centers/{$machine->id}", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($machine->fresh()->is_active);
    }

    public function test_the_machine_master_permission_grants_the_writes(): void
    {
        $this->userWith(['production.view', 'machine-master.manage']);

        $created = $this->postJson('/api/v1/production/work-centers', [
            'code' => 'MC-11', 'name' => 'Machine 11',
            // The capability vocabulary the store request gained: a machine
            // whose limits are known arrives complete instead of needing a
            // second save.
            'capacity_class' => 'High Capacity',
            'permitted_cavities' => [6, 8],
            'cycle_time_min' => '8', 'cycle_time_max' => '14',
        ])->assertCreated()->json('data');

        $this->assertSame([6, 8], $created['permitted_cavities']);
        $this->assertSame('High Capacity', $created['capacity_class']);

        $this->putJson("/api/v1/production/work-centers/{$created['id']}", [
            // R1: the code stays editable, so renaming MC-11 to the factory's
            // own naming is one edit rather than a migration.
            'code' => 'ASB-11', 'max_cavities' => 12,
        ])->assertOk()->assertJsonPath('data.code', 'ASB-11');

        $this->assertSame(12, WorkCenter::find($created['id'])->max_cavities);
    }

    public function test_one_bound_can_be_stated_without_the_other(): void
    {
        // R5: a blank limit never blocks. The `gte` rule used to compare a
        // stated maximum against an absent minimum and 422 — so "this
        // machine never runs above 12 cavities, nobody has measured a floor"
        // could not be recorded at all.
        $this->userWith(['production.view', 'machine-master.manage']);

        $created = $this->postJson('/api/v1/production/work-centers', [
            'code' => 'MC-13', 'name' => 'Machine 13', 'max_cavities' => 12,
        ])->assertCreated()->json('data');

        $this->assertSame(12, $created['max_cavities']);
        $this->assertNull($created['min_cavities'], 'An unstated minimum stays unknown, not invented.');

        // And raising a ceiling later needs only the ceiling.
        $this->putJson("/api/v1/production/work-centers/{$created['id']}", ['max_cavities' => 16])
            ->assertOk()
            ->assertJsonPath('data.max_cavities', 16);
    }

    public function test_a_maximum_still_cannot_be_pushed_below_a_standing_minimum(): void
    {
        // The other half: relaxing the rule must not delete it. The stored
        // minimum is still in force when the payload is silent about it.
        $this->userWith(['production.view', 'machine-master.manage']);

        $machine = WorkCenter::create([
            'code' => 'MC-14', 'name' => 'Machine 14', 'is_active' => true, 'min_cavities' => 8,
        ]);

        $this->putJson("/api/v1/production/work-centers/{$machine->id}", ['max_cavities' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_cavities');

        // Clearing the minimum in the same breath is a coherent request and
        // is allowed.
        $this->putJson("/api/v1/production/work-centers/{$machine->id}", [
            'min_cavities' => null, 'max_cavities' => 4,
        ])->assertOk();
    }

    public function test_the_machine_master_permission_alone_does_not_open_production(): void
    {
        // The split cuts both ways — an office user who maintains machines is
        // not thereby allowed to start batches.
        $this->userWith(['machine-master.manage']);

        $this->getJson('/api/v1/production/work-centers')->assertForbidden();
    }

    public function test_the_administrator_role_is_granted_the_new_module_by_a_reseed(): void
    {
        // The deploy path: PermissionSeeder runs on every release and syncs
        // Administrator to the whole catalog. If machine-master were not in
        // PermissionService::MODULES, this is where the office would silently
        // lose the machine screen.
        $this->seed(PermissionSeeder::class);

        $administrator = Role::where('name', 'Administrator')->firstOrFail();

        $this->assertTrue($administrator->hasPermissionTo('machine-master.view'));
        $this->assertTrue($administrator->hasPermissionTo('machine-master.manage'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($administrator);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/production/work-centers', ['code' => 'MC-12', 'name' => 'Machine 12'])
            ->assertCreated();
    }

    public function test_the_module_is_offered_in_the_roles_picker(): void
    {
        // Without a catalog entry the office could never be GRANTED the
        // permission from the UI, and RoleService would strip it if it were
        // set any other way.
        $this->assertArrayHasKey('machine-master', PermissionService::MODULES);

        $modules = array_column(app(PermissionService::class)->catalog(), 'module');
        $this->assertContains('machine-master', $modules);
    }
}
