<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Services\DispatchQualityApprovalService;
use App\Modules\Sales\Services\FulfilmentControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GET /sales/fulfilment-control — THE SHARED CONTROL VIEW.
 *
 * What this file pins:
 *
 *   the blocker    every line names WHO must act next and WHY, derived from
 *                  data this build actually holds — the headline column, and
 *                  the only one computed rather than read;
 *   the honesty    the four things this build does not record say so in
 *                  words ('not_recorded' + a reason), and are NEVER a zero.
 *                  A blank column reads as "nothing to worry about" on a
 *                  factory floor, which is the one lie this view must not
 *                  tell;
 *   the false green  a fully held line is NOT called "ready to dispatch" —
 *                  QA and customer approval are not recorded, so the summary
 *                  says to confirm both off-system;
 *   the four teams  Sales, Store, Production and Quality all read the same
 *                  row, and none of them needs another team's permission.
 */
class FulfilmentControlViewTest extends TestCase
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

    public function test_free_stock_nobody_has_held_puts_the_ball_in_the_stores_court(): void
    {
        $this->seedStock('500');
        $line = $this->line('200');

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')
            ->assertOk()
            ->assertJsonPath('data.0.line_id', $line->id)
            ->assertJsonPath('data.0.customer.name', 'Aqua Traders')
            ->assertJsonPath('data.0.item.sku', 'BTL-500')
            ->assertJsonPath('data.0.ordered', '200.0000')
            ->assertJsonPath('data.0.available_stock', '500.0000')
            ->assertJsonPath('data.0.held', '0.0000')
            ->assertJsonPath('data.0.shortfall', '200.0000')
            ->assertJsonPath('data.0.dispatch_ready', '0.0000')
            ->assertJsonPath('data.0.blocker.code', 'store_has_not_held_stock')
            ->assertJsonPath('data.0.blocker.team', 'Store');
    }

    public function test_no_stock_and_no_request_still_names_the_store_as_the_next_actor(): void
    {
        $line = $this->line('200');

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')
            ->assertOk()
            ->assertJsonPath('data.0.available_stock', '0.0000')
            ->assertJsonPath('data.0.blocker.code', 'short_and_not_requested')
            ->assertJsonPath('data.0.blocker.team', 'Store');
    }

    public function test_a_queued_shortfall_moves_the_ball_to_production(): void
    {
        $line = $this->line('200');
        app(ProductionRequestService::class)->createFromShortfall($line, '200', null);

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')
            ->assertOk()
            ->assertJsonPath('data.0.production.requested', '200.0000')
            ->assertJsonPath('data.0.production.status', 'queued')
            ->assertJsonPath('data.0.blocker.code', 'queued_for_production')
            ->assertJsonPath('data.0.blocker.team', 'Production');
    }

    /**
     * THE FALSE GREEN, and the column the owner called misleading.
     *
     * Everything the line owes is HELD — and none of it may go, because Quality
     * has not signed it off (DEC-20260831-003). `stock_held` says what the store
     * set aside; `dispatch_ready` stays 0. Those are two different facts and a
     * single "dispatch ready" figure conflated them into permission to ship.
     */
    public function test_a_fully_held_line_shows_stock_held_but_nothing_dispatchable_until_quality_signs(): void
    {
        $this->seedStock('500');
        $line = $this->line('200');
        app(StockReservationService::class)->reserve($line, '200', null);

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')
            ->assertOk()
            ->assertJsonPath('data.0.held', '200.0000')
            ->assertJsonPath('data.0.shortfall', '0.0000')
            ->assertJsonPath('data.0.stock_held', '200.0000')
            ->assertJsonPath('data.0.dispatch_ready', '0.0000')
            ->assertJsonPath('data.0.quality.state', 'pending')
            ->assertJsonPath('data.0.blocker.code', 'awaiting_quality_approval')
            ->assertJsonPath('data.0.blocker.team', 'Quality');
    }

    /** Once Quality signs, the held stock becomes genuinely dispatchable and the ball moves to Sales. */
    public function test_quality_approval_turns_held_stock_into_dispatchable_stock(): void
    {
        $this->seedStock('500');
        $line = $this->line('200');
        app(StockReservationService::class)->reserve($line, '200', null);
        app(DispatchQualityApprovalService::class)->approve($line, 'Checked the lot', null);

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')
            ->assertOk()
            ->assertJsonPath('data.0.stock_held', '200.0000')
            ->assertJsonPath('data.0.dispatch_ready', '200.0000')
            ->assertJsonPath('data.0.quality.state', 'approved')
            ->assertJsonPath('data.0.quality.approved_quantity', '200.0000')
            ->assertJsonPath('data.0.blocker.code', 'ready_to_dispatch')
            ->assertJsonPath('data.0.blocker.team', 'Sales');
    }

    /** The gate the owner removed must be gone from the wire, not shown as unknown. */
    public function test_the_row_carries_no_customer_approval_key_at_all(): void
    {
        $this->seedStock('500');
        $this->line('200');

        $this->actingWith(['sales.view']);

        $row = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey(
            'customer_approval',
            $row,
            'there is no customer-approval step — a gate nobody performs must not appear on a screen at all',
        );
    }

    /**
     * THE HONESTY RULE, pinned as a contract: what this build does not record
     * is reported in WORDS with a reason, and is never coerced to a number a
     * reader would mistake for a fact.
     */
    public function test_what_is_not_recorded_says_so_in_words_and_is_never_a_zero(): void
    {
        $this->seedStock('500');
        $this->line('200');

        $this->actingWith(['sales.view']);

        $row = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');

        foreach ([
            'store.rejected',
            'production.planned',
            'production.completed',
        ] as $path) {
            [$group, $key] = explode('.', $path);
            $this->assertSame(
                FulfilmentControlService::NOT_RECORDED,
                $row[$group][$key],
                "{$path} has no source in this build and must say so, not read as a figure",
            );
        }

        $this->assertNotEmpty($row['store']['rejected_detail']);
        $this->assertNotEmpty($row['production']['planned_detail']);
        $this->assertNotEmpty($row['production']['completed_detail']);
        // Quality is NOT in that list any more: it is recorded now, and reads
        // approved/pending rather than confessing it has no source.
        $this->assertContains($row['quality']['state'], ['approved', 'pending']);
    }

    /** The ageing signal owner point 3 asks for: how long the store has sat on a decision. */
    public function test_a_hold_carries_how_long_it_has_been_waiting(): void
    {
        $this->seedStock('500');
        $line = $this->line('200');
        $hold = app(StockReservationService::class)->reserve($line, '50', null);
        $hold->forceFill(['created_at' => now()->subDays(6)])->save();

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')
            ->assertOk()
            ->assertJsonPath('data.0.store.approved', '50.0000')
            ->assertJsonPath('data.0.store.waiting_days', 6);
    }

    /** Over-promised stock is the row that needs a human, so it sorts to the top. */
    public function test_an_over_promised_product_sorts_first(): void
    {
        $this->seedStock('100');
        $quiet = $this->line('10');
        $contested = $this->line('100');
        app(StockReservationService::class)->reserve($contested, '100', null);
        // A second order now holds against a product with nothing free left.
        $this->seedStock('50');
        $other = $this->line('50');
        app(StockReservationService::class)->reserve($other, '50', null);
        app(StockMovementService::class)->recordIssue(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: '120',
            reference: 'shrinkage',
        );

        $this->actingWith(['sales.view']);

        $first = $this->getJson('/api/v1/sales/fulfilment-control')->assertOk()->json('data.0');

        $this->assertSame('over_reserved', $first['blocker']['code']);
        $this->assertNotSame($quiet->id, $first['line_id'], 'the quiet line must not outrank a contested one');
    }

    /**
     * THE WALL, in the direction that matters: this is the one screen all four
     * teams share, so each reads it with its OWN permission and nothing more.
     */
    public function test_every_team_reads_the_same_row_with_its_own_permission(): void
    {
        $this->seedStock('500');
        $this->line('200');

        foreach (['sales.view', 'inventory.view', 'production.view', 'quality.view'] as $permission) {
            $this->actingWith([$permission]);

            $this->getJson('/api/v1/sales/fulfilment-control')
                ->assertOk()
                ->assertJsonPath('data.0.blocker.team', 'Store');
        }
    }

    public function test_a_login_holding_none_of_the_four_is_refused(): void
    {
        $this->seedStock('500');
        $this->line('200');

        $this->actingWith(['hrms.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')->assertForbidden();
    }

    /** Only LIVE orders: a draft has promised nothing and a cancelled one asks for nothing. */
    public function test_draft_and_cancelled_orders_are_not_in_the_view(): void
    {
        $this->seedStock('500');
        $this->line('200', SalesOrderStatus::Draft);
        $this->line('200', SalesOrderStatus::Cancelled);

        $this->actingWith(['sales.view']);

        $this->getJson('/api/v1/sales/fulfilment-control')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ---- fixtures ---------------------------------------------------------

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

    private function line(string $quantity, SalesOrderStatus $status = SalesOrderStatus::Confirmed): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => $status,
            'order_date' => '2026-08-20',
            'expected_date' => '2026-09-05',
        ]);

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
