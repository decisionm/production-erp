<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Exceptions\MachineBusyException;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Shift Floor's machine state must match the backend's global
 * one-in-progress-per-machine guard. A batch left running from an earlier
 * shift/date (or beyond the paginated entry list) used to leave the machine
 * looking idle while Start Batch was correctly refused — the /active
 * endpoint closes that gap by returning every running batch, unscoped and
 * unpaginated.
 */
class ActiveBatchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises global machine-state visibility, not the production-readiness gate.
        // Its fixtures are deliberately minimal items (no weight, no Tally
        // identity), which the fail-closed gate would refuse at Start Batch.
        // Turning enforcement off here keeps each test on its own subject;
        // the gate itself is covered by ProductReadinessGateTest.
        config()->set('production.readiness.enforced', false);
    }

    private function viewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo('production.view');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_active_endpoint_returns_a_running_batch_from_a_past_shift_and_date(): void
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);

        // A batch left running yesterday — the exact orphan that reads idle.
        $running = ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-27',
            'batch_status' => BatchStatus::InProgress,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        // A completed batch on another machine must NOT appear.
        $other = WorkCenter::create(['code' => 'MC-02', 'name' => 'Machine 2', 'display_sequence' => 2]);
        ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $other->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-27',
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '100', 'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);

        $this->viewer();

        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $running->id)
            ->assertJsonPath('data.0.work_center.id', $machine->id)
            ->assertJsonPath('data.0.batch_status', 'in_progress');
    }

    public function test_every_running_machine_is_returned_never_capped_by_pagination(): void
    {
        // 25 machines each running a batch — more than the 20-row default
        // page. A running batch must never be hidden by paging, so all 25
        // must come back. (Guards against a regression to ->paginate().)
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);

        for ($n = 1; $n <= 25; $n++) {
            $machine = WorkCenter::create(['code' => "MC-{$n}", 'name' => "Machine {$n}", 'display_sequence' => $n]);
            ShiftProductionEntry::create([
                'shift_id' => $shift->id, 'work_center_id' => $machine->id,
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
                'production_date' => '2026-07-27',
                'batch_status' => BatchStatus::InProgress,
                'quantity_scrap' => '0',
                'status' => ShiftProductionEntryStatus::Pending,
            ]);
        }

        $this->viewer();

        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonCount(25, 'data');
    }

    public function test_the_endpoint_surfaces_exactly_what_the_start_guard_blocks(): void
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'display_sequence' => 1]);
        $warehouse = Warehouse::create(['code' => 'WH-FG', 'name' => 'FG Store']);
        $item = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'pcs']);
        $service = app(ShiftProductionEntryService::class);

        // Yesterday's running batch.
        $service->startBatch([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-27',
        ], User::factory()->create()->id);

        // The endpoint shows the machine as running...
        $this->viewer();
        $this->getJson('/api/v1/production/shift-production-entries/active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_center.id', $machine->id);

        // ...and the guard still refuses a second batch on it. The refusal
        // now names the running batch (MachineBusyException) instead of a
        // bare transition error — the machine looking idle on today's screen
        // while the backend considers it running is exactly this case, and
        // "here is what is on it" is the answer the supervisor needs.
        try {
            $service->startBatch([
                'shift_id' => $shift->id, 'work_center_id' => $machine->id,
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
                'production_date' => '2026-07-28',
            ], User::factory()->create()->id);

            $this->fail('Expected the start guard to refuse a second batch.');
        } catch (MachineBusyException $e) {
            $this->assertSame('machine_busy', $e->errorCode());
            $this->assertSame('Machine 1', $e->payload()['active_batch']['work_center']);
            $this->assertStringStartsWith('2026-07-27', (string) $e->payload()['active_batch']['production_date']);
        }

        $this->assertDatabaseCount('shift_production_entries', 1);
    }
}
