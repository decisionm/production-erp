<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Production\Services\WorkCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 of the shift redesign: machines list in business sequence
 * (Machine 10 after Machine 9, never after Machine 1), and batch numbers
 * are minted automatically at Start Batch — {Ymd}-M{machine}-{seq},
 * unique, never typed by the supervisor in the normal path.
 */
class MachineOrderAndAutoBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises machine ordering and batch-number minting, not the production-readiness gate.
        // Its fixtures are deliberately minimal items (no weight, no Tally
        // identity), which the fail-closed gate would refuse at Start Batch.
        // Turning enforcement off here keeps each test on its own subject;
        // the gate itself is covered by ProductReadinessGateTest.
        config()->set('production.readiness.enforced', false);
    }

    public function test_machines_list_in_business_sequence_not_name_order(): void
    {
        WorkCenter::create(['code' => 'MC-10', 'name' => 'Machine 10', 'display_sequence' => 10]);
        WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'display_sequence' => 2]);
        WorkCenter::create(['code' => 'PACK-01', 'name' => 'Packing Station']);

        $names = app(WorkCenterService::class)->paginate(20)->pluck('name')->all();

        // Name order would give 1, 10, 2 — business sequence must win, and
        // rows without a sequence sort last.
        $this->assertSame(['Machine 1', 'Machine 2', 'Machine 10', 'Packing Station'], $names);
    }

    public function test_start_batch_mints_a_unique_readable_batch_number(): void
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-03', 'name' => 'Machine 3', 'display_sequence' => 3]);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);
        $user = User::factory()->create();
        $service = app(ShiftProductionEntryService::class);

        $base = [
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
        ];

        $first = $service->startBatch($base, $user->id);
        $this->assertSame('20260728-M03-001', $first->batch_number);

        // Same machine, same date: sequence increments (complete the first
        // so the machine frees up).
        $service->completeBatch($first, ['quantity_produced' => '100'], $user->id);
        $second = $service->startBatch($base, $user->id);
        $this->assertSame('20260728-M03-002', $second->batch_number);

        // A different machine runs its own sequence.
        $other = WorkCenter::create(['code' => 'MC-07', 'name' => 'Machine 7', 'display_sequence' => 7]);
        $third = $service->startBatch(['work_center_id' => $other->id] + $base, $user->id);
        $this->assertSame('20260728-M07-001', $third->batch_number);
    }

    public function test_completion_without_a_batch_number_keeps_the_minted_one(): void
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-05', 'name' => 'Machine 5', 'display_sequence' => 5]);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);
        $user = User::factory()->create();
        $service = app(ShiftProductionEntryService::class);

        $entry = $service->startBatch([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
        ], $user->id);

        $minted = $entry->batch_number;
        $completed = $service->completeBatch($entry, ['quantity_produced' => '500'], $user->id);
        $this->assertSame($minted, $completed->batch_number, 'Empty completion must not wipe the minted number');

        // The exception path still allows an explicit override.
        $entry2 = $service->startBatch([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
        ], $user->id);
        $overridden = $service->completeBatch($entry2, ['quantity_produced' => '500', 'batch_number' => 'MANUAL-EXC-1'], $user->id);
        $this->assertSame('MANUAL-EXC-1', $overridden->batch_number);
    }
}
