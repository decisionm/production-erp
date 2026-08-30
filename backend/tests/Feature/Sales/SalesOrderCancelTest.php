<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * CANCELLING A SALES ORDER (Phase 3.5, POST /sales/sales-orders/{id}/cancel).
 *
 * The rule the service enforces and the resource's `can_cancel` mirrors:
 * a sales order can be cancelled ONLY while nothing has happened against
 * it — from `draft`, or from `confirmed` with every line's
 * quantity_delivered still 0 AND no invoice (draft included) on it.
 * Everything else is refused with the existing
 * InvalidStatusTransitionException (a 422, like every other refused
 * transition in Sales), and a cancelled order is a dead end: confirm,
 * delivery and invoice creation are all refused against it.
 *
 * Cancelling is a status flip and nothing more: no stock moves (a sales
 * order never touched stock in the first place), no Tally voucher is
 * queued, no sync event is recorded. Real sales are invoiced in Tally
 * (DEC-20260809-003); the ERP's order book is the ERP-originated subset
 * only, and cancelling one of its rows changes nothing in anyone's books.
 *
 * Writes need sales.manage; sales.view alone can read the order but not
 * cancel it (403), exactly like every other Sales write.
 */
class SalesOrderCancelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

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

        // Issuing an invoice below is a fixture, not the subject: without the
        // GST masters SalesVoucherPayload refuses and stages nothing, and the
        // "a queued voucher survives a refused cancel" assertion has no row to
        // watch. The FG warehouse above is the single Tally-linked godown, so
        // the trait adds none.
        $this->seedSalesTallyMasterData();
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
    private function draftOrder(string $quantity = '2000'): SalesOrder
    {
        $id = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-08-10',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => $quantity, 'unit_price' => '4.50']],
        ])->assertSuccessful()->assertJsonPath('data.status', 'draft')->json('data.id');

        return SalesOrder::query()->with('lines')->findOrFail($id);
    }

    private function confirmedOrder(string $quantity = '2000'): SalesOrder
    {
        $order = $this->draftOrder($quantity);
        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/confirm")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'confirmed');

        return $order->fresh('lines');
    }

    /** FG stock to dispatch from — a delivery is the one Sales action that moves stock. */
    private function seedStock(string $quantity = '5000'): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id, warehouseId: $this->fg->id,
            quantity: $quantity, unitCost: '2.50', reference: 'seed',
        );
    }

    private function deliver(SalesOrder $order, string $quantity)
    {
        return $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-11',
            'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => $quantity]],
        ]);
    }

    private function invoice(SalesOrder $order, string $quantity)
    {
        return $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $order->id,
            'invoice_date' => '2026-08-12',
            'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => $quantity, 'unit_price' => '4.50']],
        ]);
    }

    private function cancel(SalesOrder $order)
    {
        return $this->postJson("/api/v1/sales/sales-orders/{$order->id}/cancel");
    }

    private function fgBalance(): ?string
    {
        $quantity = StockBalance::query()
            ->where('item_id', $this->bottle->id)
            ->where('warehouse_id', $this->fg->id)
            ->value('quantity');

        return $quantity === null ? null : (string) $quantity;
    }

    private function assertRefusedTransition(SalesOrder $order, SalesOrderStatus $from): void
    {
        $this->cancel($order)
            ->assertStatus(422)
            ->assertJsonPath('message', "Cannot transition sales order from \"{$from->value}\" to \"cancelled\".");

        $this->assertSame($from, $order->fresh()->status, 'A refused cancel leaves the status exactly where it was');
        $this->assertFalse(
            $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertOk()->json('data.can_cancel'),
            'can_cancel must say what the service will do',
        );
    }

    // ---- the allowed cases --------------------------------------------------

    public function test_a_draft_order_can_be_cancelled(): void
    {
        $order = $this->draftOrder();

        // The flag says yes before, the resource says cancelled after — and
        // the same resource shape the list and show hand back (Phase 3.5).
        $this->assertTrue($this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertOk()->json('data.can_cancel'));

        $cancelled = $this->cancel($order)->assertOk()->json('data');

        $this->assertSame($order->id, $cancelled['id']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame("SO-{$order->id}", $cancelled['document_number']);
        $this->assertFalse($cancelled['can_cancel'], 'A cancelled order cannot be cancelled again');
        $this->assertSame(SalesOrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_a_confirmed_order_with_nothing_delivered_can_be_cancelled(): void
    {
        $order = $this->confirmedOrder();

        $this->assertTrue($this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertOk()->json('data.can_cancel'));

        $this->cancel($order)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false);

        $this->assertSame(SalesOrderStatus::Cancelled, $order->fresh()->status);
        // The lines are untouched — nothing was delivered, nothing is now.
        $this->assertSame('0.0000', (string) $order->lines()->first()->fresh()->quantity_delivered);
    }

    // ---- the refusals -------------------------------------------------------

    public function test_a_partially_delivered_order_refuses_cancellation(): void
    {
        $this->seedStock();
        $order = $this->confirmedOrder('2000');
        $this->deliver($order, '500')->assertSuccessful();
        $this->assertSame(SalesOrderStatus::PartiallyDelivered, $order->fresh()->status);

        // Goods have left: the order can no longer be made to have not
        // happened. InvalidStatusTransitionException, 422, in its own words.
        $this->assertRefusedTransition($order, SalesOrderStatus::PartiallyDelivered);
        $this->assertSame('500.0000', (string) $order->lines()->first()->fresh()->quantity_delivered);
    }

    public function test_a_completed_order_refuses_cancellation(): void
    {
        $this->seedStock();
        $order = $this->confirmedOrder('2000');
        $this->deliver($order, '2000')->assertSuccessful();
        $this->assertSame(SalesOrderStatus::Completed, $order->fresh()->status);

        $this->assertRefusedTransition($order, SalesOrderStatus::Completed);
    }

    public function test_an_order_with_an_invoice_refuses_cancellation_even_while_still_confirmed(): void
    {
        // Confirmed, nothing delivered — the status alone would allow it —
        // but a DRAFT invoice already hangs off the order. An invoice is a
        // document someone may act on; the order underneath it stays.
        $order = $this->confirmedOrder('2000');
        $invoiceId = $this->invoice($order, '2000')->assertSuccessful()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->assertRefusedTransition($order, SalesOrderStatus::Confirmed);
        $this->assertSame('draft', Invoice::query()->findOrFail($invoiceId)->status->value, 'The refusal touches the invoice as little as the order');

        // Issued: refused all the same (and now a Sales voucher is queued
        // that the cancel must not disturb).
        $this->postJson("/api/v1/sales/invoices/{$invoiceId}/issue")->assertSuccessful()->assertJsonPath('data.status', 'issued');
        $this->assertSame(1, TallySyncEntry::query()->count());

        $this->assertRefusedTransition($order, SalesOrderStatus::Confirmed);
        $this->assertSame(1, TallySyncEntry::query()->count(), 'A refused cancel neither adds nor removes a queued voucher');
    }

    public function test_a_cancelled_order_refuses_confirm_delivery_invoice_and_a_second_cancel(): void
    {
        $this->seedStock();
        $order = $this->draftOrder('2000');
        $this->cancel($order)->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Confirm: the existing draft-only guard already says no.
        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "cancelled" to "confirmed".');

        // Delivery: the existing confirmed/partially_delivered guard says no
        // — before any line, stock issue or Delivery Note exists.
        $this->deliver($order, '100')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "cancelled" to "delivered".');
        $this->assertSame(0, Delivery::query()->count());
        $this->assertSame(0, StockMovement::query()->where('type', 'issue')->count());

        // Invoice creation: refused (Phase 3.5 closes this door — a cancelled
        // order must not grow a bill), and no draft is left behind.
        $this->invoice($order, '100')->assertStatus(422);
        $this->assertSame(0, Invoice::query()->count(), 'A refused invoice leaves no draft row');

        // Cancelling twice: cancelled is not draft or confirmed.
        $this->assertRefusedTransition($order, SalesOrderStatus::Cancelled);

        $this->assertSame(0, TallySyncEntry::query()->count(), 'None of the refusals queued anything for Tally');
    }

    // ---- permission gate ---------------------------------------------------

    public function test_cancel_needs_sales_manage(): void
    {
        $order = $this->draftOrder();

        // sales.view can read the order — and its can_cancel — but not act.
        Sanctum::actingAs($this->userWith('Vasanth Viewer', ['sales.view']));
        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertOk()->assertJsonPath('data.can_cancel', true);
        $this->cancel($order)->assertForbidden();
        $this->assertSame(SalesOrderStatus::Draft, $order->fresh()->status);

        // No sales permission at all: nothing, read or write.
        Sanctum::actingAs($this->userWith('Someone Else', ['production.view']));
        $this->cancel($order)->assertForbidden();
        $this->getJson("/api/v1/sales/sales-orders/{$order->id}")->assertForbidden();
        $this->assertSame(SalesOrderStatus::Draft, $order->fresh()->status);

        // sales.manage: allowed.
        Sanctum::actingAs($this->salesDesk);
        $this->cancel($order)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    // ---- nothing moves -------------------------------------------------------

    public function test_cancelling_touches_no_stock_and_enqueues_nothing_for_tally(): void
    {
        $this->seedStock('5000');
        $order = $this->confirmedOrder('2000');

        $movementsBefore = StockMovement::query()->count();
        $balanceBefore = $this->fgBalance();
        $this->assertSame('5000.0000', $balanceBefore);
        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertSame(0, TallySyncEvent::query()->count());

        $this->cancel($order)->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Stock: not one movement more, the balance exactly as seeded — a
        // sales order never reserved or moved anything, and cancelling it
        // does not "release" anything either.
        $this->assertSame($movementsBefore, StockMovement::query()->count(), 'Cancelling a sales order records no stock movement');
        $this->assertSame($balanceBefore, $this->fgBalance());

        // Tally: no voucher of any type queued, no sync event recorded —
        // nothing about a cancelled ERP order reaches, or is meant to reach,
        // the books (DEC-20260809-003: real sales live in Tally).
        $this->assertSame(0, TallySyncEntry::query()->count(), 'Cancelling a sales order queues nothing for Tally');
        $this->assertSame(0, TallySyncEvent::query()->count(), 'Cancelling a sales order records no sync event');
    }
}
