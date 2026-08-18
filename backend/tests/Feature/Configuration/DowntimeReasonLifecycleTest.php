<?php

namespace Tests\Feature\Configuration;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Support\Configuration\ConfigurationLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * THE DOWNTIME REASON MASTER, through the Configuration Lifecycle Contract.
 *
 * This is the audit's flagged entity: `production_downtime_events` cascades
 * from it (`ON DELETE CASCADE`), so the database has NO backstop — a delete
 * that got past the check would take the idle-time history with it and the
 * report would simply have less history than yesterday, with no error
 * anywhere. The refusal test asserts the events are still there.
 *
 * WHY THERE IS NO `deleted_at` ON THIS TABLE, and why that is the right
 * answer rather than a shortfall. The audit's Tier 1 list asks for one so
 * that Archive "has somewhere to go". It does not need one:
 * ConfigurationLifecycle::archive() takes the ACTIVE-FLAG branch first and
 * RETURNS — the soft-delete branch is only ever reached by a master with no
 * flag at all — and `downtime_reasons` has `is_active`. So the column would
 * never be written, while adding it together with the SoftDeletes trait
 * would silently re-scope every existing DowntimeReason query (the picker,
 * ProductionDowntimeService, ValidatesDowntimeEvents) for zero gain. The
 * two tests at the bottom pin BOTH halves of that reasoning, so the day
 * archive()'s branch order changes, this file says so.
 */
class DowntimeReasonLifecycleTest extends FloorMasterLifecycleTestCase
{
    protected function modulePermissions(): array
    {
        return ['production.view', 'production.manage'];
    }

    private function reason(string $code = 'PWR', bool $active = true): DowntimeReason
    {
        return DowntimeReason::create([
            'code' => $code, 'description' => 'Power cut', 'planning_type' => 'unplanned',
            'is_active' => $active, 'selectable_at_start' => true,
        ]);
    }

    // ---- Create / edit --------------------------------------------------

    public function test_a_downtime_reason_is_created_and_edited(): void
    {
        $this->manager();

        $reason = $this->postJson('/api/v1/production/downtime-reasons', [
            'code' => 'MLD-CHG', 'description' => 'Mould change', 'planning_type' => 'planned',
        ])->assertCreated()->assertJsonPath('data.is_active', true)->json('data');

        $this->putJson("/api/v1/production/downtime-reasons/{$reason['id']}", [
            'code' => 'MLD-CHG', 'description' => 'Mould changeover', 'planning_type' => 'planned',
        ])->assertOk()->assertJsonPath('data.description', 'Mould changeover');
    }

    public function test_a_duplicate_code_is_refused_even_when_the_holder_is_withdrawn(): void
    {
        $this->manager();
        $reason = $this->reason();

        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive")->assertOk();

        $this->postJson('/api/v1/production/downtime-reasons', [
            'code' => 'PWR', 'description' => 'Impostor', 'planning_type' => 'unplanned',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    // ---- Archive / activate ---------------------------------------------

    public function test_a_referenced_reason_still_archives_and_reactivates(): void
    {
        $this->manager();
        $reason = $this->reason();
        $eventId = $this->downtimeEventFor($reason);

        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive", ['reason' => 'merged into MAINT'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can.activate', true)
            ->assertJsonPath('data.can.archive', false);

        // Archive deletes nothing — and this child is the cascade side.
        $this->assertDatabaseHas('production_downtime_events', ['id' => $eventId, 'downtime_reason_id' => $reason->id]);

        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_a_withdrawn_reason_leaves_new_selection_while_history_still_renders(): void
    {
        $this->manager();
        $reason = $this->reason();
        $eventId = $this->downtimeEventFor($reason);

        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive")->assertOk();

        // Out of the Start Batch picker...
        $picker = $this->getJson('/api/v1/production/downtime-reasons?selectable_at_start=1')->assertOk()->json('data');
        $this->assertSame([], array_column($picker, 'code'));

        // ...and out of what a completion may name. THIS WIDENS THE REFUSAL
        // SET ON LIVE DATA: the rule was a bare exists: before, so a
        // withdrawn reason was still choosable and Archive meant nothing on
        // this master.
        $entryId = $this->batch();
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'running_hours' => '8', 'quantity_produced' => '100',
            'downtime_events' => [['downtime_reason_id' => $reason->id, 'minutes' => '30']],
        ])->assertStatus(422)->assertJsonValidationErrors('downtime_events.0.downtime_reason_id');

        // The admin list and the recorded history still carry it.
        $this->assertSame(['PWR'], array_column($this->getJson('/api/v1/production/downtime-reasons')->assertOk()->json('data'), 'code'));
        $this->assertDatabaseHas('production_downtime_events', ['id' => $eventId, 'downtime_reason_id' => $reason->id]);
    }

    public function test_an_active_reason_is_still_accepted_on_a_completion(): void
    {
        $this->manager();
        $reason = $this->reason();
        $entryId = $this->batch();

        // The half that makes the refusal above non-vacuous: the ACTIVE row
        // must still pass the rule, so no error on that field.
        $this->postJson("/api/v1/production/shift-production-entries/{$entryId}/complete", [
            'running_hours' => '8', 'quantity_produced' => '100',
            'downtime_events' => [['downtime_reason_id' => $reason->id, 'minutes' => '30']],
        ])->assertJsonMissingValidationErrors('downtime_events.0.downtime_reason_id');
    }

    // ---- Delete ---------------------------------------------------------

    public function test_a_module_manager_without_the_tier_may_not_hard_delete_but_may_still_archive(): void
    {
        $this->manager();
        $reason = $this->reason();

        $this->deleteJson("/api/v1/production/downtime-reasons/{$reason->id}")->assertForbidden();
        $this->assertDatabaseHas('downtime_reasons', ['id' => $reason->id]);

        $this->getJson("/api/v1/production/downtime-reasons/{$reason->id}")
            ->assertOk()
            ->assertJsonPath('data.can.delete', false)
            ->assertJsonPath('data.can.archive', true);

        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive")->assertOk();
    }

    public function test_deleting_a_used_reason_is_refused_and_the_cascade_children_survive(): void
    {
        $this->owner();
        $reason = $this->reason();
        $eventId = $this->downtimeEventFor($reason);

        $response = $this->deleteJson("/api/v1/production/downtime-reasons/{$reason->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'configuration_in_use')
            ->assertJsonPath('alternative', 'archive');

        $this->assertSame(
            [['code' => 'production_downtime_events', 'label' => 'downtime event', 'count' => 1]],
            $response->json('blocking'),
        );

        // The whole reason this check is first-tier: ON DELETE CASCADE means
        // the database would have taken this row without a word.
        $this->assertDatabaseHas('downtime_reasons', ['id' => $reason->id]);
        $this->assertDatabaseHas('production_downtime_events', ['id' => $eventId, 'downtime_reason_id' => $reason->id]);
    }

    public function test_an_unused_reason_is_really_deleted_and_frees_its_code(): void
    {
        $this->owner();
        $reason = $this->reason('SPARE');

        $this->deleteJson("/api/v1/production/downtime-reasons/{$reason->id}")->assertNoContent();
        $this->assertSame(0, DowntimeReason::query()->whereKey($reason->id)->count());

        $this->postJson('/api/v1/production/downtime-reasons', [
            'code' => 'SPARE', 'description' => 'Reused code', 'planning_type' => 'unplanned',
        ])->assertCreated();
    }

    // ---- Why this table has no deleted_at -------------------------------

    public function test_the_table_has_no_deleted_at_and_archive_never_needs_one(): void
    {
        $this->assertFalse(
            Schema::hasColumn('downtime_reasons', 'deleted_at'),
            'downtime_reasons deliberately carries no deleted_at — see this class docblock',
        );

        $this->manager();
        $reason = $this->reason();

        $this->postJson("/api/v1/production/downtime-reasons/{$reason->id}/archive")->assertOk();

        // Archive landed on the flag, and the row is still physically there
        // to be reactivated: nothing needed a soft-delete column.
        $this->assertSame(0, (int) DB::table('downtime_reasons')->where('id', $reason->id)->value('is_active'));
        $this->assertDatabaseHas('downtime_reasons', ['id' => $reason->id]);
    }

    public function test_the_active_flag_branch_of_archive_is_the_one_that_runs_first(): void
    {
        // The reasoning above depends on ONE fact about the shared
        // mechanism: with an active flag present, archive() writes the flag
        // and returns before the soft-delete branch. Pinned here rather than
        // assumed, because the day that order changes, downtime_reasons DOES
        // need a deleted_at and this test is what says so.
        $reason = $this->reason();

        (new ConfigurationLifecycle(label: 'downtime reason', checks: []))->archive($reason);

        $this->assertFalse($reason->fresh()->is_active);
        $this->assertDatabaseHas('downtime_reasons', ['id' => $reason->id]);
    }

    // ---- Audit ----------------------------------------------------------

    public function test_every_lifecycle_act_is_recorded_in_the_configuration_audit(): void
    {
        $user = $this->manager();

        $reason = $this->postJson('/api/v1/production/downtime-reasons', [
            'code' => 'NO-OP', 'description' => 'No operator', 'planning_type' => 'unplanned',
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/production/downtime-reasons/{$reason['id']}", [
            'code' => 'NO-OP', 'description' => 'Operator absent', 'planning_type' => 'unplanned',
        ])->assertOk();
        $this->postJson("/api/v1/production/downtime-reasons/{$reason['id']}/archive")->assertOk();

        $trail = Activity::query()
            ->where('log_name', DowntimeReason::CONFIGURATION_LOG_NAME)
            ->where('subject_type', (new DowntimeReason)->getMorphClass())
            ->where('subject_id', $reason['id'])
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['downtime_reason.created', 'downtime_reason.updated', 'downtime_reason.updated'],
            $trail->pluck('description')->all(),
        );
        $this->assertSame([$user->id, $user->id, $user->id], $trail->pluck('causer_id')->map(fn ($id) => (int) $id)->all());

        $edit = json_decode($trail[1]->attribute_changes, true);
        $this->assertSame(['description' => 'Operator absent'], $edit['attributes']);
    }

    // ---- Fixtures -------------------------------------------------------

    private function downtimeEventFor(DowntimeReason $reason): int
    {
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);

        return DB::table('production_downtime_events')->insertGetId([
            'work_center_id' => $machine->id, 'downtime_reason_id' => $reason->id,
            'production_date' => '2026-08-01', 'minutes' => '30', 'is_planned' => false,
            'known_before_start' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function batch(): int
    {
        $shift = Shift::firstOrCreate(['name' => 'Shift A'], ['start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);
        $item = Item::firstOrCreate(['sku' => 'BTL-1'], ['name' => 'Bottle', 'uom' => 'Nos.', 'is_active' => true]);
        $warehouse = Warehouse::firstOrCreate(['code' => 'FG-TEST'], ['name' => 'FG Test', 'is_active' => true]);

        return DB::table('shift_production_entries')->insertGetId([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id, 'item_id' => $item->id,
            'warehouse_id' => $warehouse->id, 'production_date' => '2026-08-01',
            'batch_status' => 'running', 'status' => 'draft', 'scheduled_hours' => '8',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
