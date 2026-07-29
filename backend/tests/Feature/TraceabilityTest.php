<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\DayBinMovement;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 6 lot/barcode traceability: flag gating (feature invisible when
 * off), lot→bag fan-out with unique barcodes, day-bin load/return/count
 * guards, the FIFO policy with its override permission, and the design
 * doc's consumption formula
 * (consumed = opening + loaded − closing − returned) on real fixtures.
 */
class TraceabilityTest extends TestCase
{
    use RefreshDatabase;

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
     * @return array{0: Item, 1: Warehouse, 2: WorkCenter}
     */
    private function masters(): array
    {
        return [
            Item::create(['sku' => 'RM-PET', 'name' => 'PET Resin', 'uom' => 'Kgs']),
            Warehouse::create(['code' => 'RM-STORE', 'name' => 'RM Store']),
            WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']),
        ];
    }

    private function inProgressEntry(Item $producedItem, WorkCenter $machine, Warehouse $warehouse): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $producedItem->id,
            'warehouse_id' => $warehouse->id,
            'production_date' => '2026-07-28',
            'batch_number' => '20260728-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    public function test_with_the_flag_off_every_traceability_route_is_a_404(): void
    {
        config(['production.traceability_enabled' => false]);
        $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();

        // An explicitly disabled deployment must make the feature
        // indistinguishable from not existing.
        $this->getJson('/api/v1/inventory/material-lots')->assertNotFound();
        $this->postJson('/api/v1/inventory/material-lots', [
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'total_received_kg' => '50',
        ])->assertNotFound();
        $this->getJson('/api/v1/inventory/material-bags')->assertNotFound();
        $this->getJson('/api/v1/inventory/material-bags/pick-list?item_id='.$resin->id)->assertNotFound();
        $this->getJson('/api/v1/production/day-bin/movements')->assertNotFound();
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => 'X', 'work_center_id' => $machine->id,
        ])->assertNotFound();
        $this->postJson('/api/v1/production/day-bin/return', [])->assertNotFound();
        $this->postJson('/api/v1/production/day-bin/count', [])->assertNotFound();
        $this->getJson('/api/v1/production/day-bin/consumption?shift_production_entry_id=1&item_id=1')->assertNotFound();

        $this->assertSame(0, MaterialLot::count());
    }

    public function test_creating_a_lot_fans_out_n_bags_with_generated_unique_barcodes(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('inventory.manage');
        [$resin] = $this->masters();

        $response = $this->postJson('/api/v1/inventory/material-lots', [
            'item_id' => $resin->id,
            'supplier_lot_no' => 'RIL-2026-0714',
            'received_date' => '2026-07-20',
            'bag_count' => 4,
            'bag_weight_kg' => '25',
            'total_received_kg' => '100',
        ])->assertSuccessful();

        $lotId = $response->json('data.id');
        $bags = MaterialBag::query()->where('material_lot_id', $lotId)->orderBy('id')->get();

        $this->assertCount(4, $bags);
        $this->assertSame(
            ["LOT{$lotId}-B1", "LOT{$lotId}-B2", "LOT{$lotId}-B3", "LOT{$lotId}-B4"],
            $bags->pluck('barcode')->all(),
        );
        $this->assertCount(4, $bags->pluck('barcode')->unique());
        foreach ($bags as $bag) {
            $this->assertSame('25.0000', $bag->original_kg);
            $this->assertSame('25.0000', $bag->remaining_kg);
            $this->assertSame(MaterialBagStatus::InStore, $bag->status);
        }
    }

    public function test_supplier_barcodes_are_used_and_a_duplicate_is_rejected(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('inventory.manage');
        [$resin] = $this->masters();

        $this->postJson('/api/v1/inventory/material-lots', [
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
            'barcodes' => ['SUP-0001', 'SUP-0002'],
        ])->assertSuccessful();

        $this->assertNotNull(MaterialBag::query()->where('barcode', 'SUP-0001')->first());

        // Same barcode again — a duplicate scan must be a clear error,
        // never a silent double-count.
        $this->postJson('/api/v1/inventory/material-lots', [
            'item_id' => $resin->id, 'received_date' => '2026-07-21',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
            'barcodes' => ['SUP-0002', 'SUP-0003'],
        ])->assertStatus(422)->assertJsonValidationErrors(['barcodes.0']);

        // Duplicate within one request is caught too.
        $this->postJson('/api/v1/inventory/material-lots', [
            'item_id' => $resin->id, 'received_date' => '2026-07-21',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
            'barcodes' => ['SUP-0009', 'SUP-0009'],
        ])->assertStatus(422);

        $this->assertSame(2, MaterialBag::count(), 'failed lots must not leave partial bags behind');
    }

    public function test_the_unique_column_is_the_hard_guard_beneath_validation(): void
    {
        $this->enableTraceability();
        [$resin] = $this->masters();
        $service = app(TraceabilityService::class);

        $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'total_received_kg' => '25', 'barcodes' => ['DUP-1'],
        ], null);

        // Bypassing request validation entirely still cannot double-create
        // a barcode — the constraint throws.
        $this->expectException(QueryException::class);
        $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-21',
            'bag_count' => 1, 'total_received_kg' => '25', 'barcodes' => ['DUP-1'],
        ], null);
    }

    public function test_full_and_partial_loads_decrement_the_bag_and_over_load_is_rejected(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 2, 'bag_weight_kg' => '25', 'total_received_kg' => '50',
            'warehouse_id' => $warehouse->id,
        ], $user->id);
        [$bag1, $bag2] = $lot->bags->sortBy('id')->values();

        // Over-load: 30 kg out of a 25 kg bag can never happen.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag1->barcode, 'work_center_id' => $machine->id, 'quantity_kg' => '30',
        ])->assertStatus(422);
        $this->assertSame('25.0000', $bag1->fresh()->remaining_kg);

        // Full-bag scan (no quantity): whole remaining_kg goes to the bin —
        // the emptied bag is Consumed, tracked at the machine it went to.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag1->barcode, 'work_center_id' => $machine->id,
        ])->assertSuccessful();
        $bag1 = $bag1->fresh();
        $this->assertSame('0.0000', $bag1->remaining_kg);
        $this->assertSame(MaterialBagStatus::Consumed, $bag1->status);
        $this->assertSame($machine->id, $bag1->day_bin_work_center_id);

        // Partial (weighed) load: bag decrements and stays in the store.
        $this->postJson('/api/v1/production/day-bin/load', [
            'material_bag_id' => $bag2->id, 'work_center_id' => $machine->id, 'quantity_kg' => '7.5',
        ])->assertSuccessful();
        $bag2 = $bag2->fresh();
        $this->assertSame('17.5000', $bag2->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $bag2->status);

        $this->assertSame(2, DayBinMovement::query()->where('type', 'load')->count());
        // An exhausted bag cannot be loaded again.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $bag1->barcode, 'work_center_id' => $machine->id,
        ])->assertStatus(422);
    }

    public function test_consumption_formula_on_the_multi_bag_partial_fixture(): void
    {
        // Fixture: fresh run (opening 0). Load 25.0000 (full bag) +
        // 7.5000 (weighed partial) = 32.5000; return 1.3000 to the bag;
        // closing count 4.2000.
        // consumed = 0 + 32.5 − 4.2 − 1.3 = 27.0000.
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();
        $bottle = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle', 'uom' => 'NOS']);
        $entry = $this->inProgressEntry($bottle, $machine, $warehouse);

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
        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id,
            'quantity_kg' => '4.2', 'shift_production_entry_id' => $entry->id,
        ])->assertSuccessful();

        // Returned kg flowed back into the bag.
        $this->assertSame('18.8000', $bag2->fresh()->remaining_kg);

        $this->getJson('/api/v1/production/day-bin/consumption?shift_production_entry_id='
            .$entry->id.'&item_id='.$resin->id)
            ->assertSuccessful()
            ->assertJson(['data' => [
                'opening_kg' => '0.0000',
                'loaded_kg' => '32.5000',
                'returned_kg' => '1.3000',
                'closing_kg' => '4.2000',
                'consumed_kg' => '27.0000',
            ]]);
    }

    public function test_fifo_pick_list_is_oldest_first_and_override_needs_the_permission(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();
        $service = app(TraceabilityService::class);

        $older = $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);
        $newer = $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-22',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);
        $olderBag = $older->bags->first();
        $newerBag = $newer->bags->first();

        // Pick list: oldest received_date first.
        $this->getJson('/api/v1/inventory/material-bags/pick-list?item_id='.$resin->id)
            ->assertSuccessful()
            ->assertJsonPath('data.0.barcode', $olderBag->barcode)
            ->assertJsonPath('data.1.barcode', $newerBag->barcode);

        // Scanning the newer bag while the older stays open: refused.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $newerBag->barcode, 'work_center_id' => $machine->id,
        ])->assertStatus(422);

        // Even with the permission, the override must be explicit.
        $user->givePermissionTo(Permission::findOrCreate('production.override-fifo', 'web'));
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $newerBag->barcode, 'work_center_id' => $machine->id,
        ])->assertStatus(422);

        // Permission + explicit override: allowed, and recorded_by says who.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $newerBag->barcode, 'work_center_id' => $machine->id, 'override_fifo' => true,
        ])->assertSuccessful();
        $movement = DayBinMovement::query()->latest('id')->first();
        $this->assertSame($newerBag->id, $movement->material_bag_id);
        $this->assertSame($user->id, $movement->getRawOriginal('recorded_by'));

        // The FIFO head itself always loads without any permission.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $olderBag->barcode, 'work_center_id' => $machine->id,
        ])->assertSuccessful();
    }

    public function test_override_without_the_permission_is_refused_and_fifo_can_be_configured_off(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();
        $service = app(TraceabilityService::class);

        $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);
        $newer = $service->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-22',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);

        // override_fifo without holding the permission: still refused.
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $newer->bags->first()->barcode,
            'work_center_id' => $machine->id, 'override_fifo' => true,
        ])->assertStatus(422);

        // Vincent Q3 absorbed by config: FIFO as preference, not mandate.
        config(['production.traceability.fifo_enforced' => false]);
        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $newer->bags->first()->barcode, 'work_center_id' => $machine->id,
        ])->assertSuccessful();
    }

    public function test_no_negative_balances_returns_and_counts_are_capped(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('inventory.manage', 'production.manage');
        [$resin, $warehouse, $machine] = $this->masters();

        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id, 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '25', 'total_received_kg' => '25',
        ], $user->id);

        $this->postJson('/api/v1/production/day-bin/load', [
            'barcode' => $lot->bags->first()->barcode, 'work_center_id' => $machine->id, 'quantity_kg' => '5',
        ])->assertSuccessful();

        // Returning 10 from a bin holding 5: refused.
        $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id, 'quantity_kg' => '10',
        ])->assertStatus(422);

        // Counting 50 in a bin that only ever received 5: refused.
        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id, 'quantity_kg' => '50',
        ])->assertStatus(422);

        // The honest figures pass.
        $this->postJson('/api/v1/production/day-bin/return', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id, 'quantity_kg' => '2',
        ])->assertSuccessful();
        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id, 'quantity_kg' => '3',
        ])->assertSuccessful();
    }
}
