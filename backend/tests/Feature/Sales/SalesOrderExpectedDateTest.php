<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE EXPECTED DATE ON A SALES ORDER (PUT /sales/sales-orders/{id}).
 *
 * `expected_date` is the promise the order carries — typed by hand by the
 * sales desk, owned by nobody else. It is NOT a production ETA and nothing
 * derives it; whether production can meet it is a separate, unanswered
 * question. This endpoint therefore does one thing: change that date and
 * the desk's notes, and only while the order is still the desk's own
 * (draft or confirmed). From the first dispatch onwards the promise is
 * history and is refused with a 422 carrying a stable `not_editable` code.
 *
 * The write touches nothing else: no stock moves, no delivery or invoice is
 * created or changed, the status does not shift, and nothing is queued for
 * Tally — real sales are invoiced there (DEC-20260809-003) and this endpoint
 * has no business in that book.
 *
 * OVERDUE is derived on every read, never stored: expected_date before the
 * factory's own today (IST) while the order is still open. The app clock is
 * UTC, so the half-hour after 18:30 UTC is the discriminating case — the
 * factory is already on the next morning and a UTC-computed answer would be
 * a day behind.
 *
 * Writes need sales.manage; sales.view alone reads the order but cannot
 * change it (403), exactly like every other Sales write.
 */
class SalesOrderExpectedDateTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    private User $salesDesk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesDesk = $this->userWith('Sales Desk', ['sales.view', 'sales.manage']);
        Sanctum::actingAs($this->salesDesk);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
    }

    // ---- fixtures ---------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function userWith(string $name, array $permissions): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** A draft order for 2000 bottles, created the way the SPA creates one. */
    private function draftOrder(?string $expectedDate = '2026-08-20'): SalesOrder
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-08-10',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '2000', 'unit_price' => '4.50']],
        ];
        if ($expectedDate !== null) {
            $payload['expected_date'] = $expectedDate;
        }

        $id = $this->postJson('/api/v1/sales/sales-orders', $payload)
            ->assertSuccessful()
            ->json('data.id');

        return SalesOrder::query()->with('lines')->findOrFail($id);
    }

    private function confirmedOrder(?string $expectedDate = '2026-08-20'): SalesOrder
    {
        $order = $this->draftOrder($expectedDate);
        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/confirm")->assertSuccessful();

        return $order->fresh('lines');
    }

    /** FG stock, then a part dispatch — the one Sales action that moves the order out of the desk's hands. */
    private function partiallyDeliveredOrder(?string $expectedDate = '2026-08-20'): SalesOrder
    {
        $order = $this->confirmedOrder($expectedDate);

        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id, warehouseId: $this->fg->id,
            quantity: '5000', unitCost: '2.50', reference: 'seed',
        );

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-11',
            'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '500']],
        ])->assertSuccessful();

        $fresh = $order->fresh('lines');
        $this->assertSame(SalesOrderStatus::PartiallyDelivered, $fresh->status);

        return $fresh;
    }

    private function editOrder(SalesOrder $order, array $body)
    {
        return $this->putJson("/api/v1/sales/sales-orders/{$order->id}", $body);
    }

    // ---- who may write ----------------------------------------------------

    public function test_sales_view_alone_cannot_change_the_expected_date(): void
    {
        $order = $this->draftOrder();

        Sanctum::actingAs($this->userWith('Reader', ['sales.view']));

        $this->editOrder($order, ['expected_date' => '2026-08-25'])->assertForbidden();

        $this->assertSame('2026-08-20', $order->fresh()->expected_date?->toDateString());
    }

    public function test_a_login_without_sales_at_all_cannot_change_the_expected_date(): void
    {
        $order = $this->draftOrder();

        Sanctum::actingAs($this->userWith('Storekeeper', ['inventory.manage']));

        $this->editOrder($order, ['expected_date' => '2026-08-25'])->assertForbidden();
    }

    // ---- the allowed statuses ---------------------------------------------

    public function test_a_draft_order_takes_a_new_expected_date(): void
    {
        $order = $this->draftOrder();

        $this->editOrder($order, ['expected_date' => '2026-08-25'])
            ->assertSuccessful()
            ->assertJsonPath('data.expected_date', '2026-08-25')
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame('2026-08-25', $order->fresh()->expected_date?->toDateString());
    }

    public function test_a_confirmed_order_takes_a_new_expected_date(): void
    {
        $order = $this->confirmedOrder();

        $this->editOrder($order, ['expected_date' => '2026-09-01', 'notes' => 'Customer moved the slot.'])
            ->assertSuccessful()
            ->assertJsonPath('data.expected_date', '2026-09-01')
            ->assertJsonPath('data.notes', 'Customer moved the slot.')
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_a_partially_delivered_order_refuses_the_change_with_a_stable_code(): void
    {
        $order = $this->partiallyDeliveredOrder();

        $this->editOrder($order, ['expected_date' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_editable');

        $this->assertSame('2026-08-20', $order->fresh()->expected_date?->toDateString());
    }

    public function test_a_completed_order_refuses_the_change(): void
    {
        $order = $this->draftOrder();
        $order->forceFill(['status' => SalesOrderStatus::Completed])->save();

        $this->editOrder($order, ['expected_date' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_editable');
    }

    public function test_a_cancelled_order_refuses_the_change(): void
    {
        $order = $this->draftOrder();
        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/cancel")->assertSuccessful();

        $this->editOrder($order, ['expected_date' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_editable');

        $this->assertSame('2026-08-20', $order->fresh()->expected_date?->toDateString());
    }

    // ---- setting, clearing, leaving alone ---------------------------------

    public function test_an_explicit_null_clears_the_expected_date(): void
    {
        $order = $this->confirmedOrder();

        $this->editOrder($order, ['expected_date' => null])
            ->assertSuccessful()
            ->assertJsonPath('data.expected_date', null)
            ->assertJsonPath('data.is_overdue', false);

        $this->assertNull($order->fresh()->expected_date);
    }

    public function test_an_undated_order_can_be_given_a_date(): void
    {
        $order = $this->confirmedOrder(expectedDate: null);
        $this->assertNull($order->expected_date);

        $this->editOrder($order, ['expected_date' => '2026-08-30'])
            ->assertSuccessful()
            ->assertJsonPath('data.expected_date', '2026-08-30');
    }

    public function test_an_absent_key_leaves_the_stored_date_alone(): void
    {
        $order = $this->confirmedOrder();

        // Notes only: the date is not named, so it is not touched. This is
        // the case `isset()` would have got wrong — it reads an absent key
        // and an explicit null as the same request.
        $this->editOrder($order, ['notes' => 'Called the customer.'])
            ->assertSuccessful()
            ->assertJsonPath('data.expected_date', '2026-08-20')
            ->assertJsonPath('data.notes', 'Called the customer.');

        $this->assertSame('2026-08-20', $order->fresh()->expected_date?->toDateString());
    }

    public function test_an_empty_body_changes_nothing_and_still_returns_the_order(): void
    {
        $order = $this->confirmedOrder();

        $this->editOrder($order, [])
            ->assertSuccessful()
            ->assertJsonPath('data.expected_date', '2026-08-20');
    }

    // ---- the date rule ----------------------------------------------------

    public function test_a_date_before_the_order_date_is_refused(): void
    {
        $order = $this->confirmedOrder();

        $this->editOrder($order, ['expected_date' => '2026-08-09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_date');

        $this->assertSame('2026-08-20', $order->fresh()->expected_date?->toDateString());
    }

    public function test_the_order_date_itself_is_allowed(): void
    {
        $order = $this->confirmedOrder();

        $this->editOrder($order, ['expected_date' => '2026-08-10'])
            ->assertSuccessful()
            ->assertJsonPath('data.expected_date', '2026-08-10');
    }

    public function test_a_value_that_is_not_a_date_is_refused(): void
    {
        $order = $this->confirmedOrder();

        $this->editOrder($order, ['expected_date' => 'next tuesday-ish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_date');
    }

    // ---- nothing else moves ------------------------------------------------

    public function test_the_endpoint_cannot_mass_assign_any_other_field(): void
    {
        $order = $this->confirmedOrder();
        $otherCustomer = Customer::create(['code' => 'CUST-2', 'name' => 'Blue Waters']);
        $intruder = $this->userWith('Intruder', ['sales.manage']);

        $this->editOrder($order, [
            'expected_date' => '2026-08-28',
            'status' => 'completed',
            'order_date' => '2020-01-01',
            'customer_id' => $otherCustomer->id,
            'created_by' => $intruder->id,
            'notes' => 'Moved a week out.',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => '9999', 'unit_price' => '0.01']],
        ])->assertSuccessful();

        $fresh = $order->fresh('lines');
        $this->assertSame(SalesOrderStatus::Confirmed, $fresh->status);
        $this->assertSame('2026-08-10', $fresh->order_date?->toDateString());
        $this->assertSame($this->customer->id, $fresh->customer_id);
        $this->assertSame($this->salesDesk->id, $fresh->created_by);
        $this->assertCount(1, $fresh->lines);
        $this->assertSame('2000.0000', $fresh->lines->first()->quantity);
        // The two fields that ARE this endpoint's did change.
        $this->assertSame('2026-08-28', $fresh->expected_date?->toDateString());
        $this->assertSame('Moved a week out.', $fresh->notes);
    }

    public function test_changing_the_date_moves_no_stock_and_queues_nothing_for_tally(): void
    {
        $order = $this->confirmedOrder();

        $movements = StockMovement::query()->count();
        $entries = TallySyncEntry::query()->count();
        $events = TallySyncEvent::query()->count();

        $this->editOrder($order, ['expected_date' => '2026-09-05'])->assertSuccessful();

        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame($entries, TallySyncEntry::query()->count());
        $this->assertSame($events, TallySyncEvent::query()->count());
        $this->assertSame(0, $order->deliveries()->count());
        $this->assertSame(0, $order->invoices()->count());
    }

    public function test_the_response_is_the_normal_order_resource_without_a_trace(): void
    {
        $order = $this->confirmedOrder();

        $response = $this->editOrder($order, ['expected_date' => '2026-08-26'])->assertSuccessful();

        $response->assertJsonStructure([
            'data' => [
                'id', 'document_number', 'status', 'customer', 'order_date', 'expected_date',
                'is_overdue', 'notes', 'lines', 'totals', 'deliveries_count', 'invoices_count',
                'can_cancel', 'can_edit', 'created_at',
            ],
        ]);
        $this->assertArrayNotHasKey('trace', $response->json('data'));
        $this->assertTrue($response->json('data.can_edit'));
    }

    public function test_can_edit_is_false_once_the_order_has_shipped(): void
    {
        $order = $this->partiallyDeliveredOrder();

        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.can_edit', false);
    }

    // ---- overdue, on the factory's calendar --------------------------------

    public function test_an_open_order_past_its_date_reads_overdue_and_one_still_ahead_does_not(): void
    {
        $this->travelTo('2026-08-25 06:00:00');

        $late = $this->confirmedOrder('2026-08-20');
        $ahead = $this->confirmedOrder('2026-08-30');

        $this->assertTrue($this->showFlag($late));
        $this->assertFalse($this->showFlag($ahead));
    }

    public function test_an_order_due_today_is_not_yet_overdue(): void
    {
        $this->travelTo('2026-08-25 06:00:00');

        $today = $this->confirmedOrder('2026-08-25');

        $this->assertFalse($this->showFlag($today));
    }

    public function test_a_draft_can_be_overdue_and_a_completed_or_cancelled_order_never_is(): void
    {
        $this->travelTo('2026-08-25 06:00:00');

        $this->assertTrue($this->showFlag($this->draftOrder('2026-08-20')));

        $completed = $this->draftOrder('2026-08-20');
        $completed->forceFill(['status' => SalesOrderStatus::Completed])->save();
        $this->assertFalse($this->showFlag($completed));

        $cancelled = $this->draftOrder('2026-08-20');
        $this->postJson("/api/v1/sales/sales-orders/{$cancelled->id}/cancel")->assertSuccessful();
        $this->assertFalse($this->showFlag($cancelled));
    }

    public function test_an_undated_order_is_never_overdue(): void
    {
        $this->travelTo('2026-08-25 06:00:00');

        $this->assertFalse($this->showFlag($this->confirmedOrder(expectedDate: null)));
    }

    /**
     * THE IST BOUNDARY. The app clock is UTC; the factory's day is IST
     * (+05:30). At 18:00 UTC the factory is still on the 25th, so a promise
     * for the 25th is not yet late. One hour later it is 00:30 on the 26th
     * on the factory floor and the same promise IS late — while a
     * UTC-computed answer would still say the 25th and read "on time" for
     * another five and a half hours.
     */
    public function test_the_day_turns_on_the_factory_clock_not_the_app_clock(): void
    {
        $this->travelTo('2026-08-25 06:00:00');
        $order = $this->confirmedOrder('2026-08-25');

        $this->travelTo('2026-08-25 18:00:00');   // 23:30 IST on the 25th
        $this->assertFalse($this->showFlag($order));
        $this->assertSame(0, $this->dashboardOverdue());

        $this->travelTo('2026-08-25 19:00:00');   // 00:30 IST on the 26th
        $this->assertTrue($this->showFlag($order));
        $this->assertSame(1, $this->dashboardOverdue());
    }

    public function test_the_list_carries_the_same_flag_as_the_show_endpoint(): void
    {
        $this->travelTo('2026-08-25 19:00:00');

        $order = $this->confirmedOrder('2026-08-25');

        $row = collect($this->getJson('/api/v1/sales/sales-orders')->assertSuccessful()->json('data'))
            ->firstWhere('id', $order->id);

        $this->assertTrue($row['is_overdue']);
        $this->assertTrue($this->showFlag($order));
    }

    public function test_the_dashboard_counts_the_same_orders_the_flag_marks(): void
    {
        $this->travelTo('2026-08-25 06:00:00');

        $this->draftOrder('2026-08-20');            // late, draft — counted
        $this->confirmedOrder('2026-08-20');        // late, confirmed — counted
        $this->confirmedOrder('2026-08-30');        // ahead — not counted
        $this->confirmedOrder(expectedDate: null);  // undated — not counted

        $cancelled = $this->draftOrder('2026-08-20');
        $this->postJson("/api/v1/sales/sales-orders/{$cancelled->id}/cancel")->assertSuccessful();

        $this->assertSame(2, $this->dashboardOverdue());
    }

    /** The `is_overdue` the show endpoint reports for one order. */
    private function showFlag(SalesOrder $order): bool
    {
        return $this->getJson("/api/v1/sales/sales-orders/{$order->id}")
            ->assertSuccessful()
            ->json('data.is_overdue');
    }

    private function dashboardOverdue(): int
    {
        return $this->getJson('/api/v1/dashboard/summary')
            ->assertSuccessful()
            ->json('data.sales.overdue_sales_orders');
    }
}
