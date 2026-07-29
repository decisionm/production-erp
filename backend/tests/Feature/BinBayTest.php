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
use Tests\TestCase;

/**
 * The CENTRAL bin bay: material is loaded into a machine's day bin once, at
 * the bay, instead of being re-declared inside every batch.
 *
 * A load is an inventory LOCATION movement (store → machine day bin). These
 * tests pin exactly that: the kg leaves the bag and appears in the bin, the
 * source lot stays attached to it, the run's shortfall is computable, and
 * every load names who did it. Nothing here consumes stock or posts a
 * voucher, and no test asserts that it does.
 */
class BinBayTest extends TestCase
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

    public function test_with_the_flag_off_every_bin_bay_route_is_a_404(): void
    {
        config(['production.traceability_enabled' => false]);
        $this->actingAsUserWithPermissions('production.manage');
        [$resin, , $machine] = $this->masters();

        // An explicitly disabled deployment has no bin-bay surface.
        $this->getJson('/api/v1/production/bin-bay/availability?work_center_id='.$machine->id.'&item_id='.$resin->id)
            ->assertNotFound();
        $this->getJson('/api/v1/production/bin-bay/history?work_center_id='.$machine->id)->assertNotFound();
        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id, 'barcode' => 'X',
        ])->assertNotFound();
    }

    public function test_loading_five_kg_of_a_hundred_kg_bag_leaves_ninety_five_on_the_bag_and_five_in_the_bin(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.manage', 'production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id,
            'barcode' => $bag->barcode,
            'quantity_kg' => 5,
        ])->assertSuccessful()
            ->assertJsonPath('data.quantity_kg', '5.0000')
            ->assertJsonPath('data.type', 'load')
            // The load is credited to whoever scanned it, by default.
            ->assertJsonPath('data.recorded_by.id', $user->id);

        // The bag keeps the balance — a partial pour does NOT move the bag.
        $this->assertSame('95.0000', $bag->fresh()->remaining_kg);

        $bin = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id.'&item_id='.$resin->id,
        )->assertSuccessful()->json('data.bin');

        $this->assertSame('5.0000', $bin['available_kg']);
        $this->assertSame('5.0000', $bin['loaded_kg']);
        $this->assertSame('0.0000', $bin['unattributed_kg']);
    }

    public function test_availability_reports_the_source_lot_layer_behind_the_bin_balance(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.manage', 'production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id,
            'barcode' => $bag->barcode,
            'quantity_kg' => 5,
        ])->assertSuccessful();

        $bin = $this->getJson(
            '/api/v1/production/bin-bay/availability?work_center_id='.$machine->id.'&item_id='.$resin->id,
        )->assertSuccessful()->json('data.bin');

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
            $this->postJson('/api/v1/production/bin-bay/load', [
                'work_center_id' => $machine->id, 'barcode' => $bag->barcode, 'quantity_kg' => 10,
            ])->assertSuccessful();
        }

        // A weighed count re-anchors the bin at 6 kg: 14 kg has been used,
        // which FIFO attributes to the older lot first.
        $this->postJson('/api/v1/production/day-bin/count', [
            'work_center_id' => $machine->id, 'item_id' => $resin->id, 'quantity_kg' => 6,
        ])->assertSuccessful();

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
        $user = $this->actingAsUserWithPermissions('production.manage', 'production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $bottle = Item::create(['sku' => 'FG-BOTTLE', 'name' => '1L Bottle', 'uom' => 'Nos']);
        $cap = Item::create(['sku' => 'RM-CAP', 'name' => 'Cap', 'uom' => 'Nos']);
        $bom = Bom::create(['item_id' => $bottle->id, 'name' => '1L Bottle recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0200']);
        $bom->lines()->create(['component_item_id' => $cap->id, 'quantity_per' => '1.0000']);

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag->barcode, 'quantity_kg' => 5,
        ])->assertSuccessful();

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
        $user = $this->actingAsUserWithPermissions('production.manage', 'production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $bottle = Item::create(['sku' => 'FG-BOTTLE', 'name' => '1L Bottle', 'uom' => 'Nos']);
        $bom = Bom::create(['item_id' => $bottle->id, 'name' => '1L Bottle recipe', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0200']);

        // Full-bag scan: the whole 100 kg goes to the bay.
        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag->barcode,
        ])->assertSuccessful();
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

    public function test_history_records_who_loaded_what_when_and_off_which_bag(): void
    {
        $this->enableTraceability();
        $user = $this->actingAsUserWithPermissions('production.manage', 'production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $user->id);

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag->barcode, 'quantity_kg' => 5,
        ])->assertSuccessful();

        $rows = $this->getJson('/api/v1/production/bin-bay/history?work_center_id='.$machine->id)
            ->assertSuccessful()->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertSame('5.0000', $rows[0]['quantity_kg']);
        $this->assertSame($bag->barcode, $rows[0]['barcode']);
        $this->assertSame('RIL-2026-0714', $rows[0]['lot']['supplier_lot_no']);
        $this->assertSame($resin->id, $rows[0]['item']['id']);
        $this->assertSame($user->id, $rows[0]['loaded_by']['id']);
        $this->assertSame($user->name, $rows[0]['loaded_by']['name']);
        $this->assertNotNull($rows[0]['recorded_at']);
        // Loading is CENTRAL — it is not tied to a batch unless asked for.
        $this->assertNull($rows[0]['shift_production_entry_id']);
    }

    public function test_a_manager_may_credit_the_load_to_the_person_who_actually_carried_the_bag(): void
    {
        $this->enableTraceability();
        $manager = $this->actingAsUserWithPermissions('production.manage', 'production.view');
        $loader = User::factory()->create(['is_active' => true]);
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, $manager->id);

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id,
            'barcode' => $bag->barcode,
            'quantity_kg' => 5,
            'loaded_by' => $loader->id,
        ])->assertSuccessful()->assertJsonPath('data.recorded_by.id', $loader->id);

        $rows = $this->getJson('/api/v1/production/bin-bay/history?work_center_id='.$machine->id)
            ->assertSuccessful()->json('data.rows');

        $this->assertSame($loader->id, $rows[0]['loaded_by']['id']);
    }

    public function test_a_read_only_user_cannot_load_the_bay(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('production.view');
        [$resin, $warehouse, $machine] = $this->masters();
        $bag = $this->oneHundredKgBag($resin, $warehouse, null);

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id, 'barcode' => $bag->barcode, 'quantity_kg' => 5,
        ])->assertForbidden();

        $this->assertSame('100.0000', $bag->fresh()->remaining_kg);
    }

    public function test_an_unknown_barcode_is_rejected_by_name(): void
    {
        $this->enableTraceability();
        $this->actingAsUserWithPermissions('production.manage');
        [, , $machine] = $this->masters();

        $this->postJson('/api/v1/production/bin-bay/load', [
            'work_center_id' => $machine->id, 'barcode' => 'NOT-A-BAG',
        ])->assertStatus(422)->assertJsonValidationErrors('barcode');
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
        $this->assertSame([], $this->getJson(
            '/api/v1/production/bin-bay/history?work_center_id='.$machine->id,
        )->assertSuccessful()->json('data.rows'));
    }
}
