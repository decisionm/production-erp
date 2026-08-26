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
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `?search[]=RM` IS A 422, NOT A 500 AND NEVER A WIDER LIST.
 *
 * A query string carries arrays: `?search[]=RM` and `?search[a]=RM` both
 * arrive as a PHP array, and every filter on these four lists read its value
 * through a cast that assumes a scalar. `(string) []` raises "Array to string
 * conversion", which this app's error handler turns into a 500 — an
 * unauthenticated-shaped crash on a plain GET that any client can trigger by
 * repeating a parameter. `(int) ['5']` is quieter and worse: it is 1, with no
 * warning at all, so `?item_id[]=5` answered with ITEM 1's batches and said
 * nothing.
 *
 * REFUSED RATHER THAN IGNORED, and the direction of failure is the reason.
 * A filter that fails open returns MORE than the caller asked for: neutralise
 * `?code[]=x` to null and a scanner's exact-match question becomes "page one
 * of everything", which reads on screen as a successful scan of the wrong
 * thing. `per_page` is the opposite direction — falling back to the
 * documented default serves the normal page, never a wider one — which is why
 * it keeps the neutralising it already had (see `Controller::perPage`).
 *
 * This is the same rule PR #30 applied to a malformed FIGURE
 * (EveryQuantityDoorRefusesAMalformedNumberTest): input the layer below
 * cannot represent is answered at the door, in the request's own words.
 *
 * NOTHING HERE IS AUTHORISATION. Every request below is authenticated and
 * permitted; a refusal is about the shape of the parameter, and no row is
 * read, written or revealed either way.
 */
class EveryListFilterRefusesAnArrayTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $this->item = Item::create([
            'sku' => 'RM-ARR', 'name' => 'Array Filter Resin', 'uom' => 'Kgs.',
            'is_active' => true, 'tracking_type' => ItemTrackingType::Batch,
        ]);

        Warehouse::create(['code' => 'ARR-WH', 'name' => 'Array Store', 'is_active' => true, 'tally_guid' => 'arr-gd']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function filters(): array
    {
        return [
            'batches search' => ['/api/v1/inventory/batches', 'search'],
            'batches code' => ['/api/v1/inventory/batches', 'code'],
            'batches item_id' => ['/api/v1/inventory/batches', 'item_id'],
            'serial numbers search' => ['/api/v1/inventory/serial-numbers', 'search'],
            'serial numbers code' => ['/api/v1/inventory/serial-numbers', 'code'],
            'serial numbers item_id' => ['/api/v1/inventory/serial-numbers', 'item_id'],
            'stock balances search' => ['/api/v1/inventory/stock-balances', 'search'],
            'stock balances item_id' => ['/api/v1/inventory/stock-balances', 'item_id'],
            'warehouses search' => ['/api/v1/inventory/warehouses', 'search'],
            'stock movements item_id' => ['/api/v1/inventory/stock-movements', 'item_id'],
            'stock movements warehouse_id' => ['/api/v1/inventory/stock-movements', 'warehouse_id'],
        ];
    }

    /**
     * THE LIST-SHAPED ARRAY — `?search[]=RM`, what a client repeating a
     * parameter or serialising a multi-select actually sends.
     */
    #[DataProvider('filters')]
    public function test_a_list_filter_given_an_array_is_refused_not_crashed(string $url, string $key): void
    {
        $this->getJson("{$url}?{$key}[]=RM")
            ->assertStatus(422)
            ->assertJsonValidationErrors($key);
    }

    /** The map-shaped array — the same value, spelled the other way. */
    #[DataProvider('filters')]
    public function test_a_list_filter_given_a_keyed_array_is_refused_not_crashed(string $url, string $key): void
    {
        $this->getJson("{$url}?{$key}[any]=RM")
            ->assertStatus(422)
            ->assertJsonValidationErrors($key);
    }

    /**
     * THE REFUSAL MUST NOT COST THE ORDINARY REQUEST ANYTHING. Every one of
     * these lists is called with no parameters at all by the SPA and by
     * ApiSurfaceSmokeTest, and with plain scalar filters by the pickers.
     */
    public function test_the_same_filters_still_answer_a_scalar(): void
    {
        $batch = Batch::create(['item_id' => $this->item->id, 'batch_number' => 'B-ARR-1']);

        $this->getJson('/api/v1/inventory/batches')->assertOk();
        $this->getJson("/api/v1/inventory/batches?item_id={$this->item->id}")->assertOk()
            ->assertJsonPath('data.0.id', $batch->id);
        $this->getJson('/api/v1/inventory/batches?search=ARR')->assertOk()
            ->assertJsonPath('data.0.id', $batch->id);
        $this->getJson('/api/v1/inventory/batches?code=B-ARR-1')->assertOk()
            ->assertJsonPath('data.0.id', $batch->id);

        $this->getJson('/api/v1/inventory/serial-numbers')->assertOk();
        $this->getJson('/api/v1/inventory/stock-balances')->assertOk();
        $this->getJson('/api/v1/inventory/warehouses')->assertOk();
        $this->getJson('/api/v1/inventory/stock-movements')->assertOk();
        $this->getJson("/api/v1/inventory/stock-movements?item_id={$this->item->id}")->assertOk();
    }

    /**
     * AN EMPTY ARRAY IS STILL AN ARRAY. `?search[]=` is the shape a cleared
     * multi-select sends, and it reached the same cast — so it is refused for
     * the same reason rather than being read as "no filter".
     */
    public function test_an_empty_array_is_refused_too(): void
    {
        $this->getJson('/api/v1/inventory/batches?search[]=')
            ->assertStatus(422)
            ->assertJsonValidationErrors('search');
    }

    /**
     * A REFUSAL MUST NOT SERVE A WIDER LIST BY ACCIDENT — the failure mode
     * neutralising would have introduced. If `code[]` were read as "no code",
     * this endpoint would answer 200 with EVERY serial number in the factory
     * to a scanner that asked about one. Pinned as a refusal with no `data`
     * key at all.
     */
    public function test_a_refused_scan_returns_no_rows(): void
    {
        $serialTracked = Item::create([
            'sku' => 'SN-ARR', 'name' => 'Array Filter Unit', 'uom' => 'Nos.',
            'is_active' => true, 'tracking_type' => ItemTrackingType::Serial,
        ]);

        foreach (['SN-ARR-1', 'SN-ARR-2', 'SN-ARR-3'] as $number) {
            SerialNumber::create([
                'item_id' => $serialTracked->id,
                'serial_number' => $number,
                'status' => SerialNumberStatus::Registered,
            ]);
        }

        $response = $this->getJson('/api/v1/inventory/serial-numbers?code[]=SN-ARR-1');

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('data', $response->json());
    }

    /**
     * NO WRITE, NO SIDE EFFECT. A refused read is a read that did not happen:
     * this pins that the 422 path leaves the stock ledger exactly as it was,
     * so the guard can never be mistaken for something that touches stock.
     */
    public function test_a_refused_list_changes_no_stock(): void
    {
        $warehouse = Warehouse::where('code', 'ARR-WH')->firstOrFail();
        app(StockMovementService::class)->recordReceipt($this->item->id, $warehouse->id, '7', '100');

        $this->getJson('/api/v1/inventory/stock-balances?search[]=ARR')->assertStatus(422);

        $this->assertSame(
            '7.0000',
            (string) StockBalance::query()
                ->where('item_id', $this->item->id)
                ->where('warehouse_id', $warehouse->id)
                ->value('quantity'),
        );
    }
}
