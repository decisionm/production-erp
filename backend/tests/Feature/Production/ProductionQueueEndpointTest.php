<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
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
 * GET /production/queue — the floor's worklist with the demand behind it
 * and the date in front of it, in one read.
 *
 * ProductionQueueService computes nothing of its own (see its docblock) —
 * these tests pin the read's OWN concerns, distinct from what
 * ProductionRequestEndpointsTest and FulfilmentPlanningServiceTest already
 * pin for the services it composes:
 *
 *   the OR-gate reaches this NEW route too — it is registered inside the
 *   same `module:production,inventory` group as /production/requests, not
 *   a route somebody could forget to gate;
 *   the FIELD-LEVEL gate on the joined figures: each block is readable
 *   here by exactly the desk that can already read it elsewhere, and is
 *   ABSENT — not null — for anybody else (see below);
 *   the read is a READ — nothing it touches changes shape or count across
 *   repeat calls, whatever module a login uses to reach it;
 *   it is unpaginated like its sibling, not silently truncated to a
 *   default page size;
 *   an empty factory (no requests at all) answers with an empty list, not
 *   an error;
 *   FC-06 holds here exactly as it holds on every other floor/store screen.
 *
 * WHY THE GATE IS TESTED FROM BOTH SIDES. Opening the queue is OR-gated so
 * both desks can read the shared document; that gate does not decide who
 * may read the OTHER modules' figures this row joins on. The planning block
 * is `/inventory/fulfilment/planning`'s, gated `module:inventory`; the
 * order's expected date is Sales' and reachable from no other desk today.
 * These tests pin that a login sees here only what it could already read
 * elsewhere — so a widening of that rule has to be a deliberate edit with
 * the owner's answer behind it, not a quiet consequence of a refactor.
 */
class ProductionQueueEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bottle = Item::create([
            'sku' => 'BTL-500',
            'name' => '500ml PET Bottle',
            'uom' => 'Nos',
            'standard_cycle_time' => '10.00',
            'standard_cavities' => 4,
        ]);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);

        Shift::create(['name' => 'Shift A', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Shift B', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Shift C', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_active' => true]);
    }

    // ---- empty state --------------------------------------------------------

    /** A factory with nothing queued answers with an empty list, not an error. */
    public function test_an_empty_factory_reads_as_no_rows(): void
    {
        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/queue')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'basis' => [
                    'shifts_per_day' => 3,
                    'parallel_lines' => 1,
                    'shift_hours' => '8.0000',
                    'timezone' => 'Asia/Kolkata',
                    'source' => 'active_shifts',
                ],
            ]);
    }

    // ---- the join itself -----------------------------------------------------

    /**
     * THE STORE'S READ: the worklist, the demand behind it and the date in
     * front of it, in one row. Every key here is one this login already
     * reads on `/inventory/fulfilment/queue` and `/fulfilment/planning`.
     *
     * The request half is named exactly as `/production/requests` names it —
     * `id`, not a second spelling of the same column — because one class
     * (ProductionRequestResource) owns those keys on both routes.
     */
    public function test_the_row_carries_the_demand_and_the_date_together(): void
    {
        $request = $this->request($this->bottle, '20000', '20000');

        $this->actingWith(['production.view', 'inventory.view']);

        $this->getJson('/api/v1/production/queue')
            ->assertOk()
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.request_number', $request->documentNumber())
            ->assertJsonPath('data.0.sales_order_line_id', $request->sales_order_line_id)
            ->assertJsonPath('data.0.item.sku', 'BTL-500')
            ->assertJsonPath('data.0.quantity', '20000.0000')
            ->assertJsonPath('data.0.ordered', '20000.0000')
            ->assertJsonPath('data.0.delivered', '0.0000')
            ->assertJsonPath('data.0.sales_order.customer_name', 'Aqua Traders')
            ->assertJsonPath('data.0.planning.free', '0.0000')
            ->assertJsonPath('data.0.planning.cannot_estimate', false)
            ->assertJsonPath('data.0.planning.shifts_needed', 2)
            ->assertJsonPath('data.0.can.start', true);
    }

    /** cannot_estimate is propagated, never hidden behind a blank cell. */
    public function test_an_unestimable_product_reads_its_own_refusal(): void
    {
        $mystery = Item::create(['sku' => 'NEW-JAR', 'name' => 'New Jar', 'uom' => 'Nos']);
        $this->request($mystery, '5000', '5000');

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/production/queue')
            ->assertOk()
            ->assertJsonPath('data.0.planning.cannot_estimate', true)
            ->assertJsonPath('data.0.planning.reason', 'no_production_standard')
            ->assertJsonPath('data.0.planning.estimated_ready_date', null);
    }

    // ---- the field-level gate -------------------------------------------------

    /**
     * THE FLOOR'S OWN LOGIN sees the worklist and NOT the other desks'
     * figures: no planning block (that is `/inventory/fulfilment/planning`,
     * module:inventory), no line quantities, no expected date.
     *
     * ABSENT, NOT NULL, and that is the whole point. `cannot_estimate` is a
     * real state — the factory genuinely cannot date this row — so a nulled
     * planning block would be indistinguishable from that refusal and the
     * screen would print "cannot estimate" at somebody who is merely not
     * allowed to see the answer.
     */
    public function test_a_floor_only_login_reads_the_worklist_without_the_other_desks_figures(): void
    {
        $this->request($this->bottle, '20000', '20000');

        $this->actingWith(['production.view']);

        $row = $this->getJson('/api/v1/production/queue')->assertOk()->json('data.0');

        // What the floor is owed, and who asked for it — unchanged.
        $this->assertSame('20000.0000', $row['quantity']);
        $this->assertSame('Aqua Traders', $row['sales_order']['customer_name']);

        foreach (['planning', 'ordered', 'delivered'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "{$key} is another desk's figure and must be absent, not null");
        }
        $this->assertArrayNotHasKey('expected_date', $row['sales_order']);
    }

    /**
     * THE STORE'S LOGIN reads the line figures — it already does, on its own
     * fulfilment queue (FulfilmentQueueRowResource) — but NOT the order's
     * expected date, which is reachable today from the Sales desk alone.
     */
    public function test_the_store_reads_the_line_figures_but_not_the_orders_expected_date(): void
    {
        $this->request($this->bottle, '20000', '20000', '2026-09-15');

        $this->actingWith(['inventory.view']);

        $row = $this->getJson('/api/v1/production/queue')->assertOk()->json('data.0');

        $this->assertSame('20000.0000', $row['ordered']);
        $this->assertArrayHasKey('planning', $row);
        $this->assertArrayNotHasKey('expected_date', $row['sales_order']);
    }

    /** The SALES desk's figure, read by the desk that owns it. */
    public function test_a_sales_login_reads_the_orders_expected_date(): void
    {
        $this->request($this->bottle, '20000', '20000', '2026-09-15');

        // sales.view alone cannot OPEN this queue (the OR gate is
        // production|inventory) — it is the second permission that reveals
        // the date, never the one that gets you through the door.
        $this->actingWith(['production.view', 'sales.view']);

        $row = $this->getJson('/api/v1/production/queue')->assertOk()->json('data.0');

        $this->assertSame('2026-09-15', $row['sales_order']['expected_date']);
        $this->assertSame('20000.0000', $row['ordered']);
        // Still not the store's planning block: sales.view is not inventory.
        $this->assertArrayNotHasKey('planning', $row);
    }

    /** A finished (cancelled) request carries no planning row and leaves the queue. */
    public function test_a_cancelled_request_leaves_the_queue(): void
    {
        $request = $this->request($this->bottle, '20000', '20000');
        app(ProductionRequestService::class)->cancel($request, 'mold is down');

        $this->actingWith(['production.view']);

        $this->getJson('/api/v1/production/queue')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- unpaginated, like its sibling ---------------------------------------

    public function test_every_open_request_is_returned_in_one_read(): void
    {
        for ($i = 0; $i < 17; $i++) {
            $this->request($this->bottle, '100', '100');
        }

        $this->actingWith(['production.view']);

        $response = $this->getJson('/api/v1/production/queue')->assertOk();

        $response->assertJsonCount(17, 'data');
        // A bare object with `data`/`basis`, never a paginator's `meta`/`links`
        // — nothing here is silently truncated to a default page size.
        $this->assertSame(['data', 'basis'], array_keys($response->json()));
    }

    // ---- read-only, end to end ------------------------------------------------

    /**
     * READ-ONLY (invariant 1 and 2, said again for THIS route): reading the
     * queue — once, or repeatedly, from either side of the OR-gate — creates
     * no batch, writes no shift entry, and leaves every request's own status
     * and priority exactly where it found them.
     */
    public function test_reading_the_queue_writes_nothing(): void
    {
        $request = $this->request($this->bottle, '20000', '20000');

        $this->actingWith(['production.view']);
        $this->getJson('/api/v1/production/queue')->assertOk();

        $this->actingWith(['inventory.view']);
        $this->getJson('/api/v1/production/queue')->assertOk();
        $this->getJson('/api/v1/production/queue')->assertOk();

        $fresh = $request->fresh();
        $this->assertSame('queued', $fresh->status->value);
        $this->assertSame(1, $fresh->priority);
        $this->assertSame(0, ShiftProductionEntry::query()->count());
        $this->assertSame(1, ProductionRequest::query()->count());
    }

    // ---- the OR-gate, both ways ------------------------------------------------

    public function test_both_desks_read_the_queue(): void
    {
        $this->request($this->bottle, '20000', '20000');

        $this->actingWith(['production.view']);
        $this->getJson('/api/v1/production/queue')->assertOk();

        $this->actingWith(['inventory.view']);
        $this->getJson('/api/v1/production/queue')->assertOk();

        // manage on either side reads too — Manage subsumes View
        // (EnsureModulePermission).
        $this->actingWith(['production.manage']);
        $this->getJson('/api/v1/production/queue')->assertOk();

        $this->actingWith(['inventory.manage']);
        $this->getJson('/api/v1/production/queue')->assertOk();
    }

    public function test_a_login_with_neither_module_is_refused(): void
    {
        $this->request($this->bottle, '20000', '20000');

        $this->actingWith(['sales.manage']);

        $this->getJson('/api/v1/production/queue')->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/production/queue')->assertStatus(401);
    }

    // ---- FC-06 ------------------------------------------------------------

    /** FC-06: the floor's screen is about pieces and priority, never money. */
    public function test_the_queue_carries_no_rate_no_cost_and_no_vendor(): void
    {
        $this->request($this->bottle, '20000', '20000', '2026-09-15');

        // EVERY desk that can reach this queue, not merely the narrowest one.
        // The field-level gate above opens keys for the store and the sales
        // desk, and FC-06 is not a thing the widest payload gets to relax:
        // the line carries a unit_price and NO caller of this route reads it.
        foreach ([['production.view'], ['inventory.view'], ['production.view', 'sales.view']] as $permissions) {
            $this->actingWith($permissions);

            $body = json_encode($this->getJson('/api/v1/production/queue')->assertOk()->json());
            $who = implode('+', $permissions);

            foreach (['unit_price', 'rate', 'cost', 'amount', 'vendor', 'supplier'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "FC-06: the queue must not print {$forbidden} to {$who}");
            }
        }
    }

    // ---- fixtures ----------------------------------------------------------

    private function request(Item $item, string $shortfall, string $ordered, ?string $expected = null): ProductionRequest
    {
        return app(ProductionRequestService::class)->createFromShortfall(
            $this->line($item, $ordered, $expected),
            $shortfall,
            null,
        );
    }

    private function line(Item $item, string $quantity, ?string $expected = null): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
            'expected_date' => $expected,
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
