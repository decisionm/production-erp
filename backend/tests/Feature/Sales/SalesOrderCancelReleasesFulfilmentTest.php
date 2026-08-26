<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockReservationStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\AvailabilityService;
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
 * S6 — A CANCELLED ORDER STOPS ASKING THE FACTORY FOR THINGS.
 *
 * Cancelling used to be a status change and nothing else, which was correct
 * while nothing else existed. It is not correct now: a cancelled order left
 * holding stock keeps finished goods away from orders that can still use
 * them, and one left with an open production request keeps the floor making
 * pieces for a customer who walked away. Both are silences that cost real
 * money and neither shows up anywhere until somebody counts the shelf.
 *
 * So the cancel releases every hold and withdraws every open request, in the
 * SAME transaction, under the order row the cancel already locked.
 *
 * AND STILL NO STOCK MOVES (invariant 1) and NO BATCH IS TOUCHED (invariant
 * 2): a released hold leaves the pieces where they were, and a cancelled
 * request is a word on a piece of paper.
 */
class SalesOrderCancelReleasesFulfilmentTest extends TestCase
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
    }

    public function test_cancelling_gives_every_hold_back_and_withdraws_every_open_request(): void
    {
        $this->seedStock('500');
        $order = $this->order();
        $covered = $this->lineOn($order, '100');
        $short = $this->lineOn($order, '300');

        $hold = app(StockReservationService::class)->reserve($covered, '100', null);
        $request = app(ProductionRequestService::class)->createFromShortfall($short, '300', null);

        $desk = $this->actingWith(['sales.manage']);

        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.ready_for_dispatch', false);

        $this->assertSame(StockReservationStatus::Released, $hold->fresh()->status);
        $this->assertSame('so_cancelled', $hold->fresh()->released_reason);
        // WHO cancelled is on the hold: a released hold with no author is a
        // hole the next shift cannot read.
        $this->assertSame($desk->id, $hold->fresh()->released_by);

        $this->assertSame(ProductionRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('so_cancelled', $request->fresh()->cancelled_reason);

        // The pieces are free again for somebody who can still use them —
        // and the shelf never moved.
        $this->assertSame('500.0000', app(AvailabilityService::class)->forItem($this->bottle->id)['free']);
        $this->assertSame('500.0000', (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->value('quantity'));
    }

    /**
     * A CANCEL THAT IS REFUSED CHANGES NOTHING. The order's holds are still
     * standing afterwards, because the release happens inside the same
     * transaction as the status write and behind the same guard.
     */
    public function test_a_refused_cancel_leaves_the_holds_exactly_where_they_were(): void
    {
        $this->seedStock('500');
        $order = $this->order();
        $line = $this->lineOn($order, '100');
        $hold = app(StockReservationService::class)->reserve($line, '100', null);

        // Something has already shipped, so the order is no longer
        // cancellable (SalesOrder::isCancellable).
        $line->update(['quantity_delivered' => '40']);

        $this->actingWith(['sales.manage']);

        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/cancel")->assertStatus(422);

        $this->assertSame(StockReservationStatus::Active, $hold->fresh()->status);
        $this->assertSame(SalesOrderStatus::Confirmed, $order->fresh()->status);
    }

    /** An order holding nothing cancels exactly as it always did. */
    public function test_an_order_with_no_holds_cancels_as_before(): void
    {
        $order = $this->order();
        $this->lineOn($order, '100');

        $this->actingWith(['sales.manage']);

        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    // ---- fixtures ----------------------------------------------------------

    private function seedStock(string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: $quantity,
            unitCost: '2.50',
            reference: 'seed',
        );
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
