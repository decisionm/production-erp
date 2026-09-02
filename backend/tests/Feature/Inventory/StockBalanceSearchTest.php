<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SEARCHING THE STOCK LIST, on a list the server paginates.
 *
 * The Stock page had no search at all: the largest table in the app, one row
 * per item×warehouse, and the only way to a row was to page to it. `q` is
 * the needle the shared list-state hook writes to the URL, answered by the
 * SERVER over the whole collection — so a row on page nine is found from
 * page one, and `meta.total` describes the matches rather than the factory.
 *
 * The three names it must reach: `sku`, `display_name` (what the row is
 * labelled with — itemLabel() prefers it) and `name` (the Tally wire key).
 * A search that could not find what the row says would be a search that
 * lied. Every figure here is synthetic.
 */
class StockBalanceSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStore(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function balance(string $sku, string $name, ?string $displayName, string $warehouseCode, string $quantity): StockBalance
    {
        $item = Item::firstOrCreate(
            ['sku' => $sku],
            ['name' => $name, 'display_name' => $displayName, 'uom' => 'Nos.', 'is_active' => true],
        );
        $warehouse = Warehouse::firstOrCreate(
            ['code' => $warehouseCode],
            ['name' => $warehouseCode.' Store', 'is_active' => true],
        );

        return StockBalance::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
        ]);
    }

    private function seedBalances(): void
    {
        $this->balance('BTL-1000', 'Pet Bottle 1000ml Tally', '1 Litre Bottle', 'RM', '10');
        $this->balance('BTL-1000', 'Pet Bottle 1000ml Tally', '1 Litre Bottle', 'FG', '5');
        $this->balance('CAP-28', 'Cap 28mm', null, 'RM', '400');
        $this->balance('RES-PET', 'Relpet G5801', 'PET Resin', 'RM', '2000');
    }

    private function skus(array $json): array
    {
        return collect($json)->pluck('item.sku')->all();
    }

    public function test_q_narrows_by_sku_and_the_total_counts_the_matches(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        $response = $this->getJson('/api/v1/inventory/stock-balances?q=BTL-1000')->assertSuccessful();

        $this->assertSame(['BTL-1000', 'BTL-1000'], $this->skus($response->json('data')));
        // The pager describes the MATCHES, not the whole list.
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_q_reaches_the_tally_name(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        $response = $this->getJson('/api/v1/inventory/stock-balances?q=Relpet')->assertSuccessful();

        $this->assertSame(['RES-PET'], $this->skus($response->json('data')));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_q_reaches_the_display_name_the_row_is_labelled_with(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        // "1 Litre" appears in display_name only — not in sku, not in Tally's
        // name — and it is what itemLabel() prints on the row.
        $response = $this->getJson('/api/v1/inventory/stock-balances?q='.urlencode('1 Litre'))->assertSuccessful();

        $this->assertSame(['BTL-1000', 'BTL-1000'], $this->skus($response->json('data')));
    }

    public function test_q_and_the_warehouse_filter_narrow_together(): void
    {
        $this->actingAsStore();
        $this->seedBalances();
        $fg = Warehouse::where('code', 'FG')->firstOrFail();

        $response = $this->getJson('/api/v1/inventory/stock-balances?q=BTL&warehouse_id='.$fg->id)->assertSuccessful();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('FG', $response->json('data.0.warehouse.code'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_q_finds_a_row_past_the_first_page_and_pages_the_matches(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        // Two matches, one per page: page 2 exists and holds the second one.
        $page1 = $this->getJson('/api/v1/inventory/stock-balances?q=BTL&per_page=1')->assertSuccessful();
        $this->assertCount(1, $page1->json('data'));
        $this->assertSame(2, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.last_page'));

        $page2 = $this->getJson('/api/v1/inventory/stock-balances?q=BTL&per_page=1&page=2')->assertSuccessful();
        $this->assertCount(1, $page2->json('data'));
        // A stable tie-break (item_id, warehouse_id): the two pages hold
        // different rows, never the same one twice.
        $this->assertNotSame($page1->json('data.0.id'), $page2->json('data.0.id'));
    }

    public function test_no_match_is_an_empty_page_with_a_zero_total(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        $response = $this->getJson('/api/v1/inventory/stock-balances?q=nothing-like-this')->assertSuccessful();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_an_empty_q_narrows_nothing(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        $response = $this->getJson('/api/v1/inventory/stock-balances?q=')->assertSuccessful();

        $this->assertSame(4, $response->json('meta.total'));
    }

    public function test_the_older_search_spelling_still_answers_the_same_way(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        $viaQ = $this->getJson('/api/v1/inventory/stock-balances?q=Relpet')->assertSuccessful();
        $viaSearch = $this->getJson('/api/v1/inventory/stock-balances?search=Relpet')->assertSuccessful();

        $this->assertSame($viaQ->json('data'), $viaSearch->json('data'));
    }

    public function test_a_needle_past_the_limit_is_refused(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        $this->getJson('/api/v1/inventory/stock-balances?q='.str_repeat('x', 101))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_a_list_shaped_q_is_refused_rather_than_read_as_no_filter(): void
    {
        $this->actingAsStore();
        $this->seedBalances();

        // Failing open would answer the whole list and look like a result.
        $this->getJson('/api/v1/inventory/stock-balances?q[]=BTL')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }
}
