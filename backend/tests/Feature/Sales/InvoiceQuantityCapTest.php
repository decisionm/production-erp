<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * AN INVOICE CANNOT BILL MORE THAN THE CUSTOMER ORDERED — across every invoice
 * raised against the line, and across the lines of one invoice.
 *
 * The live spot check (03-Sep-2026) found the ERP's invoice fed straight from
 * the order: no cap, no counter, no uniqueness, so one order line could be
 * invoiced any number of times at any quantity. Whether the ERP keeps its own
 * invoice at all is the owner's (Q96: Tally originates the sales invoice,
 * DEC-20260831-012). What is true under EITHER answer is pinned here: the
 * quantity billed against an order line never exceeds the quantity ordered.
 * The cap is the ORDERED quantity, not the delivered one, deliberately — a
 * proforma may precede dispatch, and capping at delivered would decide Q96
 * from the code.
 *
 * Refused the way the delivery path refuses over-delivery: a 422 that names
 * the line, what remains and what was asked, and a rolled-back transaction
 * that leaves no half-written invoice behind.
 */
class InvoiceQuantityCapTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private Item $bottle;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $salesDesk = User::factory()->create(['name' => 'Sales Desk', 'is_active' => true]);
        foreach (['sales.view', 'sales.manage', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $salesDesk->givePermissionTo($permission);
        }
        Sanctum::actingAs($salesDesk);

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

    /** @param  list<string>  $quantities  one invoice line per entry, all against the order's one line */
    private function invoice(SalesOrder $order, array $quantities)
    {
        $lineId = $order->lines->first()->id;

        return $this->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $order->id,
            'invoice_date' => '2026-08-12',
            'lines' => array_map(
                fn (string $quantity) => ['sales_order_line_id' => $lineId, 'quantity' => $quantity, 'unit_price' => '4.50'],
                $quantities,
            ),
        ]);
    }

    private function refusedMessage(SalesOrder $order, string $remaining, string $requested): string
    {
        $lineId = $order->lines->first()->id;

        return "Cannot invoice more than the remaining ordered quantity for sales order line #{$lineId}: "
            ."remaining {$remaining}, requested {$requested}.";
    }

    public function test_one_invoice_cannot_bill_more_than_the_order_line(): void
    {
        $order = $this->confirmedOrder('2000');

        $this->invoice($order, ['2001'])
            ->assertStatus(422)
            ->assertJsonPath('message', $this->refusedMessage($order, '2000.0000', '2001.0000'));

        // Rolled back whole: no invoice header survives a refused line.
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, InvoiceLine::query()->count());

        // Exactly the ordered quantity is fine.
        $this->invoice($order, ['2000'])->assertCreated();
    }

    public function test_the_same_order_line_cannot_be_invoiced_past_the_ordered_quantity_across_invoices(): void
    {
        $order = $this->confirmedOrder('2000');

        $this->invoice($order, ['1500'])->assertCreated();

        // 500 remain. 600 is refused, and the refusal says so.
        $this->invoice($order, ['600'])
            ->assertStatus(422)
            ->assertJsonPath('message', $this->refusedMessage($order, '500.0000', '600.0000'));

        $this->assertSame(1, Invoice::query()->count());

        // The remaining 500 may still be billed; after that, nothing.
        $this->invoice($order, ['500'])->assertCreated();
        $this->invoice($order, ['1'])
            ->assertStatus(422)
            ->assertJsonPath('message', $this->refusedMessage($order, '0.0000', '1.0000'));

        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame('2000.0000', bcadd((string) InvoiceLine::query()->sum('quantity'), '0', 4));
    }

    public function test_two_lines_of_one_invoice_against_the_same_order_line_are_counted_together(): void
    {
        $order = $this->confirmedOrder('2000');

        // 1,500 + 600 on one document is the same over-billing as two documents.
        $this->invoice($order, ['1500', '600'])
            ->assertStatus(422)
            ->assertJsonPath('message', $this->refusedMessage($order, '500.0000', '600.0000'));

        $this->assertSame(0, Invoice::query()->count());

        $this->invoice($order, ['1500', '500'])->assertCreated();
    }
}
