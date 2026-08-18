<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * THE MACHINE MASTER, through the Configuration Lifecycle Contract
 * (DEC-20260817-002).
 *
 * Two things make this entity different from every other master in the
 * module and both are pinned here:
 *
 *  1 · THE PERMISSION SPLIT. Reading the machine list is `production.view`
 *      (every supervisor resolves a machine through it); changing what a
 *      machine IS — including archiving it — is `machine-master.manage`.
 *      Archive and activate are WRITES and live on the machine-master side.
 *
 *  2 · TWO CASCADING CHILDREN WITH NO DATABASE BACKSTOP.
 *      `production_configurations` and `production_downtime_events` are
 *      `ON DELETE CASCADE`, so a delete that got past the check would take
 *      the whole recipe and the idle-time history with it, silently. Every
 *      delete-refused test below asserts those rows are STILL THERE
 *      afterwards — a refusal that quietly cleaned up would pass a test
 *      that only checked the status code.
 */
class WorkCenterLifecycleTest extends FloorMasterLifecycleTestCase
{
    protected function modulePermissions(): array
    {
        return ['production.view', 'production.manage', 'machine-master.view', 'machine-master.manage'];
    }

    private function machine(string $code = 'MC-01', bool $active = true): WorkCenter
    {
        return WorkCenter::create(['code' => $code, 'name' => 'Machine '.$code, 'is_active' => $active]);
    }

    // ---- Create ---------------------------------------------------------

    public function test_a_machine_is_created_and_starts_in_service(): void
    {
        $this->manager();

        $this->postJson('/api/v1/production/work-centers', ['code' => 'MC-07', 'name' => 'Machine 7'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MC-07')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.can.edit', true)
            ->assertJsonPath('data.can.archive', true);
    }

    public function test_a_duplicate_code_is_refused_even_when_the_holder_is_archived(): void
    {
        $this->manager();
        $machine = $this->machine('MC-01');

        $this->postJson("/api/v1/production/work-centers/{$machine->id}/archive")->assertOk();

        // DEC-20260817-002 §2: an archived record RETAINS AND RESERVES its
        // business code. The uniqueness rule is global on purpose and must
        // not be narrowed to active rows.
        $this->postJson('/api/v1/production/work-centers', ['code' => 'MC-01', 'name' => 'Impostor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ---- Edit -----------------------------------------------------------

    public function test_a_machine_is_edited_by_the_office_and_not_by_the_floor(): void
    {
        $machine = $this->machine();

        $this->actorWith(['production.view', 'production.manage']);
        $this->putJson("/api/v1/production/work-centers/{$machine->id}", ['name' => 'Blow Moulder 1'])
            ->assertForbidden();

        $this->manager();
        $this->putJson("/api/v1/production/work-centers/{$machine->id}", ['name' => 'Blow Moulder 1'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Blow Moulder 1');
    }

    public function test_the_floor_is_told_it_may_not_write_rather_than_shown_buttons_that_403(): void
    {
        $this->machine();
        $this->actorWith(['production.view', 'production.manage']);

        $row = $this->getJson('/api/v1/production/work-centers')->assertOk()->json('data.0');

        // The split is real, so `can` must say so. Every action false —
        // including delete, which is a decision no amount of counting would
        // change, not an unknown.
        $this->assertSame(
            ['edit' => false, 'activate' => false, 'archive' => false, 'delete' => false],
            $row['can'],
        );

        // And the GUARD agrees with the advertisement. `can: false` and a
        // 403 are different failures: archiving a machine is a WRITE, so it
        // sits in the machine-master group with store and update, not beside
        // the index every supervisor reads.
        $this->postJson("/api/v1/production/work-centers/{$row['id']}/archive")->assertForbidden();
        $this->postJson("/api/v1/production/work-centers/{$row['id']}/activate")->assertForbidden();
        $this->assertTrue((bool) WorkCenter::findOrFail($row['id'])->is_active);
    }

    // ---- Archive / activate ---------------------------------------------

    public function test_a_referenced_machine_still_archives_and_reactivates(): void
    {
        $this->manager();
        $machine = $this->machine();
        $this->configurationOn($machine);

        $this->postJson("/api/v1/production/work-centers/{$machine->id}/archive", ['reason' => 'moved to the shed'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can.activate', true)
            ->assertJsonPath('data.can.archive', false);

        // Archive deletes nothing: the configuration is exactly where it was.
        $this->assertSame(1, DB::table('production_configurations')->where('work_center_id', $machine->id)->count());

        $this->postJson("/api/v1/production/work-centers/{$machine->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.can.archive', true);
    }

    public function test_an_archived_machine_leaves_new_selection_while_history_still_renders(): void
    {
        $this->manager();
        $machine = $this->machine();
        $shift = Shift::create(['name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'FG-TEST', 'name' => 'FG Test', 'is_active' => true]);

        $entryId = DB::table('shift_production_entries')->insertGetId([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'production_date' => '2026-08-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/production/work-centers/{$machine->id}/archive")->assertOk();

        // Gone from what a NEW batch may pick...
        $active = $this->getJson('/api/v1/production/work-centers?active=1')->assertOk()->json('data');
        $this->assertSame([], array_column($active, 'code'));

        // ...and still there for everything that has to resolve the past.
        $all = $this->getJson('/api/v1/production/work-centers')->assertOk()->json('data');
        $this->assertSame(['MC-01'], array_column($all, 'code'));
        $this->assertSame($machine->id, (int) DB::table('shift_production_entries')->where('id', $entryId)->value('work_center_id'));
    }

    // ---- Delete ---------------------------------------------------------

    public function test_a_module_manager_without_the_tier_may_not_hard_delete_but_may_still_archive(): void
    {
        $this->manager();
        $machine = $this->machine();

        $this->deleteJson("/api/v1/production/work-centers/{$machine->id}")->assertForbidden();
        $this->assertDatabaseHas('work_centers', ['id' => $machine->id]);

        // `can` agrees with the enforcement — the button was never offered.
        $this->getJson("/api/v1/production/work-centers/{$machine->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonPath('data.can.archive', true);

        // And the reversible half is still open to them.
        $this->postJson("/api/v1/production/work-centers/{$machine->id}/archive")->assertOk();
    }

    public function test_deleting_a_referenced_machine_is_refused_with_counts_and_every_cascade_child_survives(): void
    {
        $this->owner();
        $machine = $this->machine();
        $configurationId = $this->configurationOn($machine);
        $downtimeEventId = $this->downtimeEventOn($machine);
        $bagId = $this->dayBinBagOn($machine);
        $requestId = DB::table('material_requests')->insertGetId([
            'status' => 'draft', 'work_center_id' => $machine->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/production/work-centers/{$machine->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_in_use')
            ->assertJsonPath('alternative', 'archive');

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['production_configurations']['count']);
        $this->assertSame(1, $blocking['production_downtime_events']['count']);
        $this->assertSame(1, $blocking['material_bags']['count']);
        $this->assertSame(1, $blocking['material_requests']['count']);
        $this->assertSame('production configuration', $blocking['production_configurations']['label']);

        // THE POINT OF THIS TEST. The two cascading children would have been
        // destroyed by the database, silently, had the refusal not held —
        // and the two SET NULL children would simply have stopped naming the
        // machine, with no error anywhere.
        $this->assertDatabaseHas('work_centers', ['id' => $machine->id]);
        $this->assertDatabaseHas('production_configurations', ['id' => $configurationId, 'work_center_id' => $machine->id]);
        $this->assertDatabaseHas('production_downtime_events', ['id' => $downtimeEventId, 'work_center_id' => $machine->id]);
        $this->assertDatabaseHas('material_bags', ['id' => $bagId, 'day_bin_work_center_id' => $machine->id]);
        $this->assertDatabaseHas('material_requests', ['id' => $requestId, 'work_center_id' => $machine->id]);
    }

    public function test_a_withdrawn_configuration_still_blocks_the_delete(): void
    {
        $this->owner();
        $machine = $this->machine();
        $configurationId = $this->configurationOn($machine);

        // Soft-deleted, so invisible to an ordinary count — and still a
        // physical row the cascade would take.
        DB::table('production_configurations')->where('id', $configurationId)->update(['deleted_at' => now()]);

        $this->deleteJson("/api/v1/production/work-centers/{$machine->id}")
            ->assertStatus(422)
            ->assertJsonPath('blocking.0.code', 'production_configurations');

        $this->assertDatabaseHas('production_configurations', ['id' => $configurationId]);
    }

    public function test_an_unused_machine_is_really_deleted_and_frees_its_code(): void
    {
        $this->owner();
        $machine = $this->machine('MC-09');

        $this->deleteJson("/api/v1/production/work-centers/{$machine->id}")->assertNoContent();

        // A real delete, not a soft one: nothing left to reserve the code.
        $this->assertSame(0, WorkCenter::withTrashed()->whereKey($machine->id)->count());

        $this->postJson('/api/v1/production/work-centers', ['code' => 'MC-09', 'name' => 'Machine 9 again'])
            ->assertCreated();
    }

    public function test_an_archived_machine_that_was_never_used_may_still_be_deleted(): void
    {
        $this->owner();
        $machine = $this->machine('MC-08');

        $this->postJson("/api/v1/production/work-centers/{$machine->id}/archive")->assertOk();

        // Being archived is not being used.
        $this->deleteJson("/api/v1/production/work-centers/{$machine->id}")->assertNoContent();
        $this->assertSame(0, WorkCenter::withTrashed()->whereKey($machine->id)->count());
    }

    // ---- Audit ----------------------------------------------------------

    public function test_every_lifecycle_act_is_recorded_in_the_configuration_audit(): void
    {
        $user = $this->manager();

        $machine = $this->postJson('/api/v1/production/work-centers', ['code' => 'MC-05', 'name' => 'Machine 5'])
            ->assertCreated()->json('data');

        $this->putJson("/api/v1/production/work-centers/{$machine['id']}", ['name' => 'Machine Five'])->assertOk();
        $this->postJson("/api/v1/production/work-centers/{$machine['id']}/archive")->assertOk();

        $trail = Activity::query()
            ->where('log_name', WorkCenter::CONFIGURATION_LOG_NAME)
            ->where('subject_type', (new WorkCenter)->getMorphClass())
            ->where('subject_id', $machine['id'])
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['work_center.created', 'work_center.updated', 'work_center.updated'],
            $trail->pluck('description')->all(),
        );
        $this->assertSame([$user->id, $user->id, $user->id], $trail->pluck('causer_id')->map(fn ($id) => (int) $id)->all());
        // The before/after of the edit, and the archive as its own row.
        $edit = json_decode($trail[1]->attribute_changes, true);
        $this->assertSame(['name' => 'Machine Five'], $edit['attributes'], 'logOnlyDirty: untouched columns must not be logged');
        $this->assertSame(['name' => 'Machine 5'], $edit['old']);

        $archive = json_decode($trail[2]->attribute_changes, true);
        $this->assertFalse($archive['attributes']['is_active']);
    }

    // ---- Fixtures -------------------------------------------------------

    private function configurationOn(WorkCenter $machine): int
    {
        $item = Item::firstOrCreate(['sku' => 'BTL-CFG'], ['name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);

        return DB::table('production_configurations')->insertGetId([
            'work_center_id' => $machine->id, 'item_id' => $item->id, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function downtimeEventOn(WorkCenter $machine): int
    {
        $reason = DowntimeReason::create([
            'code' => 'PWR', 'description' => 'Power cut', 'planning_type' => 'unplanned',
        ]);

        return DB::table('production_downtime_events')->insertGetId([
            'work_center_id' => $machine->id, 'downtime_reason_id' => $reason->id,
            'production_date' => '2026-08-01', 'minutes' => '30', 'is_planned' => false,
            'known_before_start' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dayBinBagOn(WorkCenter $machine): int
    {
        $item = Item::firstOrCreate(['sku' => 'RESIN'], ['name' => 'Relpet', 'uom' => 'Kg', 'is_active' => true]);
        $lotId = DB::table('material_lots')->insertGetId([
            'item_id' => $item->id, 'received_date' => '2026-08-01', 'bag_count' => 1,
            'total_received_kg' => '25', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('material_bags')->insertGetId([
            'material_lot_id' => $lotId, 'barcode' => 'BAG-1', 'original_kg' => '25',
            'remaining_kg' => '25', 'day_bin_work_center_id' => $machine->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
