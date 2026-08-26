<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A LIST THAT ENDS AT TWENTY WITHOUT SAYING SO.
 *
 * The defect this file guards is the one found in front of the owner on
 * 12-Aug-2026 (see frontend `src/lib/pickerFullList.test.ts`): a picker fed
 * from the default first page of 20 out of 642 items simply had no raw
 * materials in it, and nothing on screen said a row was missing. The same
 * shape was still live on four inventory lists:
 *
 *   - `/inventory/stock-balances` took NO parameters at all — no page size,
 *     no filter. Twenty rows, always, out of however many item×warehouse
 *     balances the factory holds.
 *   - `/inventory/batches` and `/inventory/serial-numbers` took `item_id`
 *     and nothing else; `per_page` was ignored, so a client could not ask
 *     for the rest even knowing it was there.
 *   - `/inventory/warehouses` already honoured `per_page` — its half of the
 *     defect was on the SPA side (a table with `pagination={false}` over a
 *     20-row page), which is pinned in the frontend suite, not here.
 *
 * TWO ASSERTIONS PER LIST, and the second is the one that matters:
 *
 *   1. `meta.total` reports the WHOLE count even on page one, so a caller
 *      can tell the list is longer than the page.
 *   2. Page 1 ∪ page 2 is DISTINCT and equals the full set. This is what
 *      catches an unstable sort — `stock_balances` was ordered by `item_id`
 *      alone, which ties for one item held in two warehouses, so rows could
 *      shuffle between pages and be skipped or served twice. A test that
 *      only counts page one passes straight over that.
 *
 * Every parameter is OPTIONAL: the bare URL each of these lists is called
 * with today (ModuleIndexTest, ApiSurfaceSmokeTest) must keep answering 200
 * with the same shape.
 */
class InventoryListCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /** More than one page, and not a multiple of the page size. */
    private const ROWS = 25;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    public static function listProvider(): array
    {
        return [
            'stock balances' => ['/api/v1/inventory/stock-balances', 'seedStockBalances'],
            'warehouses' => ['/api/v1/inventory/warehouses', 'seedWarehouses'],
            'batches' => ['/api/v1/inventory/batches', 'seedBatches'],
            'serial numbers' => ['/api/v1/inventory/serial-numbers', 'seedSerialNumbers'],
        ];
    }

    #[DataProvider('listProvider')]
    public function test_the_list_reports_the_whole_count_and_serves_every_row(string $url, string $seeder): void
    {
        $this->{$seeder}();

        $firstPage = $this->getJson($url)->assertSuccessful();
        $firstPage->assertJsonPath('meta.total', self::ROWS);
        $this->assertCount(20, $firstPage->json('data'), 'the default page size moved');

        $pages = array_map(
            fn (int $page) => $this->getJson("{$url}?page={$page}&per_page=10")->assertSuccessful(),
            [1, 2, 3],
        );

        // Without this the walk below is vacuous on a list that IGNORES
        // per_page: three pages of 20 also add up to 25.
        $pages[0]->assertJsonPath('meta.per_page', 10)->assertJsonCount(10, 'data');

        $ids = array_merge(...array_map($this->idsOf(...), $pages));

        $this->assertSame(
            $ids,
            array_values(array_unique($ids)),
            'walking the pages served the same row twice — the sort is not a total order',
        );
        $this->assertCount(self::ROWS, $ids, 'walking every page did not reach every row');
    }

    #[DataProvider('listProvider')]
    public function test_the_list_serves_the_whole_set_in_one_page_when_asked(string $url, string $seeder): void
    {
        $this->{$seeder}();

        $this->getJson("{$url}?per_page=100")
            ->assertSuccessful()
            ->assertJsonCount(self::ROWS, 'data');
    }

    #[DataProvider('listProvider')]
    public function test_the_page_size_is_bounded_by_the_server(string $url, string $seeder): void
    {
        $this->{$seeder}();

        // A client asking for a million rows gets the server's ceiling, not a
        // query the server builds the whole table into.
        $response = $this->getJson("{$url}?per_page=1000000")->assertSuccessful();
        $this->assertLessThanOrEqual(1000, (int) $response->json('meta.per_page'));

        // And a nonsense size falls back to the documented default rather than
        // collapsing to one row per page ((int) 'abc' is 0).
        $this->getJson("{$url}?per_page=abc")->assertSuccessful()->assertJsonPath('meta.per_page', 20);
        $this->getJson("{$url}?per_page=0")->assertSuccessful()->assertJsonCount(1, 'data');
    }

    public function test_search_narrows_each_list_on_the_server(): void
    {
        $this->seedStockBalances();
        $this->seedBatches();
        $this->seedSerialNumbers();
        $this->seedWarehouses();

        // Each needle is row 23 of 25 — past the first page, so a client-side
        // filter over the default page could never have found it.
        $this->getJson('/api/v1/inventory/stock-balances?search=FILLER-23')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item.sku', 'FILLER-23');

        $this->getJson('/api/v1/inventory/batches?search=LOT-23')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.batch_number', 'LOT-23');

        $this->getJson('/api/v1/inventory/serial-numbers?search=SN-23')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'SN-23');

        $this->getJson('/api/v1/inventory/warehouses?search=WH-23')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'WH-23');
    }

    public function test_searching_by_item_still_finds_the_rows_of_a_deleted_item(): void
    {
        // `whereHas` on a soft-deleting relation silently adds
        // `deleted_at is null`, so without withTrashed() a search would become
        // a FILTER: the balances, batches and serials of a deleted item would
        // stop being findable while still being on the unsearched list. The
        // stock is standing there either way.
        $this->seedStockBalances();
        $this->seedBatches();
        $this->seedSerialNumbers();

        Item::query()->delete();

        $this->getJson('/api/v1/inventory/stock-balances?search=Filler 23')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/inventory/batches?search=Batched Material')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', self::ROWS);

        $this->getJson('/api/v1/inventory/serial-numbers?search=Serialised Part')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', self::ROWS);
    }

    public function test_stock_balances_filter_by_item_for_an_items_own_page(): void
    {
        // The item detail page used to read this list unfiltered and pick its
        // rows out of the first twenty client-side, so past twenty balances an
        // item's own page stopped showing its stock. FILLER-1 is the one item
        // held in two stores, which is what that page has to show both of.
        $this->seedStockBalances();
        $shared = Item::where('sku', 'FILLER-1')->firstOrFail();

        $this->getJson("/api/v1/inventory/stock-balances?item_id={$shared->id}")
            ->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_batches_and_serial_numbers_still_filter_by_item(): void
    {
        // The existing parameter keeps working exactly as it did — this is
        // what the per-item selector reads.
        $this->seedBatches();
        $this->seedSerialNumbers();

        $mine = Item::create([
            'sku' => 'MINE', 'name' => 'Mine', 'uom' => 'Kgs',
            'tracking_type' => ItemTrackingType::Batch,
        ]);
        Batch::create(['item_id' => $mine->id, 'batch_number' => 'LOT-MINE']);

        $this->getJson("/api/v1/inventory/batches?item_id={$mine->id}")
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.batch_number', 'LOT-MINE');

        $serialItem = Item::where('sku', 'SERIALS')->firstOrFail();
        $this->getJson("/api/v1/inventory/serial-numbers?item_id={$serialItem->id}&per_page=100")
            ->assertSuccessful()
            ->assertJsonCount(self::ROWS, 'data');
    }

    #[DataProvider('listProvider')]
    public function test_the_list_still_includes_rows_of_archived_items_and_stores(string $url, string $seeder): void
    {
        // Pagination must not become a filter. A retired store and a retired
        // item keep their balances, batches and serials on the list — the
        // stock is still standing there, and the movements already recorded
        // against it still have to read back.
        $this->{$seeder}();

        Item::query()->update(['is_active' => false]);
        Warehouse::query()->update(['is_active' => false]);

        $this->getJson("{$url}?per_page=100")
            ->assertSuccessful()
            ->assertJsonCount(self::ROWS, 'data');
    }

    // ---- seeders -------------------------------------------------------------

    /** @return list<int> */
    private function idsOf(TestResponse $response): array
    {
        return array_map(
            static fn (array $row) => (int) $row['id'],
            $response->assertSuccessful()->json('data'),
        );
    }

    /** One warehouse, 25 items — so `order by item_id` alone is a total order. */
    private function seedBatches(): void
    {
        $item = Item::create([
            'sku' => 'BATCHES', 'name' => 'Batched Material', 'uom' => 'Kgs',
            'tracking_type' => ItemTrackingType::Batch,
        ]);
        foreach (range(1, self::ROWS) as $index) {
            Batch::create(['item_id' => $item->id, 'batch_number' => "LOT-{$index}"]);
        }
    }

    private function seedSerialNumbers(): void
    {
        $item = Item::create([
            'sku' => 'SERIALS', 'name' => 'Serialised Part', 'uom' => 'Nos',
            'tracking_type' => ItemTrackingType::Serial,
        ]);
        foreach (range(1, self::ROWS) as $index) {
            SerialNumber::create([
                'item_id' => $item->id,
                'serial_number' => "SN-{$index}",
                'status' => SerialNumberStatus::Registered,
            ]);
        }
    }

    private function seedWarehouses(): void
    {
        foreach (range(1, self::ROWS) as $index) {
            Warehouse::create([
                'code' => "WH-{$index}",
                // Deliberately the SAME name on every row: `order by name` is
                // not a total order, which is exactly what the page walk above
                // is looking for.
                'name' => 'Store',
                'is_active' => true,
            ]);
        }
    }

    /**
     * The unstable-sort case, built on purpose: ONE item held in two stores
     * plus 23 more, so `order by item_id` alone ties on the first two rows.
     */
    private function seedStockBalances(): void
    {
        $stores = [
            Warehouse::create(['code' => 'BAL-A', 'name' => 'Store A', 'is_active' => true]),
            Warehouse::create(['code' => 'BAL-B', 'name' => 'Store B', 'is_active' => true]),
        ];

        $shared = Item::create(['sku' => 'FILLER-1', 'name' => 'Shared Material', 'uom' => 'Kgs']);
        foreach ($stores as $store) {
            StockBalance::create([
                'item_id' => $shared->id, 'warehouse_id' => $store->id,
                'quantity' => '10.0000', 'average_cost' => '100.0000',
            ]);
        }

        foreach (range(2, self::ROWS - 1) as $index) {
            $item = Item::create(['sku' => "FILLER-{$index}", 'name' => "Filler {$index}", 'uom' => 'Kgs']);
            StockBalance::create([
                'item_id' => $item->id, 'warehouse_id' => $stores[0]->id,
                'quantity' => '5.0000', 'average_cost' => '50.0000',
            ]);
        }
    }
}
