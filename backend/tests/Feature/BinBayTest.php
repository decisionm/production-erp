<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\RecordsDayBinHistory;
use Tests\TestCase;

/**
 * The bin-bay AVAILABILITY read: the machine-scoped ledger balance (with its
 * source-lot layers) and a run's recipe priced against it — the figures the
 * Start Batch dialog quotes.
 *
 * READ ONLY. The Bin Bay page, bin-bay/load and bin-bay/history are gone
 * (DEC-20260807-006) — the floor's one load flow is the common input's bag
 * scan. These tests seed the ledger through TraceabilityService directly
 * (the day-bin/load endpoint's own writer, covered in TraceabilityTest);
 * nothing here consumes stock or posts a voucher, and no test asserts that
 * it does.
 */
class BinBayTest extends TestCase
{
    use RecordsDayBinHistory, RefreshDatabase;

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

    /** One 100 kg bag of the given material, sitting in the store. */
    private function oneHundredKgBag(Item $resin, Warehouse $warehouse, ?int $userId): MaterialBag
    {
        $lot = app(TraceabilityService::class)->createLot([
            'item_id' => $resin->id,
            'supplier_lot_no' => 'RIL-2026-0714',
            'received_date' => '2026-07-20',
            'bag_count' => 1,
            'bag_weight_kg' => '100',
            'total_received_kg' => '100',
            'warehouse_id' => $warehouse->id,
        ], $userId);

        return $lot->bags->first();
    }

    /** Seed the machine ledger the way day-bin/load's own writer does. */
    private function loadIntoMachine(WorkCenter $machine, MaterialBag $bag, ?string $quantityKg, ?int $userId): void
    {
        app(TraceabilityService::class)->loadBagToDayBin(array_filter([
            'work_center_id' => $machine->id,
            'barcode' => $bag->barcode,
            'quantity_kg' => $quantityKg,
        ], fn ($value) => $value !== null), $userId);
    }

    public function test_with_the_flag_off_the_availability_read_is_a_404(): void
    {
        config(['production.traceability_enabled' => false]);
        $this->actingAsUserWithPermissions('production.manage');
        [$resin, , $machine] = $this->masters();

        // An explicitly disabled deployment has no bin-bay surface.
        $this->getJson('/api/v1/production/bin-bay/availability?work_center_id='.$machine->id.'&item_id='.$resin->id)
            ->assertNotFound();
    }

    public function test_availability_reports_the_ledger_balance_and_the_source_lot_layer_behind_it(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $this->loadIntoMachine($machine, $bag, '5', $user->id);

        $bin = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id.'&item_id='.$resin->id,
        )->assertSuccessful()->json('data.bin');

        $this->assertSame('5.0000', $bin['available_kg']);
        $this->assertSame('5.0000', $bin['loaded_kg']);
        $this->assertSame('0.0000', $bin['unattributed_kg']);
        $this->assertSame(['id' => $resin->id, 'name' => 'PET Resin', 'sku' => 'RM-PET', 'uom' => 'Kgs'], $bin['item']);
        $this->assertCount(1, $bin['layers']);

        $layer = $bin['layers'][0];
        $this->assertSame($bag->id, $layer['material_bag_id']);
        $this->assertSame($bag->barcode, $layer['barcode']);
        $this->assertSame('5.0000', $layer['loaded_kg']);
        // Nothing has left the bin yet, so the whole layer is still in it.
        $this->assertSame('5.0000', $layer['in_bin_kg']);
        $this->assertSame('RIL-2026-0714', $layer['lot']['supplier_lot_no']);
        $this->assertSame('2026-07-20', $layer['lot']['received_date']);
    }

    public function test_the_oldest_layer_is_drawn_down_first_once_the_bin_is_counted_lower(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.manage', 'production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        // Two lots so the second bag is not a FIFO violation when loaded
        // after the first: the pick list heads on received_date.
        $trace = app(TraceabilityService::class);
        $older = $trace->createLot([
            'item_id' => $resin->id, 'supplier_lot_no' => 'OLD', 'received_date' => '2026-07-01',
            'bag_count' => 1, 'bag_weight_kg' => '10', 'total_received_kg' => '10',
            'warehouse_id' => $warehouse->id,
        ], $user->id)->bags->first();
        $newer = $trace->createLot([
            'item_id' => $resin->id, 'supplier_lot_no' => 'NEW', 'received_date' => '2026-07-20',
            'bag_count' => 1, 'bag_weight_kg' => '10', 'total_received_kg' => '10',
            'warehouse_id' => $warehouse->id,
        ], $user->id)->bags->first();

        foreach ([$older, $newer] as $bag) {
            $this->loadIntoMachine($machine, $bag, '10', $user->id);
        }

        // A weighed count re-anchors the bin at 6 kg: 14 kg has been used,
        // which FIFO attributes to the older lot first.
        $this->countDayBin([
            'work_center_id' => $machine->id, 'item_id' => $resin->id, 'quantity_kg' => 6,
        ]);

        $bin = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id.'&item_id='.$resin->id,
        )->assertSuccessful()->json('data.bin');

        $this->assertSame('6.0000', $bin['available_kg']);
        $this->assertSame('20.0000', $bin['loaded_kg']);
        $this->assertSame('OLD', $bin['layers'][0]['lot']['supplier_lot_no']);
        $this->assertSame('0.0000', $bin['layers'][0]['in_bin_kg']);
        $this->assertSame('NEW', $bin['layers'][1]['lot']['supplier_lot_no']);
        $this->assertSame('6.0000', $bin['layers'][1]['in_bin_kg']);
    }

    public function test_shortage_is_computed_when_the_recipe_expects_more_than_the_bin_holds(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $bottle = Item::create(['sku' => 'FG-BOTTLE', 'name' => '1L Bottle', 'uom' => 'Nos']);
        $cap = Item::create(['sku' => 'RM-CAP', 'name' => 'Cap', 'uom' => 'Nos']);
        $bom = Bom::create(['item_id' => $bottle->id, 'name' => '1L Bottle recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0200']);
        $bom->lines()->create(['component_item_id' => $cap->id, 'quantity_per' => '1.0000']);

        $this->loadIntoMachine($machine, $bag, '5', $user->id);

        $requirement = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id
            .'&product_item_id='.$bottle->id.'&expected_pieces=1000',
        )->assertSuccessful()->json('data.requirement');

        $this->assertSame('bom', $requirement['recipe_source']);
        $this->assertSame(1000, $requirement['expected_pieces']);

        $resinRow = collect($requirement['components'])->firstWhere('item_id', $resin->id);
        // 1000 pcs × 0.02 kg = 20 kg expected, 5 kg in the bin → 15 kg short.
        $this->assertTrue($resinRow['is_mass']);
        $this->assertSame('20.0000', $resinRow['expected_quantity']);
        $this->assertSame('5.0000', $resinRow['available_quantity']);
        $this->assertSame('15.0000', $resinRow['shortage_quantity']);

        // Caps are counted in Nos and never sit in the day bin — reporting
        // them 1000 short on every run would be noise, not information.
        $capRow = collect($requirement['components'])->firstWhere('item_id', $cap->id);
        $this->assertFalse($capRow['is_mass']);
        $this->assertSame('1000.0000', $capRow['expected_quantity']);
        $this->assertNull($capRow['shortage_quantity']);
    }

    public function test_a_bin_holding_enough_reports_a_zero_shortage_not_a_negative_one(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $bottle = Item::create(['sku' => 'FG-BOTTLE', 'name' => '1L Bottle', 'uom' => 'Nos']);
        $bom = Bom::create(['item_id' => $bottle->id, 'name' => '1L Bottle recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0200']);

        // Full-bag load: the whole 100 kg goes to the machine's ledger.
        $this->loadIntoMachine($machine, $bag, null, $user->id);
        $this->assertSame('0.0000', $bag->fresh()->remaining_kg);

        $requirement = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id
            .'&product_item_id='.$bottle->id.'&expected_pieces=1000',
        )->assertSuccessful()->json('data.requirement');

        $resinRow = $requirement['components'][0];
        $this->assertSame('100.0000', $resinRow['available_quantity']);
        $this->assertSame('0.0000', $resinRow['shortage_quantity']);
    }

    public function test_a_product_without_a_recipe_reports_no_requirement_rather_than_guessing(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('production.view');
        [, , $machine] = $this->masters();
        $bottle = Item::create(['sku' => 'FG-BOTTLE', 'name' => '1L Bottle', 'uom' => 'Nos']);

        $requirement = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id
            .'&product_item_id='.$bottle->id.'&expected_pieces=1000',
        )->assertSuccessful()->json('data.requirement');

        $this->assertNull($requirement['recipe_source']);
        $this->assertSame([], $requirement['components']);
    }

    public function test_the_removed_load_and_history_routes_are_gone_even_with_traceability_on(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('production.manage', 'production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, null);

        // DEC-20260807-006: the machine-stamped load path is dead, not gated.
        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag->barcode, 'quantity_kg' => 5,
        ])->assertNotFound();
        $this->getJson('/api/v1/production/bin-bay/history?work_center_id='.$machine->id)
            ->assertNotFound();
        $this->assertSame('100.0000', (string) $bag->fresh()->remaining_kg);
    }

    public function test_an_empty_bay_reports_zero_rather_than_failing(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('production.view');
        [$resin, , $machine] = $this->masters();

        $bin = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id.'&item_id='.$resin->id,
        )->assertSuccessful()->json('data.bin');

        $this->assertSame('0.0000', $bin['available_kg']);
        $this->assertSame([], $bin['layers']);
    }
}
