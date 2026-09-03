<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DEC-20260902-020: a product standard keeps at most one OPTIONAL default
 * packaging. At Start Batch: one valid packaging selects itself; several
 * with a default use it; several with none makes the supervisor choose.
 * The choice is frozen into the batch, never re-derived from a later edit
 * to Product Configuration.
 */
class PackagingResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fgStore;

    private WorkCenter $machine;

    private Shift $shift;

    private Item $item;

    private ProductionStandard $standard;

    /** Fixture as ProductReadinessGateTest::test_a_fully_mastered_product_starts_normally, plus a standard exposing $this->standard. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $this->machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1', 'is_active' => true]);
        $this->fgStore = Warehouse::create([
            'code' => 'FG', 'name' => 'FG Store', 'is_active' => true,
            'tally_guid' => 'gd-fg-0001',
        ]);

        $this->item = Item::create([
            'sku' => 'BTL-500',
            'name' => '500ml Round Amber',
            'uom' => 'Nos.',
            'is_active' => true,
            'nominal_weight_grams' => '31.5000',
            'standard_cycle_time' => '12.00',
            'standard_cavities' => 5,
            'nos_per_box' => 800,
            'nos_per_tray' => 40,
            'colour' => 'Amber',
            'tally_stock_item_guid' => 'itm-0001',
        ]);

        $resin = Item::create(['sku' => 'PET', 'name' => 'Billion Pet Resin', 'uom' => 'Kgs.', 'is_active' => true]);
        $bom = Bom::create(['item_id' => $this->item->id, 'name' => 'BOM', 'version' => '1', 'is_active' => true]);
        $bom->lines()->create(['component_item_id' => $resin->id, 'quantity_per' => '0.0315']);

        $this->standard = ProductionStandard::create([
            'item_id' => $this->item->id,
            'source_product_name' => '500ml Round Amber',
            'cavities' => 5,
            'unit_weight_grams' => '31.5000',
            'cycle_time' => '12.00',
            'status' => 'approved',
        ]);

        // The one packaging option — complete, and NOT marked default. Case
        // 1 (test_a_single_packaging_is_selected_without_asking) exists to
        // pin that "only option" is enough on its own; is_default is not
        // needed and must not be required.
        $this->standard->packagings()->create([
            'mode' => ProductionStandardPackaging::MODE_DIRECT_BOX,
            'nos_per_box' => 800,
            'is_default' => false,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        Permission::findOrCreate('production.view', 'web');
        $user->givePermissionTo(['production.manage', 'production.view']);
        Sanctum::actingAs($user);
    }

    private function startPayload(): array
    {
        return [
            'shift_id' => $this->shift->id,
            'work_center_id' => $this->machine->id,
            'item_id' => $this->item->id,
            'warehouse_id' => $this->fgStore->id,
        ];
    }

    public function test_a_single_packaging_is_selected_without_asking(): void
    {
        $only = $this->standard->packagings()->first();

        $id = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())
            ->assertOk()
            ->json('data.id');

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $id,
            'production_standard_packaging_id' => $only->id,
        ]);
    }

    public function test_the_default_is_used_when_several_exist(): void
    {
        $second = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 10, 'trays_per_box' => 52, 'nos_per_box' => 520, 'is_default' => true,
        ]);

        $id = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())
            ->assertOk()
            ->json('data.id');

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $id,
            'production_standard_packaging_id' => $second->id,
        ]);
    }

    public function test_several_without_a_default_ask_the_supervisor(): void
    {
        $this->standard->packagings()->update(['is_default' => false]);
        $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 10, 'trays_per_box' => 52, 'nos_per_box' => 520, 'is_default' => false,
        ]);

        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['production_standard_packaging_id']);
    }

    public function test_several_without_a_default_names_one_and_it_is_used(): void
    {
        $this->standard->packagings()->update(['is_default' => false]);
        $named = $this->standard->packagings()->create([
            'mode' => 'tray', 'nos_per_tray' => 10, 'trays_per_box' => 52, 'nos_per_box' => 520, 'is_default' => false,
        ]);

        $id = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload() + [
            'production_standard_packaging_id' => $named->id,
        ])
            ->assertOk()
            ->json('data.id');

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $id,
            'production_standard_packaging_id' => $named->id,
        ]);
    }
}
