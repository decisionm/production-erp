<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Services\FulfilmentPlanningService;
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
 * GET /inventory/fulfilment/planning — the ETA dashboard's whole read.
 *
 * A BARE OBJECT, not a paginated list: it is three things at once (the
 * lines, the BASIS every date was computed from, and what the floor should
 * be working on today), like /sales/tally-mirror and
 * /inventory/production-floor-stock before it.
 *
 * What the endpoint half pins, over and above the service's own file:
 *   - the three keys are on the wire, un-nested and un-paginated;
 *   - a refusal to estimate travels as a reason, never as a missing key or
 *     a plausible date (S12);
 *   - the store reads it and nobody else does;
 *   - FC-06: an ETA screen shows no money.
 */
class FulfilmentPlanningEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $fg;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders']);

        // The factory's three 8-hour shifts.
        Shift::create(['name' => 'Shift A', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Shift B', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'is_active' => true]);
        Shift::create(['name' => 'Shift C', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_active' => true]);
    }

    public function test_the_dashboard_reads_rows_a_basis_and_todays_targets(): void
    {
        // 8 hours ÷ 10s cycle = 2880 shots, × 4 cavities = 11 520 a shift.
        $bottle = $this->item('BTL-500', '10.00', 4);
        $request = $this->request($bottle, '20000');

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/planning')
            ->assertOk()
            ->assertJsonPath('data.0.item.sku', 'BTL-500')
            ->assertJsonPath('data.0.customer.name', 'Aqua Traders')
            ->assertJsonPath('data.0.needed', '20000.0000')
            ->assertJsonPath('data.0.free', '0.0000')
            ->assertJsonPath('data.0.queued_ahead', 0)
            ->assertJsonPath('data.0.capacity_per_shift', 11520)
            ->assertJsonPath('data.0.shifts_needed', 2)
            ->assertJsonPath('data.0.cannot_estimate', false)
            ->assertJsonPath('data.0.reason', null)
            ->assertJsonPath('basis.shifts_per_day', 3)
            ->assertJsonPath('basis.parallel_lines', 1)
            ->assertJsonPath('basis.shift_hours', '8.0000')
            ->assertJsonPath('basis.timezone', 'Asia/Kolkata')
            ->assertJsonPath('basis.source', 'active_shifts')
            ->assertJsonPath('today_targets.0.request_id', $request->id)
            ->assertJsonPath('today_targets.0.priority', 1);
    }

    /**
     * S12 — a product the factory cannot estimate gets NO date and says why,
     * and so does everything behind it. Never an interpolated date, never a
     * caveat-date.
     */
    public function test_a_refusal_to_estimate_travels_as_a_reason_and_no_date(): void
    {
        $mystery = $this->item('MYS-1', null, null);
        $known = $this->item('BTL-500', '10.00', 4);
        $this->request($mystery, '5000');
        $this->request($known, '5000');

        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/planning')
            ->assertOk()
            ->assertJsonPath('data.0.cannot_estimate', true)
            ->assertJsonPath('data.0.reason', FulfilmentPlanningService::REASON_NO_STANDARD)
            ->assertJsonPath('data.0.estimated_ready_date', null)
            ->assertJsonPath('data.0.shifts_needed', null)
            ->assertJsonPath('data.1.cannot_estimate', true)
            ->assertJsonPath('data.1.reason', FulfilmentPlanningService::REASON_ITEMS_AHEAD)
            ->assertJsonPath('data.1.estimated_ready_date', null);
    }

    public function test_an_empty_queue_still_publishes_the_basis(): void
    {
        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/planning')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'today_targets')
            ->assertJsonPath('basis.shifts_per_day', 3);
    }

    // ---- the permission wall, both ways ------------------------------------

    public function test_the_store_reads_the_planning_dashboard(): void
    {
        $this->actingWith(['inventory.view']);

        $this->getJson('/api/v1/inventory/fulfilment/planning')->assertOk();
    }

    public function test_a_login_without_inventory_reads_nothing(): void
    {
        $this->actingWith(['production.manage']);

        $this->getJson('/api/v1/inventory/fulfilment/planning')->assertStatus(403);
    }

    /** FC-06: a date is not a price. */
    public function test_the_dashboard_carries_no_cost_of_any_kind(): void
    {
        $this->request($this->item('BTL-500', '10.00', 4), '5000');

        $this->actingWith(['inventory.view']);

        $body = json_encode($this->getJson('/api/v1/inventory/fulfilment/planning')->assertOk()->json());

        foreach (['cost', 'rate', 'unit_price', 'amount', 'vendor', 'supplier'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "FC-06: planning must not print {$forbidden}");
        }
    }

    // ---- fixtures ----------------------------------------------------------

    private function item(string $sku, ?string $cycleTime, ?int $cavities): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $sku,
            'uom' => 'Nos',
            'standard_cycle_time' => $cycleTime,
            'standard_cavities' => $cavities,
        ]);
    }

    private function request(Item $item, string $quantity)
    {
        return app(ProductionRequestService::class)->createFromShortfall(
            $this->line($item, $quantity),
            $quantity,
            null,
        );
    }

    private function line(Item $item, string $quantity): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => '0',
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
