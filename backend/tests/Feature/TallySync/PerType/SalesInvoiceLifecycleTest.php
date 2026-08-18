<?php

namespace Tests\Feature\TallySync\PerType;

use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallyLedgerMappingService;

/**
 * Sales (invoice): a draft invoice (POST /sales/invoices) ISSUED (POST
 * /sales/invoices/{id}/issue) → Invoice::updated → Tally 'Sales'.
 *
 * REAL SALES ARE INVOICED IN TALLY (DEC-20260809-003) — the ERP Sales
 * module is demo-scale and its Tally builder is unvalidated and carries no
 * GST (audit §4.7). This suite proves the queue's CONTRACT for the type
 * that exists in code, so the Control Center can show such a row honestly;
 * it does not encourage posting real invoices from the ERP, and nothing in
 * it changes what would reach Tally.
 *
 * This type's own facts beyond the shared lifecycle:
 *
 *   - DUPLICATE-REFUSED is the issue transition (InvoiceService::issue): an
 *     invoice already issued cannot be issued again — the model event that
 *     enqueues fires only on the draft → issued change;
 *   - the payload's rate/amount/total are selling prices, gated on the wire
 *     exactly like the Receipt Note's purchase rates: same keys, same
 *     resource, one gate (TallySyncEntryResource::LINE_RATE_KEYS).
 */
class SalesInvoiceLifecycleTest extends PerTypeLifecycleTestCase
{
    private SalesOrder $order;

    private SalesOrderLine $line;

    private ?int $invoiceId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Sri Aurobindo Beverages', 'gstin' => '34AABCA1122G1Z4']);
        $this->order = SalesOrder::create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed, 'order_date' => '2026-08-09']);
        $this->line = $this->order->lines()->create(['item_id' => $bottle->id, 'quantity' => '2000', 'unit_price' => '4.50', 'quantity_delivered' => 0]);

        // The configured Sales ledger rides the payload (Settings → Ledger
        // Mappings), never a hardcoded "Sales Account".
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Sales->value => 'Sales - Local']);
    }

    private function salesDesk(): static
    {
        return $this->asUser($this->staff('Sales Desk', ['sales.view', 'sales.manage']));
    }

    protected function enqueueViaDomain(): TallySyncEntry
    {
        $this->invoiceId = $this->salesDesk()->postJson('/api/v1/sales/invoices', [
            'sales_order_id' => $this->order->id,
            'invoice_date' => '2026-08-10',
            'notes' => 'August supply',
            'lines' => [['sales_order_line_id' => $this->line->id, 'quantity' => '2000', 'unit_price' => '4.50']],
        ])->assertSuccessful()->json('data.id');

        // A draft has no statutory effect and enqueues nothing …
        $this->assertSame(0, TallySyncEntry::query()->count(), 'A draft invoice must not reach the queue');

        // … issuing it does.
        $this->salesDesk()->postJson("/api/v1/sales/invoices/{$this->invoiceId}/issue")
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'issued');

        return TallySyncEntry::query()->sole();
    }

    protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void
    {
        $this->salesDesk()->postJson("/api/v1/sales/invoices/{$this->invoiceId}/issue")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition invoice from "issued" to "issued".');

        $this->assertSame(1, Invoice::query()->count());
    }

    protected function expectedCategoryKey(): string
    {
        return 'sales_invoice';
    }

    protected function expectedVoucherType(): string
    {
        return 'Sales';
    }

    protected function expectedDocumentNumber(TallySyncEntry $entry): string
    {
        return "INV-{$entry->syncable_id}";
    }

    protected function tallyRejection(): string
    {
        // A party-ledger miss — the resource recognises no fix for it, and
        // must say nothing rather than send someone to the wrong screen.
        return "Ledger 'Sri Aurobindo Beverages' does not exist!";
    }

    protected function expectedFixPath(): ?string
    {
        return null;
    }

    public function test_the_payload_names_the_configured_sales_ledger_and_its_prices_are_gated_on_the_wire(): void
    {
        $entry = $this->enqueueViaDomain();

        $this->assertSame('Sri Aurobindo Beverages', $entry->payload['party_ledger']);
        $this->assertSame('34AABCA1122G1Z4', $entry->payload['party_gstin']);
        $this->assertSame('Sales - Local', $entry->payload['sales_ledger']);
        $this->assertSame('2026-08-10', $entry->payload['voucher_date']);
        $this->assertSame('500ml PET Bottle', $entry->payload['lines'][0]['item']);
        $this->assertSame('9000.0000', $entry->payload['total_amount']);

        // Same keys, same gate as the Receipt Note: omitted for a reader
        // without finance.*, whole for the agent (salesInvoice.ts needs them).
        $viewerRow = $this->listedRow($entry->id);
        $this->assertSame('2000.0000', $viewerRow['payload']['lines'][0]['quantity']);
        $this->assertArrayNotHasKey('rate', $viewerRow['payload']['lines'][0]);
        $this->assertArrayNotHasKey('amount', $viewerRow['payload']['lines'][0]);
        $this->assertArrayNotHasKey('total_amount', $viewerRow['payload']);
        $this->assertSame('Sales - Local', $viewerRow['payload']['sales_ledger'], 'A ledger name is not a price');

        $agentRow = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $entry->id);
        $this->assertSame('4.5000', $agentRow['payload']['lines'][0]['rate']);
        $this->assertSame('9000.0000', $agentRow['payload']['total_amount']);
    }
}
