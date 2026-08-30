<?php

namespace Tests\Feature;

use App\Console\Commands\PreviewWarehouseStockRecovery;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The recovery preview. What is asserted here is mostly what it REFUSES to
 * do: fold Production/WIP into the Store, propose a destination for stock the
 * factory never received, transfer a negative, or write anything at all.
 *
 * The WIP exclusion is the one that matters. Production/WIP is retired only
 * in the sense that no picker offers it; it is the location holding material
 * issued to production and not yet consumed (DEC-20260817-001). A preview
 * that listed it as "stranded" would invite a move that destroys the only
 * state separating the floor from the store, so the exclusion is pinned here
 * rather than left to the reader of the docblock.
 */
class PreviewWarehouseStockRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private Warehouse $wip;

    private Warehouse $retired;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'STORE', 'name' => 'The Store', 'is_active' => true, 'tally_guid' => 'company-a-0001']);
        $this->wip = Warehouse::create(['code' => 'WIP', 'name' => 'Work In Progress', 'is_active' => false]);
        $this->retired = Warehouse::create(['code' => 'OLD-RM', 'name' => 'Old RM Store', 'is_active' => false]);

        app(AppSettingService::class)->set(ProductionWipLocationResolver::SETTING_KEY, (string) $this->wip->id);
    }

    private function item(string $sku, string $uom = 'Kgs.'): Item
    {
        return Item::create(['sku' => $sku, 'name' => $sku, 'uom' => $uom]);
    }

    private function stranded(Item $item, Warehouse $warehouse, string $qty, string $reference): void
    {
        StockBalance::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $qty,
            'average_cost' => '0.0000',
        ]);

        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovementType::Receipt->value,
            'quantity' => $qty,
            'reference' => $reference,
            'movement_date' => now(),
        ]);
    }

    public function test_material_standing_in_production_wip_is_never_listed_as_stranded(): void
    {
        $resin = $this->item('PET-RESIN');
        $this->stranded($resin, $this->wip, '860.0000', 'Store issue SI-1');

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('EXCLUDED')
            ->expectsOutputToContain(PreviewWarehouseStockRecovery::VERDICT_NONE)
            ->assertSuccessful();
    }

    public function test_an_ordinary_document_is_a_candidate_and_names_the_store(): void
    {
        $bottle = $this->item('BTL-500', 'Nos.');
        $this->stranded($bottle, $this->retired, '1980.0000', 'QC release to FG store');

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('BTL-500')
            ->expectsOutputToContain('REAL')
            ->expectsOutputToContain('STORE')
            ->assertSuccessful();
    }

    public function test_a_rehearsal_opening_balance_is_withheld_rather_than_moved(): void
    {
        $resin = $this->item('PILOT-RESIN');
        $this->stranded($resin, $this->retired, '9000.0000', 'Provisional opening stock (pilot)');

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('PILOT')
            ->expectsOutputToContain('owner decision')
            ->expectsOutputToContain('material the factory never received')
            ->assertSuccessful();
    }

    public function test_a_wiring_check_is_withheld_too(): void
    {
        $demo = $this->item('DEMO-ITEM');
        $this->stranded($demo, $this->retired, '50.0000', 'TEST opening stock for wiring check');

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('TEST')
            ->expectsOutputToContain('owner decision')
            ->assertSuccessful();
    }

    public function test_a_negative_balance_is_called_out_and_given_no_destination(): void
    {
        $resin = $this->item('NEG-RESIN');
        StockBalance::create([
            'item_id' => $resin->id,
            'warehouse_id' => $this->retired->id,
            'quantity' => '-112.3250',
            'average_cost' => '0.0000',
        ]);
        StockMovement::create([
            'item_id' => $resin->id,
            'warehouse_id' => $this->retired->id,
            'type' => StockMovementType::Issue->value,
            'quantity' => '112.3250',
            'reference' => 'SPE #154',
            'movement_date' => now(),
        ]);

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('negative')
            ->expectsOutputToContain('resolve first')
            ->assertSuccessful();
    }

    public function test_a_pair_touched_by_both_kinds_of_movement_is_not_classified_for_us(): void
    {
        $resin = $this->item('MIXED-RESIN');
        $this->stranded($resin, $this->retired, '200.0000', 'Provisional opening stock (pilot)');
        StockMovement::create([
            'item_id' => $resin->id,
            'warehouse_id' => $this->retired->id,
            'type' => StockMovementType::Receipt->value,
            'quantity' => '10.0000',
            'reference' => 'GRN for PO 4',
            'movement_date' => now(),
        ]);

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('MIXED')
            ->assertSuccessful();
    }

    public function test_it_writes_nothing(): void
    {
        $resin = $this->item('PET-RESIN');
        $this->stranded($resin, $this->retired, '9000.0000', 'Provisional opening stock (pilot)');
        $this->stranded($this->item('BTL-500', 'Nos.'), $this->retired, '1980.0000', 'QC release to FG store');

        $before = [
            'balances' => DB::table('stock_balances')->orderBy('id')->get()->toJson(),
            'movements' => DB::table('stock_movements')->orderBy('id')->get()->toJson(),
            'warehouses' => DB::table('warehouses')->orderBy('id')->get()->toJson(),
        ];

        $this->artisan('inventory:preview-warehouse-recovery')->assertSuccessful();

        $this->assertSame($before['balances'], DB::table('stock_balances')->orderBy('id')->get()->toJson());
        $this->assertSame($before['movements'], DB::table('stock_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($before['warehouses'], DB::table('warehouses')->orderBy('id')->get()->toJson());
    }

    public function test_with_no_wip_configured_it_refuses_rather_than_guessing(): void
    {
        app(AppSettingService::class)->set(ProductionWipLocationResolver::SETTING_KEY, null);
        $this->wip->update(['code' => 'NOT-WIP']);

        $this->stranded($this->item('PET-RESIN'), $this->retired, '9000.0000', 'Provisional opening stock (pilot)');

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('refuses to classify')
            ->assertFailed();
    }

    public function test_two_active_warehouses_means_no_destination_is_proposed(): void
    {
        Warehouse::create(['code' => 'STORE-2', 'name' => 'Second Store', 'is_active' => true, 'tally_guid' => 'company-a-0002']);
        $this->stranded($this->item('BTL-500', 'Nos.'), $this->retired, '10.0000', 'QC release to FG store');

        $this->artisan('inventory:preview-warehouse-recovery')
            ->expectsOutputToContain('a single destination Store cannot be resolved')
            ->assertSuccessful();
    }
}
