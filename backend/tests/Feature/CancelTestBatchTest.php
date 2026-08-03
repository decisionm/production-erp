<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Enums\ShiftScrapType;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\ShiftScrap;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cancelling a batch started by mistake.
 *
 * The feature exists because a running batch holds its machine: the start
 * guard refuses a second batch while one is in progress, so a demo run blocks
 * real production until somebody edits the database. These tests pin the two
 * halves that make it safe to expose — it frees the machine, and it refuses
 * outright the moment the batch has produced anything, because the method
 * cannot reverse what it would be orphaning.
 */
class CancelTestBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests are about the cancel guard, not the readiness gate.
        config()->set('production.readiness.enforced', false);
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0: ShiftProductionEntry, 1: WorkCenter} */
    private function runningBatch(): array
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-08-03',
            'batch_number' => '20260803-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        return [$entry, $machine];
    }

    public function test_cancelling_a_clean_running_batch_frees_the_machine_and_records_who_and_why(): void
    {
        $user = $this->actor();
        [$entry, $machine] = $this->runningBatch();

        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'Owner-created demo batch before production go-live',
        ]);

        $response->assertOk()->assertJsonPath('data.batch_status', 'cancelled');

        $entry->refresh();
        $this->assertSame(BatchStatus::Cancelled, $entry->batch_status);
        $this->assertSame($user->id, $entry->cancelled_by);
        $this->assertSame('Owner-created demo batch before production go-live', $entry->cancellation_reason);
        $this->assertNotNull($entry->cancelled_at);

        // The whole point: the machine is idle again. /active is the Shift
        // Floor's authority on machine state.
        $active = $this->getJson('/api/v1/production/shift-production-entries/active');
        $active->assertOk();
        $this->assertSame([], array_filter(
            $active->json('data'),
            fn (array $row) => $row['work_center']['id'] === $machine->id,
        ));

        // The batch itself survives — cancelled is not deleted.
        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry->id,
            'batch_number' => '20260803-M01-001',
        ]);
    }

    public function test_a_cancelled_batch_no_longer_blocks_a_new_batch_on_that_machine(): void
    {
        $this->actor();
        [$entry, $machine] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'Started on the wrong machine',
        ])->assertOk();

        // The guard reads batch_status = in_progress; a cancelled row must not
        // satisfy it.
        $stillRunning = ShiftProductionEntry::query()
            ->where('work_center_id', $machine->id)
            ->where('batch_status', BatchStatus::InProgress->value)
            ->exists();

        $this->assertFalse($stillRunning);
    }

    public function test_a_reason_is_required(): void
    {
        $this->actor();
        [$entry] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(BatchStatus::InProgress, $entry->refresh()->batch_status);
    }

    public function test_a_completed_batch_before_quality_can_be_cancelled(): void
    {
        // Widened 03-Aug: a completed batch quality has not touched is exactly
        // the case the factory needed, and its stock is reversible — the
        // amendment flow has been giving those bookings back all along. This
        // fixture has no consumption to reverse; the reversal path itself is
        // the amendment flow's and is covered by its own suite.
        $this->actor();
        [$entry] = $this->runningBatch();
        $entry->update(['batch_status' => BatchStatus::Completed, 'quantity_produced' => '100']);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'Entered against the wrong machine',
        ])->assertOk();

        $entry->refresh();
        $this->assertSame(BatchStatus::Cancelled, $entry->batch_status);
        $this->assertTrue($entry->config_snapshot['cancellation']['stock_reversed']);
        $this->assertSame('completed', $entry->config_snapshot['cancellation']['previous_batch_status']);
    }

    public function test_a_completed_batch_after_quality_cannot_be_cancelled(): void
    {
        // The line that must never move: once quality has counted the bottles
        // the figures are no longer the floor's to withdraw.
        $user = $this->actor();
        [$entry] = $this->runningBatch();
        $entry->update([
            'batch_status' => BatchStatus::Completed,
            'quality_checked_at' => now(),
            'quality_checked_by' => $user->id,
        ]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'trying to erase a checked shift',
        ])->assertStatus(422);

        $this->assertSame(BatchStatus::Completed, $entry->refresh()->batch_status);
    }

    public function test_a_cancelled_batch_leaves_the_default_entry_list_but_stays_in_history(): void
    {
        // Entry #40 kept appearing after it was withdrawn: paginate() applied a
        // batch_status predicate only inside its status branch, so an
        // unfiltered read returned cancelled rows.
        $this->actor();
        [$entry] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'demo batch',
        ])->assertOk();

        $listed = $this->getJson('/api/v1/production/shift-production-entries')->json('data');
        $this->assertNotContains($entry->id, array_column($listed, 'id'));

        // Never deleted — the row and its audit are still there.
        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $entry->id,
            'batch_status' => 'cancelled',
            'cancellation_reason' => 'demo batch',
        ]);
    }

    public function test_a_batch_with_recorded_scrap_cannot_be_cancelled(): void
    {
        $this->actor();
        [$entry] = $this->runningBatch();

        ShiftScrap::create([
            'shift_production_entry_id' => $entry->id,
            'type' => ShiftScrapType::Lumps,
            'quantity_kg' => '1.5',
        ]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'it produced something, so this must refuse',
        ])->assertStatus(422);

        $this->assertSame(BatchStatus::InProgress, $entry->refresh()->batch_status);
    }

    public function test_a_batch_past_approval_cannot_be_cancelled(): void
    {
        $this->actor();
        [$entry] = $this->runningBatch();
        $entry->update(['status' => ShiftProductionEntryStatus::PmApproved]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'a manager already signed this',
        ])->assertStatus(422);

        $this->assertSame(BatchStatus::InProgress, $entry->refresh()->batch_status);
    }

    public function test_a_quality_checked_batch_cannot_be_cancelled(): void
    {
        $user = $this->actor();
        [$entry] = $this->runningBatch();
        $entry->update(['quality_checked_at' => now(), 'quality_checked_by' => $user->id]);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cancel", [
            'reason' => 'bottles were already counted',
        ])->assertStatus(422);

        $this->assertSame(BatchStatus::InProgress, $entry->refresh()->batch_status);
    }
}
