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
 * SORTING THE STOCK LIST, on a list the server paginates.
 *
 * The reason this is a server test and not a column sorter: the balances are
 * paginated, so ordering them in the browser would order the rows that had
 * already arrived and present it as the order of the factory's stock —
 * "the largest balance" would mean the largest on the page in front of you.
 * These tests pin that the ORDER and the PAGE agree, which is the only way
 * that control can be honest.
 */
class StockBalanceSortingTest extends TestCase
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

    private function balance(string $itemName, string $warehouseCode, string $quantity): StockBalance
    {
        $item = Item::firstOrCreate(
            ['sku' => 'SYN-'.strtoupper(substr(md5($itemName), 0, 6))],
            ['name' => $itemName, 'uom' => 'Nos.', 'is_active' => true],
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

    private function names(array $json): array
    {
        return collect($json)->pluck('item.name')->all();
    }

    public function test_the_default_order_is_by_item_name_not_by_id(): void
    {
        $this->actingAsStore();
        // Created out of alphabetical order on purpose: an id order would
        // return them exactly as inserted and look sorted by accident.
        $this->balance('Zinc Stearate', 'RM', '10');
        $this->balance('Amber Bottle', 'RM', '20');
        $this->balance('Masterbatch', 'RM', '30');

        $response = $this->getJson('/api/v1/inventory/stock-balances')->assertSuccessful();

        $this->assertSame(['Amber Bottle', 'Masterbatch', 'Zinc Stearate'], $this->names($response->json('data')));
    }

    public function test_quantity_sorts_numerically_rather_than_as_text(): void
    {
        $this->actingAsStore();
        // The case a lexical sort gets wrong: "9" would outrank "100".
        $this->balance('Alpha', 'RM', '9');
        $this->balance('Beta', 'RM', '100');
        $this->balance('Gamma', 'RM', '25');

        $response = $this->getJson('/api/v1/inventory/stock-balances?sort=quantity&direction=desc')
            ->assertSuccessful();

        $this->assertSame(['Beta', 'Gamma', 'Alpha'], $this->names($response->json('data')));
    }

    public function test_the_sort_orders_every_row_not_only_the_page_in_hand(): void
    {
        $this->actingAsStore();
        // The defect this whole change exists to prevent: with three rows and
        // a page size of one, an in-browser sort of page 1 could only ever
        // return the row it already had. The server must hand back the
        // largest of ALL of them.
        $this->balance('Alpha', 'RM', '9');
        $this->balance('Beta', 'RM', '100');
        $this->balance('Gamma', 'RM', '25');

        $page1 = $this->getJson('/api/v1/inventory/stock-balances?sort=quantity&direction=desc&per_page=1')
            ->assertSuccessful();

        $this->assertSame(['Beta'], $this->names($page1->json('data')));
        $this->assertSame(3, $page1->json('meta.total'));
    }

    public function test_one_warehouse_can_be_read_on_its_own(): void
    {
        $this->actingAsStore();
        $this->balance('Amber Bottle', 'RM', '20');
        $this->balance('Amber Bottle', 'FG', '5');

        $fg = Warehouse::where('code', 'FG')->firstOrFail();
        $response = $this->getJson('/api/v1/inventory/stock-balances?warehouse_id='.$fg->id)
            ->assertSuccessful();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('FG', $response->json('data.0.warehouse.code'));
        // The pager describes the FILTERED list, not the whole one.
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_a_sort_nobody_defined_is_refused_rather_than_silently_ignored(): void
    {
        $this->actingAsStore();
        $this->balance('Amber Bottle', 'RM', '20');

        // Ignoring it would return the default order and look like an answer.
        $this->getJson('/api/v1/inventory/stock-balances?sort=whatever')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }
}
