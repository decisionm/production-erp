<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * THE ERP RAISES NO SALES INVOICE OF ITS OWN (DEC-20260903-004).
 *
 * Tally originates the sales invoice, the e-invoice and the IRN
 * (DEC-20260831-012), and the ERP imports that voucher and matches it to a
 * confirmed order (DEC-20260902-046). So the ERP's own invoice document is
 * retired: the routes that CREATE and ISSUE one are withdrawn, and no
 * proforma replaces them — quotations are out of scope (DEC-20260902-052).
 *
 * THIS FILE REPLACES InvoiceQuantityCapTest, and the reason it does is worth
 * stating so the guard is not "lost" by a later reader. That test pinned an
 * INTERIM rule: the live spot check (03-Sep-2026) found the ERP invoice fed
 * straight from the order with no cap, no counter and no uniqueness, so one
 * order line could be billed any number of times at any quantity — invoice
 * INV-2 billed 2,000 bottles against a line the Store had dispatched 50 of.
 * The cap made that impossible while the owner decided Q96. The owner then
 * chose retirement, which is the stronger answer to the same defect: a
 * document that cannot be created cannot be over-billed. The cap and its
 * OverInvoiceException are therefore removed with the writer they guarded,
 * and what survives here is the assertion that the door is shut.
 *
 * WHAT STAYS. Every invoice row already written stays exactly as it is —
 * readable, listable, on the order's trace, and never edited or deleted. An
 * order carrying one still refuses cancellation. The receivables and GST
 * figures still read those rows; where they read from INSTEAD is the Tally
 * import build (DEC-20260902-046), not this change, so they now say on their
 * face that they stand on retired history.
 */
class InvoiceRetiredTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private Item $bottle;

    private Customer $customer;

    private User $salesDesk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesDesk = User::factory()->create(['name' => 'Sales Desk', 'is_active' => true]);
        foreach (['sales.view', 'sales.manage', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->salesDesk->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->salesDesk);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
        $this->seedSalesTallyMasterData();
    }

    private function confirmedOrder(string $quantity = '2000'): SalesOrder
    {
        $id = $this->postJson('/api/v1/sales/sales-orders', [
            'customer_id' => $this->customer->id,
            'order_date' => '2026-08-10',
            'lines' => [['item_id' => $this->bottle->id, 'quantity' => $quantity, 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');

        $this->postJson("/api/v1/sales/sales-orders/{$id}/confirm")->assertSuccessful();

        return SalesOrder::query()->with('lines')->findOrFail($id);
    }

    /**
     * One invoice row as HISTORY — written the way the retired document's own
     * rows sit on live, through the models, because there is no longer a
     * route that could write one.
     */
    private function historicInvoice(SalesOrder $order, string $quantity = '2000'): Invoice
    {
        $invoice = Invoice::create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'status' => InvoiceStatus::Issued,
            'invoice_date' => '2026-08-11',
            'created_by' => $this->salesDesk->id,
        ]);

        $invoice->lines()->create([
            'sales_order_line_id' => $order->lines->first()->id,
            'item_id' => $order->lines->first()->item_id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
        ]);

        return $invoice;
    }

    public function test_the_erp_cannot_raise_an_invoice_any_more(): void
    {
        $order = $this->confirmedOrder();

        // The withdrawn door. 404 or 405 — the route is gone, so which of the
        // two Laravel answers with is a routing detail, not a rule; what
        // matters is that it is not a 2xx and that nothing was written.
        $response = $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $order->id,
            'invoice_date' => '2026-08-12',
            'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '10', 'unit_price' => '4.50']],
        ]);

        $this->assertContains($response->status(), [404, 405], 'POST /sales/invoices must no longer be routed');
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, InvoiceLine::query()->count());
    }

    public function test_an_existing_invoice_can_no_longer_be_issued(): void
    {
        $order = $this->confirmedOrder();

        $invoice = Invoice::create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => '2026-08-11',
            'created_by' => $this->salesDesk->id,
        ]);

        $response = $this->postJson("/api/v1/sales/invoices/{$invoice->id}/issue");

        $this->assertContains($response->status(), [404, 405], 'POST /sales/invoices/{id}/issue must no longer be routed');
        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status, 'a draft left standing stays a draft');
    }

    public function test_history_stays_readable_in_the_list_and_on_the_document(): void
    {
        $order = $this->confirmedOrder();
        $invoice = $this->historicInvoice($order);

        $row = collect($this->getJson('/api/v1/sales/invoices')->assertOk()->json('data'))
            ->firstWhere('id', $invoice->id);

        $this->assertNotNull($row, 'a written invoice stays listable');

        $shown = $this->getJson("/api/v1/sales/invoices/{$invoice->id}")->assertOk()->json('data');

        $this->assertSame($invoice->id, $shown['id']);
        $this->assertSame($order->id, $shown['sales_order_id']);
        $this->assertCount(1, $shown['lines']);
    }

    public function test_an_order_carrying_an_invoice_still_refuses_cancellation(): void
    {
        // The rule that mattered most about the retired document: an order
        // billed to a customer is not something Sales may quietly withdraw.
        // It has to keep working against a history row, because a history row
        // is the only kind there is now.
        $order = $this->confirmedOrder();
        $this->historicInvoice($order);

        $this->postJson("/api/v1/sales/sales-orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "confirmed" to "cancelled".');

        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_the_receivables_and_gst_figures_say_they_stand_on_retired_history(): void
    {
        $order = $this->confirmedOrder();
        $this->historicInvoice($order);

        $finance = User::factory()->create(['is_active' => true]);
        foreach (['finance.view', 'compliance.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $finance->givePermissionTo($permission);
        }
        Sanctum::actingAs($finance);

        // A LABEL, not a paragraph: the figure is unchanged and still adds up,
        // and the one thing a reader has to know is where it comes from now
        // that nothing new feeds it.
        //
        // Carried BESIDE `data`, never inside it. Receivables answers a bare
        // LIST of rows and GSTR-1 answers an object, so a key pushed inside
        // `data` would either break the list's shape or mean two different
        // things on two screens. As a sibling it is metadata about the
        // payload on both, which is what it is.
        $this->getJson('/api/v1/finance/reports/receivables')
            ->assertOk()
            ->assertJsonPath('basis', 'Retired ERP invoice history');

        $this->getJson('/api/v1/compliance/reports/gstr1')
            ->assertOk()
            ->assertJsonPath('basis', 'Retired ERP invoice history');
    }
}
