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
 * THE INTERNAL QUALITY GATE — DEC-20260831-006.
 *
 * The owner's sequence: stock fully held → QUALITY APPROVES → Sales dispatches
 * → Sales issues the invoice. Before this, dispatch consulted no quality state
 * at all beyond refusing an already-REJECTED carton (DEC-20260807-013), so a
 * batch merely not yet through QC went out freely.
 *
 * What this file pins:
 *   the precondition  Quality approves only a line whose stock is FULLY HELD;
 *   the record        who, when and FOR WHAT QUANTITY — an approval is of a
 *                     quantity, not of a row, so a hold released and re-taken
 *                     afterwards cannot inherit a sign-off nobody gave;
 *   the gate          dispatch refuses without an approval, and refuses BEYOND
 *                     the approved quantity;
 *   the wall          Quality approves, Sales does not — a desk must not be
 *                     able to sign off its own dispatch;
 *   not a one-way door  an approval may be withdrawn until goods have gone,
 *                     and is refused afterwards rather than rewritten.
 */
class DispatchQualityGateTest extends TestCase
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

    // ---- the approval act --------------------------------------------------

    public function test_quality_approves_a_fully_held_line_and_the_record_says_who_when_and_how_much(): void
    {
        $line = $this->heldLine('200', '200');

        $this->actingWith(['quality.view', 'quality.manage'], 'Quality Desk');

        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/approve", ['note' => 'Checked the lot'])
            ->assertSuccessful()
            ->assertJsonPath('data.quality_approved', true)
            ->assertJsonPath('data.quality_approved_quantity', '200.0000')
            ->assertJsonPath('data.quality_approved_by', 'Quality Desk')
            ->assertJsonPath('data.quality_approval_note', 'Checked the lot')
            ->assertJsonPath('data.dispatchable', '200.0000');

        $this->assertNotNull($line->fresh()->quality_approved_at);
    }

    /** The owner's precondition, in the owner's own order: fully held FIRST. */
    public function test_a_partly_held_line_is_not_yet_qualitys_to_approve(): void
    {
        $line = $this->heldLine('200', '80');

        $this->actingWith(['quality.manage']);

        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/approve")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Quality approves a line only once the stock is fully held: 80.0000 is held against 200.0000 still owed. Hold the remainder on Store Fulfilment first, or ask the floor for it.']);
    }

    /** Re-approving in place would silently raise the dispatch cap. */
    public function test_an_approved_line_refuses_a_second_approval_rather_than_raising_the_cap(): void
    {
        $line = $this->heldLine('200', '200');
        $this->actingWith(['quality.manage']);
        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/approve")->assertSuccessful();

        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/approve")->assertStatus(422);
    }

    // ---- the wall ----------------------------------------------------------

    public function test_sales_cannot_sign_off_its_own_dispatch(): void
    {
        $line = $this->heldLine('200', '200');

        $this->actingWith(['sales.view', 'sales.manage']);

        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/approve")->assertForbidden();
    }

    public function test_a_read_only_quality_login_cannot_approve(): void
    {
        $line = $this->heldLine('200', '200');

        $this->actingWith(['quality.view']);

        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/approve")->assertForbidden();
    }

    // ---- the gate ----------------------------------------------------------

    public function test_dispatch_is_refused_while_quality_has_not_signed(): void
    {
        $line = $this->heldLine('200', '200');

        $this->actingWith(['inventory.manage']); // the STORE dispatches (DEC-20260901-005)

        $this->dispatch($line, '200')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Line #{$line->id} has no internal quality approval, so it cannot be dispatched. Quality signs a line off once its stock is fully held; until then the goods stay in the store."]);

        $this->assertSame('0.0000', (string) $line->fresh()->quantity_delivered, 'nothing moved');
    }

    public function test_dispatch_succeeds_once_quality_has_signed(): void
    {
        $line = $this->heldLine('200', '200');
        $this->approveAsQuality($line);

        $this->actingWith(['inventory.manage']); // the STORE dispatches (DEC-20260901-005)

        $this->dispatch($line, '200')->assertSuccessful();

        $this->assertSame('200.0000', (string) $line->fresh()->quantity_delivered);
    }

    /** An approval is OF A QUANTITY. Dispatching past it is shipping uninspected stock. */
    public function test_dispatch_is_refused_beyond_the_quantity_quality_approved(): void
    {
        $line = $this->heldLine('200', '200');
        // Quality looked at 120 of the 200.
        $this->approveAsQuality($line, '120');

        $this->actingWith(['inventory.manage']); // the STORE dispatches (DEC-20260901-005)

        $this->dispatch($line, '200')->assertStatus(422);

        $this->dispatch($line, '120')->assertSuccessful();
        $this->assertSame('120.0000', (string) $line->fresh()->quantity_delivered);
    }

    // ---- who may dispatch --------------------------------------------------

    /**
     * DEC-20260901-005, resolving Q78: the STORE performs the final dispatch
     * action and Sales does not.
     *
     * Two assertions, and the second is the one that makes the first mean
     * something. It is not enough that Sales is refused — if the Store were
     * refused too, dispatch would simply be broken and the first assertion
     * would still pass.
     *
     * The Store dispatches on its OWN `inventory.manage`. It is deliberately
     * NOT given sales.manage to do it: the owner's condition was that the
     * Store gets the permission needed for dispatch and no wider Sales access,
     * so being able to dispatch must unlock nothing about sales orders,
     * customers or invoices.
     */
    public function test_sales_may_no_longer_dispatch_and_the_store_may(): void
    {
        $line = $this->heldLine('200', '200');
        $this->approveAsQuality($line);

        $this->actingWith(['sales.view', 'sales.manage']);
        $this->dispatch($line, '200')->assertForbidden();
        $this->assertSame('0.0000', (string) $line->fresh()->quantity_delivered, 'a refused dispatch moves nothing');

        $this->actingWith(['inventory.manage']);
        $this->dispatch($line, '200')->assertSuccessful();
        $this->assertSame('200.0000', (string) $line->fresh()->quantity_delivered);
    }

    /** Dispatching must not become a back door into the rest of Sales. */
    public function test_the_store_dispatching_gains_no_sales_access(): void
    {
        $line = $this->heldLine('200', '200');
        $this->approveAsQuality($line);

        $this->actingWith(['inventory.manage']);
        $this->dispatch($line, '200')->assertSuccessful();

        // The same login, immediately after dispatching, is still refused the
        // Sales desk's own work.
        $this->postJson('/api/v1/sales/sales-orders', [])->assertForbidden();
        // Invoice HISTORY, not the writing of one: the ERP raises no invoice
        // any more (DEC-20260903-004), so the probe that used to POST here
        // reads the retired document instead — still Sales' to see, still
        // refused to the Store.
        $this->getJson('/api/v1/sales/invoices')->assertForbidden();
        $this->getJson('/api/v1/sales/customers')->assertForbidden();
    }

    // ---- withdrawal --------------------------------------------------------

    public function test_quality_may_withdraw_an_approval_before_anything_goes(): void
    {
        $line = $this->heldLine('200', '200');
        $this->actingWith(['quality.manage']);
        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/approve")->assertSuccessful();

        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/revoke")
            ->assertSuccessful()
            ->assertJsonPath('data.quality_approved', false)
            ->assertJsonPath('data.dispatchable', '0.0000');

        $this->assertNull($line->fresh()->quality_approved_at);
    }

    /** Once goods have gone the approval is history, and history is not rewritten. */
    public function test_an_approval_cannot_be_withdrawn_after_goods_have_gone(): void
    {
        $line = $this->heldLine('200', '200');
        $this->approveAsQuality($line);
        $this->actingWith(['inventory.manage']); // the STORE dispatches (DEC-20260901-005)
        $this->dispatch($line, '200')->assertSuccessful();

        $this->actingWith(['quality.manage']);

        $this->postJson("/api/v1/quality/dispatch-approvals/lines/{$line->id}/revoke")->assertStatus(422);
        $this->assertNotNull($line->fresh()->quality_approved_at);
    }

    // ---- helpers -----------------------------------------------------------

    private function dispatch(SalesOrderLine $line, string $quantity)
    {
        return $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $line->sales_order_id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-31',
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => $quantity]],
        ]);
    }

    /** Stamped directly: these tests exercise the GATE, not the approval screen. */
    private function approveAsQuality(SalesOrderLine $line, ?string $quantity = null): void
    {
        $line->forceFill([
            'quality_approved_at' => now(),
            'quality_approved_quantity' => $quantity ?? (string) $line->quantity,
        ])->save();
    }

    private function heldLine(string $ordered, string $held): SalesOrderLine
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: '1000',
            unitCost: '2.50',
            reference: 'seed',
        );

        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-20',
        ]);

        $line = $order->lines()->create([
            'item_id' => $this->bottle->id,
            'quantity' => $ordered,
            'unit_price' => '4.50',
            'quantity_delivered' => 0,
        ]);

        if (bccomp($held, '0', 4) === 1) {
            app(StockReservationService::class)->reserve($line, $held, null);
        }

        return $line->fresh();
    }

    /** @param  list<string>  $permissions */
    private function actingWith(array $permissions, string $name = 'Desk'): User
    {
        $this->app['auth']->forgetGuards();

        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
