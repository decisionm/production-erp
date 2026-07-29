<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 6 shift continuity: handover completes the outgoing segment and
 * opens a child entry that INHERITS the run's identity (batch number never
 * re-minted, mold-standards snapshot never re-read from the item master),
 * and the day-bin closing carries into the next segment as its opening via
 * day_bin_movements.
 */
class SegmentHandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises cross-shift handover, not the production-readiness gate.
        // Its fixtures are deliberately minimal items (no weight, no Tally
        // identity), which the fail-closed gate would refuse at Start Batch.
        // Turning enforcement off here keeps each test on its own subject;
        // the gate itself is covered by ProductReadinessGateTest.
        config()->set('production.readiness.enforced', false);
    }

    private function actingAsUserWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function enableTraceability(): void
    {
        config(['production.traceability_enabled' => true]);
    }

    /**
     * @return array{0: ShiftProductionEntry, 1: Shift, 2: Item, 3: Item, 4: WorkCenter, 5: Warehouse}
     */
    private function runningBatch(?int $createdBy = null): array
    {
        $morning = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $evening = Shift::create(['name' => 'Evening', 'start_time' => '14:00', 'end_time' => '22:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'Store']);
        $bottle = Item::create([
            'sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS',
            'nominal_weight_grams' => '8', 'nos_per_box' => 840,
            'standard_cycle_time' => '10.6', 'standard_cavities' => 5,
        ]);
        $resin = Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']);

        $entry = app(ShiftProductionEntryService::class)->startBatch([
            'shift_id' => $morning->id,
            'work_center_id' => $machine->id,
            'item_id' => $bottle->id,
            'warehouse_id' => $warehouse->id,
        ], $createdBy);

        return [$entry, $evening, $bottle, $resin, $machine, $warehouse];
    }

    public function test_with_the_flag_off_the_handover_route_is_a_404(): void
    {
        $this->actingAsUserWithPermissions('production.manage');
        [$entry, $evening] = $this->runningBatch();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'completion' => ['quantity_produced' => '5880'],
        ])->assertNotFound();

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
        $this->assertNull(ShiftProductionEntry::query()->whereNotNull('parent_entry_id')->first());
    }

    public function test_handover_completes_the_parent_and_child_inherits_identity_and_snapshot(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.manage');
        [$entry, $evening, $bottle] = $this->runningBatch($user->id);
        $mintedBatchNumber = $entry->batch_number;

        // The item master changes mid-run — the run must keep the standard
        // it started against (snapshot inheritance, never re-read).
        $bottle->update(['standard_cycle_time' => '99', 'standard_cavities' => 9]);

        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'completion' => [
                'quantity_produced' => '5880',
                'no_of_box' => 7,
                'running_hours' => '8',
            ],
        ])->assertSuccessful();

        // Parent: completed and submitted into the approval chain.
        $parent = $entry->fresh();
        $this->assertSame(BatchStatus::Completed, $parent->batch_status);
        $this->assertSame(ShiftProductionEntryStatus::Pending, $parent->status);
        // bccomp: SQLite hands the uncast decimal back as an int, MySQL as
        // a 4dp string — compare numerically at 4dp.
        $this->assertSame(0, bccomp((string) $parent->quantity_produced, '5880', 4));

        // Child: a new in-progress segment of the SAME run.
        $child = ShiftProductionEntry::query()->find($response->json('data.id'));
        $this->assertNotSame($parent->id, $child->id);
        $this->assertSame($parent->id, $child->parent_entry_id);
        $this->assertSame($parent->id, $response->json('data.parent_entry_id'));
        $this->assertSame(BatchStatus::InProgress, $child->batch_status);

        // Batch number inherited, NOT re-minted.
        $this->assertSame($mintedBatchNumber, $child->batch_number);

        // Item / machine / warehouse carry over; shift is the incoming one.
        $this->assertSame($parent->item_id, $child->item_id);
        $this->assertSame($parent->work_center_id, $child->work_center_id);
        $this->assertSame($parent->warehouse_id, $child->warehouse_id);
        $this->assertSame($evening->id, $child->shift_id);

        // Mold standards: the Start Batch snapshot, not the altered master.
        $this->assertSame('10.60', $child->standard_cycle_time);
        $this->assertSame(5, $child->standard_cavities);
    }

    public function test_day_bin_closing_carries_into_the_child_segment_as_its_opening(): void
    {
        // Segment 1: opening 0, loaded 25 + 7.5, returned 1.3, closing 4.2
        //   → consumed 0 + 32.5 − 4.2 − 1.3 = 27.0000.
        // Segment 2 (after handover): opening 4.2 (carried), loaded 18.8,
        //   closing 2.0 → consumed 4.2 + 18.8 − 2.0 − 0 = 21.0000.
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$entry, $evening, $bottle, $resin, $machine] = $this->runningBatch($user->id);

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();

        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag1->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag2->barcode, 'work_center_id' => $machine->id,
            'quantity_kg' => '7.5', 'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '1.3', 'material_bag_id' => $bag2->id,
            'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();

        // Handover records the closing count against the OUTGOING segment.
        $response = $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '4.2']],
            'completion' => ['quantity_produced' => '5880'],
        ])->assertSuccessful();
        $child = ShiftProductionEntry::query()->find($response->json('data.id'));

        $ledger = app(DayBinLedgerService::class);

        // Parent segment consumption — the full design formula.
        $this->assertSame([
            'opening_kg' => '0.0000',
            'loaded_kg' => '32.5000',
            'returned_kg' => '1.3000',
            'closing_kg' => '4.2000',
            'consumed_kg' => '27.0000',
        ], $ledger->consumptionFor($entry->fresh(), $resin->id));

        // The child's opening IS the parent's closing — carried via
        // day_bin_movements, no re-entry.
        $this->assertSame('4.2000', $ledger->openingFor($child, $resin->id));

        // Segment 2: load the rest of bag 2 (18.8 kg), close at 2.0.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag2->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $child->id,
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '2.0', 'shift_production_entry_id' => $child->id,
        ])->assertSuccessful();

        $this->getJson('/api/v1/production/day-bin/consumption?shift_production_entry_id='
            .$child->id.'&item_id='.$resin->id)
            ->assertSuccessful()
            ->assertJson(['data' => [
                'opening_kg' => '4.2000',
                'loaded_kg' => '18.8000',
                'returned_kg' => '0.0000',
                'closing_kg' => '2.0000',
                'consumed_kg' => '21.0000',
            ]]);
    }

    public function test_handover_requires_an_in_progress_batch(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('production.manage');
        [$entry, $evening] = $this->runningBatch();

        app(ShiftProductionEntryService::class)->completeBatch($entry, ['quantity_produced' => '100'], null);

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'completion' => ['quantity_produced' => '100'],
        ])->assertStatus(422);

        $this->assertNull(ShiftProductionEntry::query()->whereNotNull('parent_entry_id')->first());
    }

    public function test_a_closing_count_beyond_the_segment_window_aborts_the_whole_handover(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$entry, $evening, $bottle, $resin, $machine] = $this->runningBatch($user->id);

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $lot->bags->first()->barcode, 'work_center_id' => $machine->id,
            'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();

        // Claiming 40 kg remain when only 25 were ever loaded: refused,
        // and the batch must NOT have been completed by the failed attempt.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/handover", [
            'shift_id' => $evening->id,
            'closing_day_bin' => [['item_id' => $resin->id, 'quantity_kg' => '40']],
            'completion' => ['quantity_produced' => '5880'],
        ])->assertStatus(422);

        $this->assertSame(BatchStatus::InProgress, $entry->fresh()->batch_status);
        $this->assertNull(ShiftProductionEntry::query()->whereNotNull('parent_entry_id')->first());
    }
}
