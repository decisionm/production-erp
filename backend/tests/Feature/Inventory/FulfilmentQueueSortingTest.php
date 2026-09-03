<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SORTING THE STORE'S FULFILMENT QUEUE (03-Sep-2026).
 *
 * The queue is computed row by row and paged in PHP, so the only honest
 * server sorts are on the REAL columns of its base query — the order number
 * and the ordered quantity (FulfilmentQueueService::SORTABLE). A requested
 * sort replaces the queue's own order (over-reserved first, S8) with the
 * reader's; with no `sort` the queue reads exactly as it did.
 */
class FulfilmentQueueSortingTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: '1000',
            unitCost: '2.50',
            reference: 'seed',
        );

        $user = User::factory()->create(['is_active' => true]);
        Permission::findOrCreate('inventory.view', 'web');
        $user->givePermissionTo('inventory.view');
        Sanctum::actingAs($user);
    }

    private function line(string $quantity): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => $this->bottle->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => 0,
        ]);
    }

    public function test_an_unknown_sort_column_is_refused(): void
    {
        $this->getJson('/api/v1/inventory/fulfilment/queue?sort=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    public function test_quantity_descending_ties_break_on_newest_line_id(): void
    {
        $firstOfTwo = $this->line('200');
        $largest = $this->line('500');
        $secondOfTwo = $this->line('200');

        $response = $this->getJson('/api/v1/inventory/fulfilment/queue?sort=-quantity')->assertOk();

        $this->assertSame(
            [$largest->id, $secondOfTwo->id, $firstOfTwo->id],
            collect($response->json('data'))->pluck('line_id')->all(),
        );
    }

    public function test_order_number_descending_and_the_default_stays_order_book_order(): void
    {
        $first = $this->line('100');
        $second = $this->line('100');
        $third = $this->line('100');

        $descending = $this->getJson('/api/v1/inventory/fulfilment/queue?sort=-sales_order_id')->assertOk();
        $this->assertSame(
            [$third->id, $second->id, $first->id],
            collect($descending->json('data'))->pluck('line_id')->all(),
        );

        $default = $this->getJson('/api/v1/inventory/fulfilment/queue')->assertOk();
        $this->assertSame(
            [$first->id, $second->id, $third->id],
            collect($default->json('data'))->pluck('line_id')->all(),
        );
    }

    public function test_page_size_is_honoured_and_the_total_is_the_whole_queue(): void
    {
        $this->line('100');
        $this->line('100');
        $this->line('100');

        $response = $this->getJson('/api/v1/inventory/fulfilment/queue?sort=quantity&per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }
}
