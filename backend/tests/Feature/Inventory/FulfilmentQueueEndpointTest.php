<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\FulfilmentQueueService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /inventory/fulfilment/queue — THE STORE'S QUEUE.
 *
 * What this file pins, beyond the row shape:
 *
 *   S16  a fully allocated line needs nothing from the store, so it is
 *        HIDDEN by default — but reachable by name, because "hidden" must
 *        never mean "lost";
 *   S8   an over-reserved line goes to the TOP whatever page it would
 *        otherwise fall on, and only a line that actually holds some of the
 *        over-promised item is called over-reserved;
 *   can  every ability is the predicate the WRITE refuses on, so the button
 *        and the 422 cannot tell two stories. The pair the screen turns on:
 *        no free stock plus a shortfall means reserve is false and
 *        send_to_production is true.
 *
 * And the wall: the store reads and writes this queue (inventory), the
 * production floor does not, and a read-only login can never hold stock.
 */
class FulfilmentQueueEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $jar;

    private Warehouse $fg;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->jar = Item::create(['sku' => 'JAR-1L', 'name' => '1L PET Jar', 'uom' => 'Nos']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);
    }

    public function test_an_untouched_line_reads_the_whole_row(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')
            ->assertOk()
            ->assertJsonPath('data.0.line_id', $line->id)
            ->assertJsonPath('data.0.sales_order_id', $line->sales_order_id)
            ->assertJsonPath('data.0.customer.name', 'Aqua Traders')
            ->assertJsonPath('data.0.item.sku', 'BTL-500')
            ->assertJsonPath('data.0.ordered', '200.0000')
            ->assertJsonPath('data.0.delivered', '0.0000')
            ->assertJsonPath('data.0.reserved', '0.0000')
            ->assertJsonPath('data.0.shortfall', '200.0000')
            ->assertJsonPath('data.0.free', '500.0000')
            ->assertJsonPath('data.0.over_reserved', '0.0000')
            ->assertJsonPath('data.0.fulfilment_state', FulfilmentQueueService::STATE_UNTOUCHED)
            ->assertJsonPath('data.0.holds', [])
            ->assertJsonPath('data.0.request', null)
            ->assertJsonPath('data.0.can.reserve', true)
            ->assertJsonPath('data.0.can.release', false)
            ->assertJsonPath('data.0.can.repoint', false)
            ->assertJsonPath('data.0.can.send_to_production', true);
    }

    public function test_a_partly_held_line_prints_who_it_is_held_for_and_since_when(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');
        $hold = app(StockReservationService::class)->reserve($line, '80', null);

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')
            ->assertOk()
            ->assertJsonPath('data.0.reserved', '80.0000')
            ->assertJsonPath('data.0.shortfall', '120.0000')
            ->assertJsonPath('data.0.fulfilment_state', FulfilmentQueueService::STATE_PARTIALLY_ALLOCATED)
            ->assertJsonPath('data.0.holds.0.reservation_id', $hold->id)
            ->assertJsonPath('data.0.holds.0.quantity', '80.0000')
            ->assertJsonPath('data.0.holds.0.consumed_quantity', '0.0000')
            ->assertJsonPath('data.0.holds.0.customer.name', 'Aqua Traders')
            ->assertJsonPath('data.0.holds.0.sales_order_id', $line->sales_order_id)
            ->assertJsonPath('data.0.can.release', true)
            ->assertJsonPath('data.0.can.repoint', true);
    }

    /**
     * THE PAIR THE STORE'S SCREEN TURNS ON: nothing free and something short
     * means the answer is not "hold it", it is "make it".
     */
    public function test_with_nothing_free_the_row_offers_production_rather_than_a_hold(): void
    {
        $line = $this->line($this->bottle, '200');

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')
            ->assertOk()
            ->assertJsonPath('data.0.free', '0.0000')
            ->assertJsonPath('data.0.can.reserve', false)
            ->assertJsonPath('data.0.can.send_to_production', true);

        app(ProductionRequestService::class)->createFromShortfall($line, '200', null);

        $this->getJson('/api/v1/inventory/fulfilment/queue')
            ->assertOk()
            ->assertJsonPath('data.0.fulfilment_state', FulfilmentQueueService::STATE_AWAITING_PRODUCTION)
            ->assertJsonPath('data.0.request.quantity', '200.0000')
            ->assertJsonPath('data.0.request.status', 'queued')
            ->assertJsonPath('data.0.request.priority', 1)
            // One open request per line: the row stops offering a second.
            ->assertJsonPath('data.0.can.send_to_production', false);
    }

    /** S16 — hidden by default, reachable by name. */
    public function test_a_fully_allocated_line_is_hidden_by_default_and_asked_for_by_name(): void
    {
        $this->seedStock($this->bottle, '500');
        $covered = $this->line($this->bottle, '100');
        app(StockReservationService::class)->reserve($covered, '100', null);
        $short = $this->line($this->jar, '50');

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.line_id', $short->id);

        $this->getJson('/api/v1/inventory/fulfilment/queue?state='.FulfilmentQueueService::STATE_FULLY_ALLOCATED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.line_id', $covered->id)
            ->assertJsonPath('data.0.can.reserve', false)
            ->assertJsonPath('data.0.can.send_to_production', false);
    }

    /**
     * S8. The over-promised line is at the TOP even though its order was
     * raised last — a decision about whose order gives way is not a row to
     * discover on page four.
     */
    public function test_an_over_reserved_line_sorts_first(): void
    {
        $this->seedStock($this->jar, '500');
        $this->line($this->jar, '100');

        $this->seedStock($this->bottle, '100');
        $trouble = $this->line($this->bottle, '100');
        app(StockReservationService::class)->reserve($trouble, '100', null);
        // QC nets the shelf away after the hold was taken.
        app(StockMovementService::class)->recordIssue(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: '70',
            reference: 'qc netting',
        );

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')
            ->assertOk()
            ->assertJsonPath('data.0.line_id', $trouble->id)
            ->assertJsonPath('data.0.fulfilment_state', FulfilmentQueueService::STATE_OVER_RESERVED)
            ->assertJsonPath('data.0.over_reserved', '70.0000');
    }

    /**
     * AND IT IS SAID ONLY OF A LINE THAT HOLDS SOME. The over-reservation is
     * a figure about the ITEM; painting every line of a busy product red
     * would make the word mean "popular" instead of "promised twice".
     */
    public function test_a_line_holding_none_of_an_over_promised_item_is_not_called_over_reserved(): void
    {
        $this->seedStock($this->bottle, '100');
        $holder = $this->line($this->bottle, '100');
        app(StockReservationService::class)->reserve($holder, '100', null);
        app(StockMovementService::class)->recordIssue(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: '70',
            reference: 'qc netting',
        );
        $bystander = $this->line($this->bottle, '40');

        $this->actingWith(['inventory.view']);

        $body = $this->getJson('/api/v1/inventory/fulfilment/queue')->assertOk()->json('data');
        $states = collect($body)->pluck('fulfilment_state', 'line_id');

        $this->assertSame(FulfilmentQueueService::STATE_OVER_RESERVED, $states[$holder->id]);
        $this->assertSame(FulfilmentQueueService::STATE_UNTOUCHED, $states[$bystander->id]);
    }

    public function test_only_live_orders_are_in_the_queue(): void
    {
        $this->line($this->bottle, '100', SalesOrderStatus::Draft);
        $this->line($this->bottle, '100', SalesOrderStatus::Cancelled);

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_state_that_does_not_exist_is_refused_rather_than_answered_empty(): void
    {
        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue?state=nearly_there')
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');
    }

    /**
     * The queue cannot answer at all until somebody has said which warehouse
     * IS the finished-goods store — and it says so in a 422 naming the fix,
     * never a 500 and never an empty screen that reads as "no work".
     */
    public function test_an_unconfigured_finished_goods_store_refuses_with_the_settings_fix(): void
    {
        // A second Tally-linked warehouse: the sole-warehouse fallback can no
        // longer choose, and nothing is configured.
        Warehouse::create(['code' => 'FG2', 'name' => 'Second Store', 'tally_guid' => 'gd-fg2']);
        $this->line($this->bottle, '100');

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');
    }

    // ---- the permission wall, both ways ------------------------------------

    public function test_the_store_reads_the_queue_with_inventory_view(): void
    {
        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')->assertOk();
    }

    public function test_the_floor_and_the_sales_desk_do_not_read_the_stores_queue(): void
    {
        $this->actingWith(['production.manage', 'sales.manage']);

        $this->getJson('/api/v1/inventory/fulfilment/queue')->assertStatus(403);
        $this->getJson('/api/v1/inventory/fulfilment/planning')->assertStatus(403);
    }

    public function test_a_read_only_store_login_can_never_hold_stock(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '100');

        $this->actingWith(['inventory.view']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '10'])
            ->assertStatus(403);
        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/send-to-production", ['quantity' => '10'])
            ->assertStatus(403);
    }

    /** FC-06: the store's screen carries no money of any kind. */
    public function test_the_queue_carries_no_rate_no_cost_and_no_vendor(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');
        app(StockReservationService::class)->reserve($line, '80', null);

        $this->actingWith(['inventory.view']);

        $body = json_encode($this->getJson('/api/v1/inventory/fulfilment/queue')->assertOk()->json());

        foreach (['unit_price', 'rate', 'cost', 'amount', 'vendor', 'supplier'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "FC-06: the queue must not print {$forbidden}");
        }
    }

    // ---- fixtures ----------------------------------------------------------

    private function seedStock(Item $item, string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $item->id,
            warehouseId: $this->fg->id,
            quantity: $quantity,
            unitCost: '2.50',
            reference: 'seed',
        );
    }

    private function line(
        Item $item,
        string $quantity,
        SalesOrderStatus $status = SalesOrderStatus::Confirmed,
    ): SalesOrderLine {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => $status,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => 0,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
