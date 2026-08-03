<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE COMMON INPUT IS AN OPERATIONAL RECORD, NOT AN ACCOUNTING ONE.
 *
 * A bag scan says material was poured into the one common input point. It does
 * not say the material left the company, left the accounting godown, or was
 * consumed — none of which have happened. Company stock changes exactly once,
 * later, when an approved batch's consumption is booked, and that same quantity
 * is what reaches Tally.
 *
 * Loading used to transfer kilograms from a store warehouse into a day-bin
 * warehouse, which described something real while those were two different
 * places. Under one accounting godown they are the same place, so the transfer
 * described a move that never happened — and would have decremented and
 * re-incremented one balance on every scan.
 */
class CommonInputLoadIsNotStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $godown;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['production.traceability_enabled' => true]);

        // ONE accounting godown, which is the real configuration: the store and
        // the day bin are the same warehouse.
        $this->godown = Warehouse::create(['code' => 'SWA', 'name' => 'SWAASHPET POLYMERS PVT LTD']);
        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'Pet Resin', 'uom' => 'Kgs.']);

        StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->godown->id,
            'quantity' => '500.0000',
            'average_cost' => '85.0000',
        ]);

        app(FactoryDayBinService::class)->setWarehouseId($this->godown->id);
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.view', 'web');
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo(['production.view', 'production.manage']);
        $this->actingAs($user);

        return $user;
    }

    private function bag(string $barcode = 'BAG-1', string $kg = '25.0000'): MaterialBag
    {
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-08-01',
            'bag_count' => 1,
            'total_received_kg' => $kg,
        ]);

        return MaterialBag::create([
            'material_lot_id' => $lot->id,
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->godown->id,
        ]);
    }

    private function runningBatch(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);
        $product = Item::create(['sku' => 'BTL', 'name' => 'Bottle', 'uom' => 'Nos.']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id, 'work_center_id' => $machine->id,
            'item_id' => $product->id, 'warehouse_id' => $this->godown->id,
            'production_date' => '2026-08-03',
            'batch_number' => '20260803-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_scrap' => '0',
            'status' => ShiftProductionEntryStatus::Pending,
        ]);
    }

    public function test_a_full_bag_scan_records_the_load_and_moves_no_stock(): void
    {
        $this->actor();
        $bag = $this->bag();

        $this->postJson('/api/v1/production/day-bin/load-bag', ['barcode' => 'BAG-1'])->assertOk();

        // The operational record exists in full.
        $movement = DayBinMovement::query()->sole();
        $this->assertSame('25.0000', $movement->quantity_kg);
        $this->assertSame($bag->id, $movement->material_bag_id);
        $this->assertSame('0.0000', $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::Consumed, $bag->fresh()->status);

        // And company stock is exactly where it was.
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('500.0000', StockBalance::query()
            ->where('item_id', $this->resin->id)->where('warehouse_id', $this->godown->id)->value('quantity'));
    }

    public function test_a_partial_pour_records_only_the_weighed_kg_and_still_moves_no_stock(): void
    {
        $this->actor();
        $bag = $this->bag();

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'BAG-1',
            'quantity_kg' => '10.5',
        ])->assertOk();

        $this->assertSame('10.5000', DayBinMovement::query()->sole()->quantity_kg);
        // The bag keeps its remainder and stays in the store — it is still
        // physically there holding 14.5 kg.
        $this->assertSame('14.5000', $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $bag->fresh()->status);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame('500.0000', StockBalance::query()
            ->where('item_id', $this->resin->id)->where('warehouse_id', $this->godown->id)->value('quantity'));
    }

    public function test_a_common_input_scan_carries_no_batch_and_no_machine(): void
    {
        // THE FLOW THIS PROTECTS. One common resin input; a bag is never
        // assigned to a machine and never to a batch — not as an assignment and
        // not as an intent. Several runs draw from the pool afterwards, and
        // cost comes from the pool's weighted average, so any per-bag claim
        // about which run used it would be invented.
        //
        // An intended-batch field was briefly added and withdrawn the same day.
        // This test is what stops it coming back: it fails the moment a scan
        // starts carrying a run.
        $this->actor();
        $this->bag();
        $batch = $this->runningBatch();

        // Sent deliberately, the way an old client or a re-added field would.
        // It must reach nothing.
        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'BAG-1',
            'work_center_id' => $batch->work_center_id,
            'intended_shift_production_entry_id' => $batch->id,
        ])->assertOk();

        $movement = DayBinMovement::query()->sole();

        $this->assertNull($movement->work_center_id, 'A common-input scan must name no machine.');
        $this->assertNull($movement->shift_production_entry_id, 'A common-input scan must name no batch.');
        $this->assertNull(
            $movement->getAttributes()['intended_shift_production_entry_id'] ?? null,
            'The retired intended-batch column must stay null — nothing may write a run onto a scan.',
        );

        // And the bag itself still belongs to no machine.
        $this->assertNull($movement->materialBag->day_bin_work_center_id);
    }

    public function test_a_transfer_to_the_same_warehouse_is_refused(): void
    {
        // Defensive backstop. Under one godown every "from" and "to" in this
        // factory is the same warehouse; a caller that slipped through would
        // mint a paired movement for a move that never happened, and every
        // report counting transfers would double-count.
        $this->expectException(ValidationException::class);

        app(StockMovementService::class)->recordTransfer(
            itemId: $this->resin->id,
            fromWarehouseId: $this->godown->id,
            toWarehouseId: $this->godown->id,
            quantity: '5.0000',
        );
    }

    public function test_a_transfer_between_two_warehouses_still_works(): void
    {
        $other = Warehouse::create(['code' => 'OTHER', 'name' => 'Other Store']);

        app(StockMovementService::class)->recordTransfer(
            itemId: $this->resin->id,
            fromWarehouseId: $this->godown->id,
            toWarehouseId: $other->id,
            quantity: '5.0000',
        );

        $this->assertSame('5.0000', StockBalance::query()
            ->where('item_id', $this->resin->id)->where('warehouse_id', $other->id)->value('quantity'));
    }
}
