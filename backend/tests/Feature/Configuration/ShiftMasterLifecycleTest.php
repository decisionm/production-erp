<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * THE SHIFT MASTER, through the Configuration Lifecycle Contract.
 *
 * The selection half of this entity's contract — operational surfaces see
 * ACTIVE shifts only while history keeps resolving a deactivated one — is
 * already pinned by Tests\Feature\Production\ShiftLifecycleContractTest
 * against the shape live actually has (three active + three rename-era
 * rows). This file adds what that one does not have: Edit, Archive/Activate
 * as endpoints, the delete tier, and the dependency refusal.
 *
 * THE REFERENCE WITH NOTHING BEHIND IT — THE TALLY VOUCHER. Under shift
 * granularity (DEC-20260807-010) a day's production posts as one Stock
 * Journal per (production_date, shift), and that voucher names the SHIFT as
 * its syncable through `syncable_type` + `syncable_id`: two plain columns,
 * no foreign key, no cascade. Nothing in the database or in the schema
 * backstop would stop a shift being deleted out from under a posted
 * voucher — only the declared check does, and that is what the two Tally
 * tests below prove, in both directions: the delete is REFUSED, and
 * archiving mutates NOTHING on the voucher (DEC-20260817-002 §4).
 */
class ShiftMasterLifecycleTest extends FloorMasterLifecycleTestCase
{
    protected function modulePermissions(): array
    {
        return ['production.view', 'production.manage'];
    }

    private function shift(string $name = 'Shift A', bool $active = true): Shift
    {
        return Shift::create(['name' => $name, 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => $active]);
    }

    // ---- Create / edit --------------------------------------------------

    public function test_a_shift_is_created_and_edited(): void
    {
        $this->manager();

        $shift = $this->postJson('/api/v1/production/shifts', [
            'name' => 'Shift D', 'start_time' => '06:00', 'end_time' => '14:00',
        ])->assertCreated()->assertJsonPath('data.is_active', true)->json('data');

        // Edit did not exist on this master at all before the contract.
        $this->putJson("/api/v1/production/shifts/{$shift['id']}", ['name' => 'Shift Delta'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Shift Delta');
    }

    public function test_a_duplicate_name_is_refused_even_when_the_holder_is_archived(): void
    {
        $this->manager();
        $shift = $this->shift('Shift A');

        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive")->assertOk();

        // A shift's NAME is its business code, and an archived one keeps it
        // reserved — which is exactly why the rename-era Morning/Afternoon/
        // Night rows on live may never be recreated.
        $this->postJson('/api/v1/production/shifts', [
            'name' => 'Shift A', 'start_time' => '06:00', 'end_time' => '14:00',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        // And an edit may not steal it either.
        $other = $this->shift('Shift B');
        $this->putJson("/api/v1/production/shifts/{$other->id}", ['name' => 'Shift A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ---- Archive / activate ---------------------------------------------

    public function test_a_referenced_shift_still_archives_and_reactivates(): void
    {
        $this->manager();
        $shift = $this->shift();
        $entryId = $this->batchOn($shift);

        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive", ['reason' => 'renamed'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can.activate', true);

        $this->assertSame($shift->id, (int) DB::table('shift_production_entries')->where('id', $entryId)->value('shift_id'));

        $this->postJson("/api/v1/production/shifts/{$shift->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_an_archived_shift_leaves_new_selection_while_history_still_renders(): void
    {
        $this->manager();
        $shift = $this->shift();
        $entryId = $this->batchOn($shift);

        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive")->assertOk();

        $this->assertSame([], array_column($this->getJson('/api/v1/production/shifts?active=1')->assertOk()->json('data'), 'name'));
        $this->assertSame(['Shift A'], array_column($this->getJson('/api/v1/production/shifts')->assertOk()->json('data'), 'name'));
        $this->assertSame($shift->id, (int) DB::table('shift_production_entries')->where('id', $entryId)->value('shift_id'));
    }

    public function test_archiving_a_shift_mutates_nothing_on_its_tally_voucher(): void
    {
        $this->manager();
        $shift = $this->shift();
        $voucherId = $this->tallyVoucherFor($shift);
        $before = DB::table('tally_sync_entries')->where('id', $voucherId)->first();

        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive")->assertOk();

        // DEC-20260817-002 §4: archiving in the ERP causes NO Tally
        // mutation. The row is compared column by column, not merely
        // counted, so a quietly rewritten status or payload would fail.
        $this->assertEquals($before, DB::table('tally_sync_entries')->where('id', $voucherId)->first());
    }

    // ---- Delete ---------------------------------------------------------

    public function test_a_module_manager_without_the_tier_may_not_hard_delete_but_may_still_archive(): void
    {
        $this->manager();
        $shift = $this->shift();

        $this->deleteJson("/api/v1/production/shifts/{$shift->id}")->assertForbidden();
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);

        $this->getJson("/api/v1/production/shifts/{$shift->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonPath('data.can.archive', true);

        $this->postJson("/api/v1/production/shifts/{$shift->id}/archive")->assertOk();
    }

    public function test_deleting_a_referenced_shift_is_refused_with_counts_and_every_child_survives(): void
    {
        $this->owner();
        $shift = $this->shift();
        $entryId = $this->batchOn($shift);
        $summaryId = DB::table('shift_summaries')->insertGetId([
            'shift_id' => $shift->id, 'production_date' => '2026-08-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $requestId = DB::table('material_requests')->insertGetId([
            'status' => 'draft', 'shift_id' => $shift->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->deleteJson("/api/v1/production/shifts/{$shift->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_in_use')
            ->assertJsonPath('alternative', 'archive');

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['shift_production_entries']['count']);
        $this->assertSame(1, $blocking['shift_summaries']['count']);
        $this->assertSame(1, $blocking['material_requests']['count']);

        // `material_requests.shift_id` is ON DELETE SET NULL — no database
        // refusal, no cascade, nothing but the declared check. It must still
        // name the shift.
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertSame($shift->id, (int) DB::table('shift_production_entries')->where('id', $entryId)->value('shift_id'));
        $this->assertSame($shift->id, (int) DB::table('shift_summaries')->where('id', $summaryId)->value('shift_id'));
        $this->assertSame($shift->id, (int) DB::table('material_requests')->where('id', $requestId)->value('shift_id'));
    }

    public function test_a_shift_named_by_a_tally_voucher_cannot_be_deleted(): void
    {
        $this->owner();
        $shift = $this->shift();
        $voucherId = $this->tallyVoucherFor($shift);

        // No foreign key stands behind this reference and no cascade
        // declares it; without the declared check the report would come back
        // clear and a posted Stock Journal would lose the row it names.
        $response = $this->deleteJson("/api/v1/production/shifts/{$shift->id}")->assertStatus(422);

        $blocking = collect($response->json('blocking'))->keyBy('code');
        $this->assertSame(1, $blocking['tally_shift_voucher']['count']);
        $this->assertSame('Tally shift voucher', $blocking['tally_shift_voucher']['label']);

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('tally_sync_entries', ['id' => $voucherId, 'syncable_id' => $shift->id]);
    }

    public function test_another_documents_voucher_with_the_same_id_does_not_block_a_shift(): void
    {
        $this->owner();
        $shift = $this->shift();

        // Same numeric id, different syncable_type. No morph map is
        // enforced in this repo, so a check that matched on syncable_id
        // alone would refuse this delete for a batch's voucher — an
        // invented dependency, and a refusal nobody could ever clear.
        DB::table('tally_sync_entries')->insert([
            'syncable_type' => 'App\\Modules\\Production\\Models\\ShiftProductionEntry',
            'syncable_id' => $shift->id, 'tally_voucher_type' => 'Stock Journal',
            'payload' => '{}', 'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/production/shifts/{$shift->id}")->assertNoContent();
    }

    public function test_an_unused_shift_is_really_deleted_and_frees_its_name(): void
    {
        $this->owner();
        $shift = $this->shift('Shift Z');

        $this->deleteJson("/api/v1/production/shifts/{$shift->id}")->assertNoContent();
        $this->assertSame(0, Shift::withTrashed()->whereKey($shift->id)->count());

        $this->postJson('/api/v1/production/shifts', [
            'name' => 'Shift Z', 'start_time' => '06:00', 'end_time' => '14:00',
        ])->assertCreated();
    }

    // ---- Audit ----------------------------------------------------------

    public function test_every_lifecycle_act_is_recorded_in_the_configuration_audit(): void
    {
        $user = $this->manager();

        $shift = $this->postJson('/api/v1/production/shifts', [
            'name' => 'Shift E', 'start_time' => '06:00', 'end_time' => '14:00',
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/production/shifts/{$shift['id']}", ['name' => 'Shift Echo'])->assertOk();
        $this->postJson("/api/v1/production/shifts/{$shift['id']}/archive")->assertOk();

        $trail = Activity::query()
            ->where('log_name', Shift::CONFIGURATION_LOG_NAME)
            ->where('subject_type', (new Shift)->getMorphClass())
            ->where('subject_id', $shift['id'])
            ->orderBy('id')
            ->get();

        $this->assertSame(['shift.created', 'shift.updated', 'shift.updated'], $trail->pluck('description')->all());
        $this->assertSame([$user->id, $user->id, $user->id], $trail->pluck('causer_id')->map(fn ($id) => (int) $id)->all());

        $edit = json_decode($trail[1]->attribute_changes, true);
        $this->assertSame(['name' => 'Shift Echo'], $edit['attributes']);
        $this->assertSame(['name' => 'Shift E'], $edit['old']);
    }

    // ---- Fixtures -------------------------------------------------------

    private function batchOn(Shift $shift): int
    {
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);
        $item = Item::firstOrCreate(['sku' => 'BTL-1'], ['name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'FG-TEST'], ['name' => 'FG Test', 'is_active' => true]);

        return DB::table('shift_production_entries')->insertGetId([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'production_date' => '2026-08-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A consolidated Stock Journal exactly as TallySyncService writes one. */
    private function tallyVoucherFor(Shift $shift): int
    {
        return DB::table('tally_sync_entries')->insertGetId([
            'syncable_type' => (new Shift)->getMorphClass(),
            'syncable_id' => $shift->id,
            'tally_voucher_type' => 'Stock Journal',
            'payload' => '{"voucher_number":"SJ-20260801-S'.$shift->id.'"}',
            'status' => 'synced', 'attempts' => 1, 'synced_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
