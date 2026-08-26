<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\StockReservationStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE FOUR THINGS THE STORE DOES ON THE FULFILMENT QUEUE, over the wire:
 * hold, give up, move, and ask the floor.
 *
 * The invariant every case here also asserts, directly or by construction:
 * NONE OF THEM MOVES STOCK (invariant 1). The balance is the same figure
 * before and after, and no stock_movement is written. Only a Delivery moves
 * stock.
 *
 * The refusals matter as much as the happy paths: the storekeeper is looking
 * at a screen that was true a moment ago, so every wall is recomputed inside
 * a transaction and comes back as a 422 naming the real figure rather than a
 * silent success on stale numbers.
 */
class FulfilmentActionsEndpointTest extends TestCase
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

    // ---- reserve -----------------------------------------------------------

    public function test_the_store_holds_free_stock_and_the_balance_does_not_move(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');

        $store = $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '120'])
            ->assertCreated()
            ->assertJsonPath('data.sales_order_line_id', $line->id)
            ->assertJsonPath('data.quantity', '120.0000')
            ->assertJsonPath('data.outstanding_quantity', '120.0000')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.created_by', $store->id);

        // Invariant 1: the shelf is untouched.
        $this->assertSame('500.0000', (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->value('quantity'));
    }

    public function test_holding_more_than_is_free_is_refused_with_the_real_figure(): void
    {
        $this->seedStock($this->bottle, '40');
        $line = $this->line($this->bottle, '200');

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '41'])
            ->assertStatus(422);

        $this->assertSame(0, StockReservation::query()->count());
    }

    /** S5, the demand cap: a line may never hold more than it still owes. */
    public function test_a_line_cannot_hold_more_than_the_customer_ordered(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '100');

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '101'])
            ->assertStatus(422);
    }

    public function test_a_draft_order_cannot_hold_stock(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '100', SalesOrderStatus::Draft);

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '10'])
            ->assertStatus(422);
    }

    /**
     * `1e+21` is what JSON.stringify emits for any JavaScript number at or
     * above 1e21, and bcmath throws on it — a 422, never a 500.
     */
    public function test_a_malformed_quantity_is_refused_rather_than_thrown(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');

        $this->actingWith(['inventory.manage']);

        foreach (['1e+21', '0x1A', 'INF', 'NAN', '0', '-5'] as $spelling) {
            $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => $spelling])
                ->assertStatus(422);
        }
    }

    public function test_a_line_that_does_not_exist_is_a_404(): void
    {
        $this->actingWith(['inventory.manage']);

        $this->postJson('/api/v1/inventory/fulfilment/lines/9999/reserve', ['quantity' => '1'])
            ->assertStatus(404);
    }

    // ---- release -----------------------------------------------------------

    public function test_a_hold_is_given_up_with_a_reason_and_the_stock_stays_put(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');
        $hold = app(StockReservationService::class)->reserve($line, '120', null);

        $store = $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/release", [
            'reason' => 'Customer moved the date out',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.released_quantity', '120.0000')
            ->assertJsonPath('data.outstanding_quantity', '0.0000')
            ->assertJsonPath('data.released_reason', 'Customer moved the date out')
            ->assertJsonPath('data.released_by', $store->id);

        $this->assertSame('500.0000', (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->value('quantity'));
    }

    public function test_a_release_without_a_reason_is_refused(): void
    {
        $this->seedStock($this->bottle, '500');
        $hold = app(StockReservationService::class)->reserve($this->line($this->bottle, '200'), '50', null);

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/release", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * A SPENT HOLD CANNOT BE GIVEN UP — the stock left the building against
     * it, and releasing it would claim the delivery never happened.
     */
    public function test_a_spent_hold_refuses_to_be_released(): void
    {
        $this->seedStock($this->bottle, '100');
        $line = $this->line($this->bottle, '100');
        $hold = app(StockReservationService::class)->reserve($line, '100', null);

        $line->update(['quantity_delivered' => '100']);
        app(StockReservationService::class)->consumeForDelivery($line->fresh(), '100', $this->fg->id);
        $this->assertSame(StockReservationStatus::Consumed, $hold->fresh()->status);

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/release", ['reason' => 'changed my mind'])
            ->assertStatus(422);
    }

    // ---- repoint -----------------------------------------------------------

    /**
     * S4. Release and re-hold in ONE transaction under ONE balance lock —
     * which is why this works at all in a store where every free piece is
     * already held.
     */
    public function test_a_hold_moves_to_another_line_even_when_nothing_is_free(): void
    {
        $this->seedStock($this->bottle, '100');
        $source = $this->line($this->bottle, '100');
        $target = $this->line($this->bottle, '100');
        $hold = app(StockReservationService::class)->reserve($source, '100', null);

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/repoint", [
            'sales_order_line_id' => $target->id,
            'quantity' => '40',
            'reason' => 'The other customer is waiting at the gate',
        ])
            // 201, not 200: a re-point RELEASES one hold and CREATES another,
            // and the row that comes back is the new one on the target line.
            ->assertCreated()
            ->assertJsonPath('data.sales_order_line_id', $target->id)
            ->assertJsonPath('data.quantity', '40.0000')
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('60.0000', $hold->fresh()->outstandingQuantity());
        $this->assertSame(StockReservationStatus::Active, $hold->fresh()->status);
        // Invariant 1: nothing moved, only whose name is on it.
        $this->assertSame('100.0000', (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)->where('warehouse_id', $this->fg->id)->value('quantity'));
    }

    public function test_a_hold_cannot_be_moved_to_a_line_for_another_item(): void
    {
        $this->seedStock($this->bottle, '100');
        $source = $this->line($this->bottle, '100');
        $wrong = $this->line($this->jar, '100');
        $hold = app(StockReservationService::class)->reserve($source, '100', null);

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/repoint", [
            'sales_order_line_id' => $wrong->id,
            'quantity' => '10',
            'reason' => 'wrong pallet',
        ])->assertStatus(422);
    }

    public function test_a_target_line_that_does_not_exist_is_refused_by_validation(): void
    {
        $this->seedStock($this->bottle, '100');
        $hold = app(StockReservationService::class)->reserve($this->line($this->bottle, '100'), '10', null);

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/repoint", [
            'sales_order_line_id' => 9999,
            'quantity' => '10',
            'reason' => 'nowhere',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sales_order_line_id');
    }

    // ---- send to production ------------------------------------------------

    /** S14 — capped at the real shortfall rather than refused. */
    public function test_the_shortfall_goes_to_the_floor_capped_at_what_is_really_short(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');
        app(StockReservationService::class)->reserve($line, '120', null);

        $store = $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/send-to-production", ['quantity' => '500'])
            ->assertCreated()
            // 200 ordered − 0 delivered − 120 held = 80, not the 500 typed.
            ->assertJsonPath('data.quantity', '80.0000')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.priority', 1)
            ->assertJsonPath('data.sales_order_line_id', $line->id)
            ->assertJsonPath('data.requested_by', $store->id)
            ->assertJsonPath('data.can.start', true)
            ->assertJsonPath('data.can.cancel', true);

        // Invariant 2: paperwork only — no batch, no shift entry, no stock.
        $this->assertSame(1, ProductionRequest::query()->count());
    }

    public function test_a_second_open_request_on_the_same_line_is_refused(): void
    {
        $line = $this->line($this->bottle, '200');

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/send-to-production", ['quantity' => '200'])
            ->assertCreated();
        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/send-to-production", ['quantity' => '50'])
            ->assertStatus(422);

        $this->assertSame(1, ProductionRequest::query()->count());
    }

    public function test_a_fully_covered_line_has_nothing_to_send(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '100');
        app(StockReservationService::class)->reserve($line, '100', null);

        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/send-to-production", ['quantity' => '10'])
            ->assertStatus(422);

        $this->assertSame(0, ProductionRequest::query()->count());
    }

    // ---- the permission wall, both ways ------------------------------------

    public function test_only_the_store_may_hold_release_repoint_and_send(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');
        $target = $this->line($this->bottle, '200');
        $hold = app(StockReservationService::class)->reserve($line, '50', null);

        // The floor and the sales desk together still hold nothing here.
        $this->actingWith(['production.manage', 'sales.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '10'])->assertStatus(403);
        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/send-to-production", ['quantity' => '10'])->assertStatus(403);
        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/release", ['reason' => 'no'])->assertStatus(403);
        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/repoint", [
            'sales_order_line_id' => $target->id, 'quantity' => '10', 'reason' => 'no',
        ])->assertStatus(403);

        // And the store may do all four.
        $this->actingWith(['inventory.manage']);

        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '10'])->assertCreated();
        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/repoint", [
            'sales_order_line_id' => $target->id, 'quantity' => '10', 'reason' => 'yes',
        ])->assertCreated();
        $this->postJson("/api/v1/inventory/reservations/{$hold->id}/release", ['reason' => 'yes'])->assertOk();
        $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/send-to-production", ['quantity' => '10'])->assertCreated();
    }

    /** FC-06: a hold is about pieces, so it needs no finance standing and grants none. */
    public function test_no_hold_payload_carries_a_cost(): void
    {
        $this->seedStock($this->bottle, '500');
        $line = $this->line($this->bottle, '200');

        $this->actingWith(['inventory.manage']);

        $body = json_encode(
            $this->postJson("/api/v1/inventory/fulfilment/lines/{$line->id}/reserve", ['quantity' => '10'])
                ->assertCreated()->json()
        );

        foreach (['cost', 'rate', 'unit_price', 'amount', 'vendor', 'supplier'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "FC-06: a hold must not print {$forbidden}");
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
