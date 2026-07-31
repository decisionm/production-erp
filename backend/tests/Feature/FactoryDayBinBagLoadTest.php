<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Shift Floor's CENTRALIZED bag scan into the factory day bin
 * (POST /production/day-bin/load-bag): one barcode, kg moves store →
 * day-bin warehouse via the EXISTING stock transfer — no new ledger, no
 * machine picked.
 *
 * What must hold:
 *  - a plain scan loads the WHOLE bag: bin balance rises, store balance
 *    falls, the bag reads 0 kg / consumed — all three in one transaction;
 *  - a weighed quantity_kg loads that much and the bag stays in the store;
 *  - a quantity beyond the bag, an unknown barcode, an already-consumed
 *    bag and a not-yet-configured bin are each refused with a clear 422 —
 *    and refusal moves NOTHING;
 *  - the audit identity is the authenticated user; supervisor_id is only
 *    a note.
 */
class FactoryDayBinBagLoadTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $store;

    private Warehouse $dayBin;

    private Item $resin;

    private StockBalance $storeResin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->store = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->dayBin = Warehouse::create(['code' => 'WH-DAYBIN', 'name' => 'Factory Day Bin']);
        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS']);

        $this->storeResin = StockBalance::create([
            'item_id' => $this->resin->id,
            'warehouse_id' => $this->store->id,
            'quantity' => '100.0000',
            'average_cost' => '85.0000',
        ]);
    }

    private function actingAsProduction(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('production.manage', 'web');
        $user->givePermissionTo('production.manage');
        Sanctum::actingAs($user);

        return $user;
    }

    private function configureBin(): void
    {
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);
    }

    /** A registered 25 kg bag physically sitting in the raw-material store. */
    private function bag(string $barcode = 'LOT1-B1', string $kg = '25.0000'): MaterialBag
    {
        $lot = MaterialLot::create([
            'item_id' => $this->resin->id,
            'received_date' => '2026-07-25',
            'bag_count' => 1,
            'total_received_kg' => $kg,
        ]);

        return $lot->bags()->create([
            'barcode' => $barcode,
            'original_kg' => $kg,
            'remaining_kg' => $kg,
            'status' => MaterialBagStatus::InStore,
            'current_warehouse_id' => $this->store->id,
        ]);
    }

    private function binBalance(): ?string
    {
        return StockBalance::query()
            ->where('item_id', $this->resin->id)
            ->where('warehouse_id', $this->dayBin->id)
            ->value('quantity');
    }

    // (a) a plain scan loads the whole bag ------------------------------------

    public function test_a_plain_scan_loads_the_whole_bag_into_the_bin(): void
    {
        $user = $this->actingAsProduction();
        $this->configureBin();
        $bag = $this->bag();

        $response = $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'LOT1-B1',
        ])->assertOk();

        // The response answers the two questions the scanner has: what is
        // left in the bag, and what is in the bin now.
        $response->assertJsonPath('data.bag.barcode', 'LOT1-B1');
        $response->assertJsonPath('data.bag.remaining_kg', '0.0000');
        $response->assertJsonPath('data.bag.status', 'consumed');
        $response->assertJsonPath('data.day_bin.item_id', $this->resin->id);
        $response->assertJsonPath('data.day_bin.quantity_kg', '25.0000');
        $response->assertJsonPath('data.day_bin.item.sku', 'PET-RESIN');

        // Bin rose, store fell, bag emptied — by exactly the same 25 kg.
        $this->assertSame('25.0000', $this->binBalance());
        $this->assertSame('75.0000', $this->storeResin->fresh()->quantity);
        $this->assertSame('0.0000', $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::Consumed, $bag->fresh()->status);
        // Central load: no machine was picked, so none is stamped.
        $this->assertNull($bag->fresh()->day_bin_work_center_id);

        // The record is the EXISTING transfer pair, referencing the bag —
        // created_by is the authenticated user (the audit identity).
        $out = StockMovement::query()->where('type', StockMovementType::TransferOut)->sole();
        $in = StockMovement::query()->where('type', StockMovementType::TransferIn)->sole();
        $this->assertSame($this->store->id, $out->warehouse_id);
        $this->assertSame($this->dayBin->id, $in->warehouse_id);
        $this->assertSame('Day bin load — bag LOT1-B1', $in->reference);
        $this->assertSame($user->id, $in->created_by);
        $this->assertSame($user->id, $out->created_by);
    }

    public function test_the_acting_supervisor_is_a_note_never_the_audit_identity(): void
    {
        $user = $this->actingAsProduction();
        $supervisor = User::factory()->create(['is_active' => true, 'name' => 'Sendhil P']);
        $this->configureBin();
        $this->bag();

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'LOT1-B1',
            'supervisor_id' => $supervisor->id,
        ])->assertOk();

        $in = StockMovement::query()->where('type', StockMovementType::TransferIn)->sole();
        $this->assertSame('Acting supervisor: Sendhil P', $in->notes);
        $this->assertSame($user->id, $in->created_by);
    }

    // (b) a weighed partial load ----------------------------------------------

    public function test_a_partial_quantity_loads_that_much_and_the_bag_stays_in_the_store(): void
    {
        $this->actingAsProduction();
        $this->configureBin();
        $bag = $this->bag();

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'LOT1-B1',
            'quantity_kg' => '10.5',
        ])->assertOk()
            ->assertJsonPath('data.bag.remaining_kg', '14.5000')
            ->assertJsonPath('data.bag.status', 'in_store')
            ->assertJsonPath('data.day_bin.quantity_kg', '10.5000');

        $this->assertSame('10.5000', $this->binBalance());
        $this->assertSame('89.5000', $this->storeResin->fresh()->quantity);
        $this->assertSame('14.5000', $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $bag->fresh()->status);
    }

    // (c) more than the bag holds ---------------------------------------------

    public function test_a_quantity_beyond_the_bag_is_refused_and_nothing_moves(): void
    {
        $this->actingAsProduction();
        $this->configureBin();
        $bag = $this->bag();

        $response = $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'LOT1-B1',
            'quantity_kg' => '30',
        ])->assertUnprocessable();

        $this->assertStringContainsString('only 25.0000 kg remaining', $response->json('message'));

        $this->assertNull($this->binBalance());
        $this->assertSame('100.0000', $this->storeResin->fresh()->quantity);
        $this->assertSame('25.0000', $bag->fresh()->remaining_kg);
        $this->assertSame(0, StockMovement::count());
    }

    // (d) unknown barcode -------------------------------------------------------

    public function test_an_unknown_barcode_is_refused_with_a_plain_message(): void
    {
        $this->actingAsProduction();
        $this->configureBin();

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'NO-SUCH-BAG',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'barcode' => 'Unknown bag barcode — no registered bag carries this code.',
            ]);

        $this->assertSame(0, StockMovement::count());
    }

    // (e) no day-bin warehouse configured ----------------------------------------

    public function test_with_no_bin_configured_the_scan_is_a_clear_422_and_nothing_moves(): void
    {
        $this->actingAsProduction();
        $bag = $this->bag();

        $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'LOT1-B1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['day_bin']);

        // Refusal moved nothing: bag untouched, store untouched, no movements.
        $this->assertSame('25.0000', $bag->fresh()->remaining_kg);
        $this->assertSame(MaterialBagStatus::InStore, $bag->fresh()->status);
        $this->assertSame('100.0000', $this->storeResin->fresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    // (f) an already-consumed bag -------------------------------------------------

    public function test_an_already_consumed_bag_is_refused(): void
    {
        $this->actingAsProduction();
        $this->configureBin();
        $bag = $this->bag();
        $bag->update(['remaining_kg' => '0.0000', 'status' => MaterialBagStatus::Consumed]);

        $response = $this->postJson('/api/v1/production/day-bin/load-bag', [
            'barcode' => 'LOT1-B1',
        ])->assertUnprocessable();

        $this->assertStringContainsString('already consumed', $response->json('message'));

        $this->assertNull($this->binBalance());
        $this->assertSame('100.0000', $this->storeResin->fresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    // Guarded like its day-bin neighbours ------------------------------------------

    public function test_the_route_is_invisible_with_traceability_off(): void
    {
        config(['production.traceability_enabled' => false]);
        $this->actingAsProduction();
        $this->configureBin();
        $this->bag();

        $this->postJson('/api/v1/production/day-bin/load-bag', ['barcode' => 'LOT1-B1'])
            ->assertNotFound();

        $this->assertSame(0, StockMovement::count());
    }
}
