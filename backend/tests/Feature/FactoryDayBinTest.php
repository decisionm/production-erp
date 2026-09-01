<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The FACTORY DAY BIN — the central place raw material sits after it leaves
 * the store and before a machine runs.
 *
 * The whole design in one line: the day bin is just a WAREHOUSE. So there is
 * no new ledger and no new balance maths to prove here — what must be proved
 * is that the three existing mechanisms line up:
 *
 *  (a) the setting naming that warehouse round-trips through the API,
 *  (b) the bin's content is read straight off stock_balances,
 *  (c) a consumption line at batch completion whose warehouse IS the bin
 *      reduces the bin's balance by exactly the kg entered — no adjustment,
 *      no second figure,
 *  (d) with NO bin configured, completion behaves exactly as it always did.
 */
class FactoryDayBinTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $dayBin;

    private Warehouse $store;

    private Warehouse $fg;

    private Item $resin;

    private Item $masterbatch;

    private Item $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Warehouse::create(['code' => 'WH-RM', 'name' => 'Raw Material Store']);
        $this->dayBin = Warehouse::create(['code' => 'WH-DAYBIN', 'name' => 'Factory Day Bin']);
        $this->fg = Warehouse::create(['code' => 'WH-FG', 'name' => 'Finished Goods']);

        $this->resin = Item::create(['sku' => 'PET-RESIN', 'name' => 'PET Resin', 'uom' => 'KGS']);
        $this->masterbatch = Item::create(['sku' => 'MB-BLUE', 'name' => 'Masterbatch Blue', 'uom' => 'KGS']);
        $this->bottle = Item::create(['sku' => 'BTL-1', 'name' => 'Bottle 500ml', 'uom' => 'NOS']);
    }

    private function actingAsProduction(string $permission = 'production.manage'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user);

        return $user;
    }

    private function inProgressEntry(): ShiftProductionEntry
    {
        $shift = Shift::create(['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00']);
        $machine = WorkCenter::create(['code' => 'MC-01', 'name' => 'Machine 1']);

        return ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-07-30',
            'batch_number' => '20260730-M01-001',
            'batch_status' => BatchStatus::InProgress,
            'quantity_produced' => null,
            'quantity_scrap' => '0',
        ]);
    }

    /** Material physically in the bin: an ordinary stock balance, nothing bespoke. */
    private function stockInBin(Item $item, string $quantity, ?Warehouse $warehouse = null): StockBalance
    {
        return StockBalance::create([
            'item_id' => $item->id,
            'warehouse_id' => ($warehouse ?? $this->dayBin)->id,
            'quantity' => $quantity,
            'average_cost' => '85.0000',
        ]);
    }

    // (a) the setting round-trips through the API ------------------------------

    public function test_the_day_bin_warehouse_setting_round_trips_through_the_api(): void
    {
        $this->actingAsProduction();

        // Nothing named yet — the honest answer is null, not an error.
        $this->getJson('/api/v1/production/settings')
            ->assertOk()
            ->assertJsonPath('data.day_bin_warehouse_id', null);

        $this->putJson('/api/v1/production/settings/day-bin-warehouse', ['warehouse_id' => $this->dayBin->id])
            ->assertOk()
            ->assertJsonPath('data.day_bin_warehouse_id', $this->dayBin->id);

        // Read back on a fresh request: it is persisted, not per-request state.
        $this->getJson('/api/v1/production/settings')
            ->assertOk()
            ->assertJsonPath('data.day_bin_warehouse_id', $this->dayBin->id);

        // And it is clearable — back to exactly the pre-day-bin world.
        $this->putJson('/api/v1/production/settings/day-bin-warehouse', ['warehouse_id' => null])
            ->assertOk()
            ->assertJsonPath('data.day_bin_warehouse_id', null);

        $this->getJson('/api/v1/production/settings')
            ->assertJsonPath('data.day_bin_warehouse_id', null);
    }

    public function test_a_warehouse_that_does_not_exist_is_refused(): void
    {
        $this->actingAsProduction();

        $this->putJson('/api/v1/production/settings/day-bin-warehouse', ['warehouse_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    // (b) the balance endpoint ------------------------------------------------

    public function test_the_balance_endpoint_returns_per_material_kg_for_the_configured_bin(): void
    {
        $this->actingAsProduction();
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        $this->stockInBin($this->resin, '1250.5000');
        $this->stockInBin($this->masterbatch, '18.2500');
        // Same materials sitting in the STORE must not be counted as bin stock —
        // that is the whole point of the bin being its own location.
        $this->stockInBin($this->resin, '9000.0000', $this->store);

        $response = $this->getJson('/api/v1/production/factory-day-bin')->assertOk();

        $response->assertJsonPath('data.warehouse.id', $this->dayBin->id);
        $response->assertJsonPath('data.warehouse.code', 'WH-DAYBIN');
        $response->assertJsonCount(2, 'data.materials');

        // Item-name ordered: "Masterbatch Blue" before "PET Resin".
        $response->assertJsonPath('data.materials.0.item_id', $this->masterbatch->id);
        $response->assertJsonPath('data.materials.0.quantity_kg', '18.2500');
        $response->assertJsonPath('data.materials.1.item_id', $this->resin->id);
        $response->assertJsonPath('data.materials.1.quantity_kg', '1250.5000');
        $response->assertJsonPath('data.materials.1.item.sku', 'PET-RESIN');
    }

    public function test_a_raw_material_with_no_stock_anywhere_still_appears_at_zero(): void
    {
        // The owner's report: "here the amber colour master batch should also
        // come." It did not, because the balances table listed only items that
        // already had a stock row — and none had been loaded yet. A material
        // you cannot see is a material you cannot load, so absence of stock hid
        // exactly the case this page exists for. Zero is a fact; a missing row
        // is not.
        $this->actingAsProduction('production.view');
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        // Resin has stock; the masterbatch has none, anywhere.
        $this->stockInBin($this->resin, '120.0000');

        $summary = $this->getJson('/api/v1/production/factory-day-bin')
            ->assertOk()
            ->json('data.summary');

        $byId = collect($summary)->keyBy('item_id');

        $this->assertTrue($byId->has($this->masterbatch->id), 'A raw material with no stock must still be listed.');
        $this->assertSame('0.0000', $byId[$this->masterbatch->id]['bin_kg']);
        $this->assertSame('0.0000', $byId[$this->masterbatch->id]['store_kg']);
        $this->assertTrue($byId->has($this->resin->id));

        // And a bottle never appears: the day bin holds material bought by the
        // kilogram, never finished goods counted in Nos.
        $this->assertFalse($byId->has($this->bottle->id), 'A Nos-uom finished good must never appear in the day bin.');
    }

    public function test_every_kg_spelling_this_catalogue_uses_counts_as_a_raw_material(): void
    {
        // The live catalogue spells the unit Kgs, Kgs. and KGS. A material
        // missed by the spelling check is invisible on this page for a reason
        // nobody would ever guess from the screen.
        $this->actingAsProduction('production.view');
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        $spellings = ['Kgs' => 'MB-A', 'Kgs.' => 'MB-B', 'KGS' => 'MB-C', 'kg' => 'MB-D'];
        $ids = [];
        foreach ($spellings as $uom => $sku) {
            $ids[] = Item::create(['sku' => $sku, 'name' => "Master Batch {$sku}", 'uom' => $uom])->id;
        }

        $listed = collect($this->getJson('/api/v1/production/factory-day-bin')->assertOk()->json('data.summary'))
            ->pluck('item_id');

        foreach ($ids as $id) {
            $this->assertTrue($listed->contains($id), 'Every kg spelling in the catalogue must count as a raw material.');
        }
    }

    public function test_an_inactive_raw_material_is_left_off_the_list(): void
    {
        // Listing every kg item must not mean listing retired ones — the
        // picker would grow a tail of materials the factory no longer buys.
        $this->actingAsProduction('production.view');
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        $retired = Item::create(['sku' => 'MB-OLD', 'name' => 'Masterbatch Retired', 'uom' => 'KGS', 'is_active' => false]);

        $listed = collect($this->getJson('/api/v1/production/factory-day-bin')->assertOk()->json('data.summary'))
            ->pluck('item_id');

        $this->assertFalse($listed->contains($retired->id));
    }

    public function test_with_no_bin_configured_the_balance_endpoint_answers_null_not_an_error(): void
    {
        $this->actingAsProduction();

        $this->getJson('/api/v1/production/factory-day-bin')
            ->assertOk()
            ->assertJsonPath('data.warehouse', null)
            ->assertJsonCount(0, 'data.materials');
    }

    public function test_a_deleted_bin_warehouse_reads_as_not_configured_rather_than_breaking(): void
    {
        $this->actingAsProduction();
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);
        $this->dayBin->delete();

        $this->getJson('/api/v1/production/factory-day-bin')
            ->assertOk()
            ->assertJsonPath('data.warehouse', null);

        $this->getJson('/api/v1/production/settings')
            ->assertOk()
            ->assertJsonPath('data.day_bin_warehouse_id', null);
    }

    public function test_the_bin_read_is_visible_to_view_only_staff(): void
    {
        // A supervisor with production.view (no manage) must still be able to
        // see what is in the bin — it is a read.
        $this->actingAsProduction('production.view');
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);
        $this->stockInBin($this->resin, '10.0000');

        $this->getJson('/api/v1/production/factory-day-bin')
            ->assertOk()
            ->assertJsonPath('data.materials.0.quantity_kg', '10.0000');

        // ...but naming the bin is a manage action.
        $this->putJson('/api/v1/production/settings/day-bin-warehouse', ['warehouse_id' => $this->dayBin->id])
            ->assertForbidden();
    }

    // (c) consumption reduces the bin ----------------------------------------

    public function test_completing_a_batch_from_the_bin_decreases_the_bin_balance_by_exactly_the_kg_entered(): void
    {
        // The masterbatch here is in no BOM, no packing master and no dosing
        // sheet, so the completion reads its line as an ADDED one — hence the
        // reason below and the authority to record it (AddedConsumptionLineTest).
        $user = $this->actingAsProduction();
        Permission::findOrCreate('consumption-substitute.manage', 'web');
        $user->givePermissionTo('consumption-substitute.manage');

        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        $binResin = $this->stockInBin($this->resin, '1000.0000');
        $binMb = $this->stockInBin($this->masterbatch, '50.0000');
        $storeResin = $this->stockInBin($this->resin, '5000.0000', $this->store);

        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5000',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->dayBin->id, 'quantity_issued_kg' => '124.7500'],
                ['item_id' => $this->masterbatch->id, 'warehouse_id' => $this->dayBin->id, 'quantity_issued_kg' => '2.5000', 'added_reason' => 'No dosing row for this colour yet'],
            ],
        ])->assertOk();

        // Exactly the kg entered came out of the bin — not a norm, not a
        // recalculated figure.
        $this->assertSame('875.2500', $binResin->fresh()->quantity);
        $this->assertSame('47.5000', $binMb->fresh()->quantity);
        // The store was not touched: consumption came out of the bin only.
        $this->assertSame('5000.0000', $storeResin->fresh()->quantity);
        // And the finished goods still landed in the entry's own warehouse.
        $this->assertSame('5000.0000', StockBalance::query()
            ->where('item_id', $this->bottle->id)
            ->where('warehouse_id', $this->fg->id)
            ->value('quantity'));
    }

    public function test_a_line_from_the_store_still_reduces_the_store_not_the_bin(): void
    {
        // The default is the bin, but the field stays changeable — an exception
        // line issued from the store must behave exactly as it always has.
        $this->actingAsProduction();
        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);

        $binResin = $this->stockInBin($this->resin, '1000.0000');
        $storeResin = $this->stockInBin($this->resin, '5000.0000', $this->store);

        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5000',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->store->id, 'quantity_issued_kg' => '100.0000'],
            ],
        ])->assertOk();

        $this->assertSame('1000.0000', $binResin->fresh()->quantity);
        $this->assertSame('4900.0000', $storeResin->fresh()->quantity);
    }

    public function test_loading_the_bin_is_the_existing_transfer_and_moves_stock_store_to_bin(): void
    {
        // The Day Bin page's one form posts THIS endpoint — no new write path.
        $user = User::factory()->create(['is_active' => true]);
        foreach (['production.manage', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        app(FactoryDayBinService::class)->setWarehouseId($this->dayBin->id);
        $storeResin = $this->stockInBin($this->resin, '5000.0000', $this->store);

        $this->postJson('/api/v1/inventory/stock-movements/transfers', [
            'item_id' => $this->resin->id,
            'from_warehouse_id' => $this->store->id,
            'to_warehouse_id' => $this->dayBin->id,
            'quantity' => '500.0000',
            'movement_date' => '2026-07-30 09:15:00',
        ])->assertOk();

        $this->assertSame('4500.0000', $storeResin->fresh()->quantity);

        $this->getJson('/api/v1/production/factory-day-bin')
            ->assertOk()
            ->assertJsonPath('data.materials.0.item_id', $this->resin->id)
            ->assertJsonPath('data.materials.0.quantity_kg', '500.0000');
    }

    // (d) no bin configured — completion unchanged ----------------------------

    public function test_with_no_bin_configured_completion_works_exactly_as_before(): void
    {
        $this->actingAsProduction();
        $this->assertNull(app(FactoryDayBinService::class)->warehouseId());

        $storeResin = $this->stockInBin($this->resin, '5000.0000', $this->store);
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5000',
            'running_hours' => 8,
            'material_consumptions' => [
                ['item_id' => $this->resin->id, 'warehouse_id' => $this->store->id, 'quantity_issued_kg' => '130.0000'],
            ],
        ])->assertOk();

        $this->assertSame(BatchStatus::Completed, $entry->fresh()->batch_status);
        $this->assertSame('4870.0000', $storeResin->fresh()->quantity);
        $this->assertSame('5000.0000', StockBalance::query()
            ->where('item_id', $this->bottle->id)
            ->where('warehouse_id', $this->fg->id)
            ->value('quantity'));
    }

    public function test_with_no_bin_configured_a_batch_with_no_consumption_lines_still_completes(): void
    {
        $this->actingAsProduction();
        $entry = $this->inProgressEntry();

        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/complete", [
            'quantity_produced' => '5000',
            'running_hours' => 8,
        ])->assertOk();

        $this->assertSame(BatchStatus::Completed, $entry->fresh()->batch_status);
    }
}
