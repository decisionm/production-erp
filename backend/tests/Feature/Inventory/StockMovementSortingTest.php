<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SORTING THE STOCK LEDGER, on a list the server paginates (03-Sep-2026).
 *
 * The ledger is paged, so ordering it in the browser would order the twenty
 * rows that had arrived and present that as the order of the factory's
 * history. `sort` is validated (ListStockMovementsRequest) and applied by
 * the service through ListSort: an unknown column is a 422, a known one
 * orders the WHOLE ledger with `id desc` as the tiebreak, and the page size
 * still comes from the request.
 */
class StockMovementSortingTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->item = Item::create(['sku' => 'SYN-SORT', 'name' => 'Sorting Resin', 'uom' => 'Kgs.', 'is_active' => true]);
        $this->store = Warehouse::create(['code' => 'SORT-RM', 'name' => 'Sorting Store', 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    private function movement(string $quantity, string $date, string $type = 'receipt'): StockMovement
    {
        return StockMovement::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->store->id,
            'type' => $type,
            'purpose' => $type === 'receipt' ? 'receipt' : 'issue_to_production',
            'quantity' => $quantity,
            'movement_date' => $date,
            'reference' => 'sort-'.$quantity,
        ]);
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->getJson('/api/v1/inventory/stock-movements?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_quantity_descending_ties_break_on_newest_id(): void
    {
        $first = $this->movement('50', '2026-08-01 10:00:00');
        $second = $this->movement('50', '2026-08-02 10:00:00');
        $largest = $this->movement('120', '2026-07-30 10:00:00');

        $response = $this->getJson('/api/v1/inventory/stock-movements?sort=-quantity')->assertOk();

        $this->assertSame(
            [$largest->id, $second->id, $first->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_the_default_order_is_still_newest_movement_first(): void
    {
        $older = $this->movement('10', '2026-08-01 10:00:00');
        $newest = $this->movement('20', '2026-08-03 10:00:00');
        $middle = $this->movement('30', '2026-08-02 10:00:00');

        $response = $this->getJson('/api/v1/inventory/stock-movements')->assertOk();

        $this->assertSame(
            [$newest->id, $middle->id, $older->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_type_sorts_ascending_with_the_pager_describing_the_whole_ledger(): void
    {
        $this->movement('10', '2026-08-01 10:00:00', 'receipt');
        $this->movement('20', '2026-08-02 10:00:00', 'issue');
        $this->movement('30', '2026-08-03 10:00:00', 'receipt');

        $response = $this->getJson('/api/v1/inventory/stock-movements?sort=type&per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(['issue', 'receipt'], collect($response->json('data'))->pluck('type')->all());
    }
}
