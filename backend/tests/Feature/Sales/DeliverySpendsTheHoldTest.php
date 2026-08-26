<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockReservationStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Models\Enums\ProductionRequestStatus;
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
 * THE DELIVERY IS THE ONE EVENT THAT SPENDS A HOLD — over the wire, through
 * the dispatch flow the store already uses.
 *
 * DISPATCH ITSELF IS UNCHANGED AND UNGATED (Q27 untouched). A delivery with
 * no hold behind it posts exactly as it always did; a delivery bigger than
 * its holds posts too, and the surplus is simply not absorbed by anything.
 * Nothing here refuses a real dispatch over paperwork.
 *
 * The three things this pins:
 *   1. the hold is consumed, oldest first, and the balance falls ONCE (the
 *      delivery moved it — the hold did not);
 *   2. S3, THE WAREHOUSE MISMATCH: a van that loaded somewhere else spends
 *      no hold and is not an error;
 *   3. S1, THE LINE'S COVERAGE: a delivery that finishes the line hands its
 *      leftover holds back and marks the line's open production request
 *      produced.
 */
class DeliverySpendsTheHoldTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    private Warehouse $depot;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        // The ONE Tally-linked warehouse is the finished-goods store; the
        // depot deliberately has no Tally link, so the resolver still has
        // exactly one candidate and the mismatch case below is a real
        // mismatch rather than an unconfigured factory.
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->depot = Warehouse::create(['code' => 'DEPOT', 'name' => 'Depot']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);
    }

    public function test_a_dispatch_spends_the_oldest_hold_first_and_moves_the_stock_once(): void
    {
        $this->seedStock($this->fg, '200');
        $order = $this->order();
        $line = $this->lineOn($order, '100');

        $first = app(StockReservationService::class)->reserve($line, '40', null);
        $second = app(StockReservationService::class)->reserve($line, '60', null);

        $this->actingWith(['sales.manage']);

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '50']],
        ])->assertSuccessful();

        $this->assertSame('40.0000', $first->fresh()->consumed_quantity);
        $this->assertSame(StockReservationStatus::Consumed, $first->fresh()->status);
        $this->assertSame('10.0000', $second->fresh()->consumed_quantity);
        $this->assertSame(StockReservationStatus::Active, $second->fresh()->status);

        // The delivery moved the stock; consuming the hold moved nothing.
        $this->assertSame('150.0000', $this->balance($this->fg));
    }

    /**
     * S3. A delivery dispatched from a warehouse this line holds nothing in
     * is a LEGAL dispatch — the holds sit in the FG store and the van loaded
     * from the depot. It spends no hold, and refusing it would block a real
     * dispatch over paperwork.
     */
    public function test_a_dispatch_from_another_warehouse_spends_no_hold_and_is_not_an_error(): void
    {
        $this->seedStock($this->fg, '200');
        $this->seedStock($this->depot, '200');
        $order = $this->order();
        $line = $this->lineOn($order, '100');
        $hold = app(StockReservationService::class)->reserve($line, '100', null);

        $this->actingWith(['sales.manage']);

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->depot->id,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '40']],
        ])->assertSuccessful();

        $this->assertSame('0.0000', $hold->fresh()->consumed_quantity);
        $this->assertSame(StockReservationStatus::Active, $hold->fresh()->status);
        // The depot's shelf fell, the FG store's did not.
        $this->assertSame('160.0000', $this->balance($this->depot));
        $this->assertSame('200.0000', $this->balance($this->fg));
    }

    /**
     * A FINISHED LINE GIVES ITS LEFTOVERS BACK and stops asking the floor
     * for anything — S1, judged on the LINE, never on the request.
     *
     * The line is finished by TWO dispatches, and that is the point: the
     * first loaded at the depot and spent no hold, so when the second one
     * finishes the line there is still a live hold standing against it. That
     * leftover is exactly what must be handed back — stock held for an order
     * that has already shipped is stock held away from an order that could
     * still use it.
     */
    public function test_finishing_the_line_releases_its_leftovers_and_marks_its_request_produced(): void
    {
        $this->seedStock($this->fg, '300');
        $this->seedStock($this->depot, '100');
        $order = $this->order();
        $line = $this->lineOn($order, '100');

        // A request raised on ANOTHER line of the same order, while that
        // line is short. It must survive this line being finished.
        $otherLinesRequest = app(ProductionRequestService::class)->createFromShortfall(
            $this->lineOn($order, '50'),
            '50',
            null,
        );

        $spent = app(StockReservationService::class)->reserve($line, '60', null);
        // Short by 40 at this moment — so the floor is asked for 40.
        $ownRequest = app(ProductionRequestService::class)->createFromShortfall($line, '40', null);
        // ...and then 40 pieces turn up and are held after all.
        $leftover = app(StockReservationService::class)->reserve($line, '40', null);

        $this->actingWith(['sales.manage']);

        // 40 out of the depot: spends no hold (S3).
        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->depot->id,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '40']],
        ])->assertSuccessful();

        $this->assertSame(StockReservationStatus::Active, $leftover->fresh()->status);

        // 60 out of the FG store finishes the line.
        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '60']],
        ])->assertSuccessful();

        $this->assertSame(StockReservationStatus::Consumed, $spent->fresh()->status);
        $this->assertSame(StockReservationStatus::Released, $leftover->fresh()->status);
        $this->assertSame('line_fulfilled', $leftover->fresh()->released_reason);

        // This line's own request is answered; the OTHER line's is not
        // touched, because coverage is judged per line (S1).
        $this->assertSame(ProductionRequestStatus::Produced, $ownRequest->fresh()->status);
        $this->assertSame(ProductionRequestStatus::Queued, $otherLinesRequest->fresh()->status);
    }

    /** A dispatch with no hold behind it posts exactly as it always did. */
    public function test_a_dispatch_with_no_hold_behind_it_is_untouched(): void
    {
        $this->seedStock($this->fg, '200');
        $order = $this->order();
        $line = $this->lineOn($order, '100');

        $this->actingWith(['sales.manage']);

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '100']],
        ])->assertSuccessful();

        $this->assertSame('100.0000', $this->balance($this->fg));
        $this->assertSame(SalesOrderStatus::Completed, $order->fresh()->status);
    }

    // ---- fixtures ----------------------------------------------------------

    private function seedStock(Warehouse $warehouse, string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id,
            warehouseId: $warehouse->id,
            quantity: $quantity,
            unitCost: '2.50',
            reference: 'seed',
        );
    }

    private function balance(Warehouse $warehouse): string
    {
        return (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('quantity');
    }

    private function order(): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);
    }

    private function lineOn(SalesOrder $order, string $quantity): SalesOrderLine
    {
        return $order->lines()->create([
            'item_id' => $this->bottle->id,
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
