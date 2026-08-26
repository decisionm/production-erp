<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `ready_for_dispatch` ON THE SALES ORDER — the badge the order list prints
 * when EVERY line is covered by what has already been delivered plus what is
 * still held for it.
 *
 * IT GATES NOTHING (Q27 untouched). Dispatch is the Delivery flow, and that
 * flow refuses and permits exactly what it did before this build. The badge
 * tells a sales desk it can promise a van; it does not stop one.
 *
 * The two edges that matter, because both would badge an order that is not
 * ready:
 *   - ONE uncovered line is enough to answer false;
 *   - an order with NO LINES is false, not true. "Every line of none is
 *     covered" is technically so and operationally a badge on a blank page.
 *
 * And a draft, a cancelled or a completed order answers false without asking
 * anything at all — there is nothing to dispatch, nothing will be, or it
 * already went.
 */
class ReadyForDispatchBadgeTest extends TestCase
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

    public function test_an_order_whose_every_line_is_held_is_ready(): void
    {
        $this->seedStock('500');
        $order = $this->order();
        $bottles = $this->lineOn($order, $this->bottle, '100');
        $jars = $this->lineOn($order, $this->jar, '50');

        app(StockReservationService::class)->reserve($bottles, '100', null);
        $this->seedStock('50', $this->jar);
        app(StockReservationService::class)->reserve($jars, '50', null);

        $this->actingWith(['sales.view']);

        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.ready_for_dispatch', true);
    }

    public function test_one_uncovered_line_is_enough_to_answer_no(): void
    {
        $this->seedStock('500');
        $order = $this->order();
        $bottles = $this->lineOn($order, $this->bottle, '100');
        $this->lineOn($order, $this->jar, '50');

        app(StockReservationService::class)->reserve($bottles, '100', null);

        $this->actingWith(['sales.view']);

        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.ready_for_dispatch', false);
    }

    /**
     * DELIVERED PIECES COUNT TOWARDS COVERAGE. A partially delivered line
     * with the remainder held is ready — the van can finish the job.
     */
    public function test_what_has_already_shipped_counts_towards_coverage(): void
    {
        $this->seedStock('500');
        $order = $this->order();
        $line = $this->lineOn($order, $this->bottle, '100');
        $line->update(['quantity_delivered' => '70']);
        $order->update(['status' => SalesOrderStatus::PartiallyDelivered]);

        app(StockReservationService::class)->reserve($line, '30', null);

        $this->actingWith(['sales.view']);

        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.ready_for_dispatch', true);
    }

    public function test_an_order_with_no_lines_is_never_badged_ready(): void
    {
        $order = $this->order();

        $this->actingWith(['sales.view']);

        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.ready_for_dispatch', false);
    }

    public function test_a_draft_a_completed_and_a_cancelled_order_are_never_ready(): void
    {
        $this->seedStock('500');

        $this->actingWith(['sales.view']);

        foreach ([SalesOrderStatus::Draft, SalesOrderStatus::Completed, SalesOrderStatus::Cancelled] as $status) {
            $order = $this->order($status);
            $line = $this->lineOn($order, $this->bottle, '10');
            // Held in full, so only the STATUS can be the reason for a no.
            if ($status === SalesOrderStatus::Completed) {
                $line->update(['quantity_delivered' => '10']);
            }

            $this->getJson("/api/v1/sales/sales-orders/{$order->id}")
                ->assertOk()
                ->assertJsonPath('data.ready_for_dispatch', false);
        }
    }

    public function test_the_badge_rides_on_the_list_as_well_as_the_drawer(): void
    {
        $this->seedStock('500');
        $order = $this->order();
        $line = $this->lineOn($order, $this->bottle, '100');
        app(StockReservationService::class)->reserve($line, '100', null);

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/sales-orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.ready_for_dispatch', true);
    }

    // ---- fixtures ----------------------------------------------------------

    private function seedStock(string $quantity, ?Item $item = null): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: ($item ?? $this->bottle)->id,
            warehouseId: $this->fg->id,
            quantity: $quantity,
            unitCost: '2.50',
            reference: 'seed',
        );
    }

    private function order(SalesOrderStatus $status = SalesOrderStatus::Confirmed): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => $status,
            'order_date' => '2026-08-20',
        ]);
    }

    private function lineOn(SalesOrder $order, Item $item, string $quantity): SalesOrderLine
    {
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
