<?php

namespace Tests\Feature\TallySync\PerType;

use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Services\TallyLedgerMappingService;

/**
 * Sales (invoice): a draft invoice moved to ISSUED → Invoice::updated →
 * Tally 'Sales'.
 *
 * The two endpoints that used to drive this (POST /sales/invoices and
 * .../issue) are withdrawn: the ERP's own sales invoice is retired
 * (DEC-20260903-004). The listener never hung off them — it hangs off the
 * model transition — so this lifecycle is unchanged and is now driven there
 * directly. No NEW invoice can appear on live; what this suite protects is
 * the queue's contract for the rows that already exist.
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
        // THIS FIXTURE'S BUYER IS IN PUDUCHERRY (GSTIN 34AABCA1122G1Z4), the
        // company's own state, so the sale is LOCAL and the local role is the
        // one the voucher names. Both roles are mapped so the test would still
        // read correctly if the fixture's party ever moved states.
        app(TallyLedgerMappingService::class)->setMany([
            TallyLedgerRole::SalesLocal->value => 'Sales - Local',
            TallyLedgerRole::SalesInterstate->value => 'Sales - Interstate',
        ]);
    }

    private function salesDesk(): static
    {
        return $this->asUser($this->staff('Sales Desk', ['sales.view', 'sales.manage']));
    }

    /**
     * THE DOMAIN TRANSITION, not the endpoint. The ERP's own invoice is
     * retired (DEC-20260903-004) and there is no route left to create or
     * issue one — but the Tally staging listener has always hung off
     * `Invoice::updated`, not off the controller, so the lifecycle this file
     * pins is reached exactly as it always was. What is no longer under test
     * here is the ROUTE; InvoiceRetiredTest holds that door shut.
     */
    protected function enqueueViaDomain(): TallySyncEntry
    {
        $invoice = Invoice::create([
            'sales_order_id' => $this->order->id,
            'customer_id' => $this->order->customer_id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => '2026-08-10',
            'notes' => 'August supply',
        ]);
        $invoice->lines()->create([
            'sales_order_line_id' => $this->line->id,
            'item_id' => $this->line->item_id,
            'quantity' => '2000',
            'unit_price' => '4.50',
        ]);
        $this->invoiceId = $invoice->id;

        // A draft has no statutory effect and enqueues nothing …
        $this->assertSame(0, TallySyncEntry::query()->count(), 'A draft invoice must not reach the queue');

        // … issuing it does.
        $invoice->update(['status' => InvoiceStatus::Issued]);
        $this->assertSame('issued', $invoice->fresh()->status->value);

        return TallySyncEntry::query()->sole();
    }

    /**
     * Re-saving an ALREADY issued invoice stages nothing further: the
     * listener fires on a CHANGE to issued, and a no-op save changes nothing.
     * The old 422 came from the withdrawn endpoint's status guard; the
     * property that actually matters — one voucher, not two — is asserted
     * straight against the queue.
     */
    protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void
    {
        $invoice = Invoice::findOrFail($this->invoiceId);
        $invoice->update(['status' => InvoiceStatus::Issued]);

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, TallySyncEntry::query()->count(), 'Re-saving an issued invoice must not stage a second voucher');
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
        // The GST masters, before the voucher is staged: SalesVoucherPayload
        // refuses and stages NOTHING without them, so this test's subject would
        // not exist. The base's own four tests seed themselves; this one is
        // this class's, so it seeds itself too.
        $this->seedSalesTallyMasterData();

        $entry = $this->enqueueViaDomain();

        $this->assertSame('Sri Aurobindo Beverages', $entry->payload['party_ledger']);
        $this->assertSame('34AABCA1122G1Z4', $entry->payload['party_gstin']);
        // The ledger is named PER LINE now, and chosen by supply type.
        $this->assertSame('intra_state', $entry->payload['supply_type']);
        $this->assertSame('Sales - Local', $entry->payload['lines'][0]['sales_ledger']);
        $this->assertSame('2026-08-10', $entry->payload['voucher_date']);
        $this->assertSame('500ml PET Bottle', $entry->payload['lines'][0]['item']);
        // `total_amount` was retired by the GST rewrite: 2000 x 4.50 = 9000
        // taxable, CGST 810 + SGST 810, and the party is debited the
        // tax-inclusive 10620 — which is already whole, so no rounding line.
        $this->assertArrayNotHasKey('total_amount', $entry->payload);
        $this->assertSame('9000.0000', $entry->payload['taxable_value']);
        $this->assertSame('10620', $entry->payload['party_amount']);
        $this->assertSame(['CGST', 'SGST'], array_column($entry->payload['tax_ledgers'], 'ledger'));
        $this->assertNull($entry->payload['round_off']);

        // Same keys, same gate as the Receipt Note: omitted for a reader
        // without finance.*, whole for the agent (salesInvoice.ts needs them).
        $viewerRow = $this->listedRow($entry->id);
        $this->assertSame('2000.0000', $viewerRow['payload']['lines'][0]['quantity']);
        $this->assertArrayNotHasKey('rate', $viewerRow['payload']['lines'][0]);
        $this->assertArrayNotHasKey('amount', $viewerRow['payload']['lines'][0]);
        $this->assertArrayNotHasKey('total_amount', $viewerRow['payload']);
        // Every money key the GST rewrite added is withheld from this reader…
        $this->assertArrayNotHasKey('party_amount', $viewerRow['payload']);
        $this->assertArrayNotHasKey('taxable_value', $viewerRow['payload']);
        $this->assertArrayNotHasKey('amount', $viewerRow['payload']['tax_ledgers'][0]);
        // …and the LEDGER NAMES survive, because a ledger name is not a price.
        $this->assertSame('Sales - Local', $viewerRow['payload']['lines'][0]['sales_ledger'], 'A ledger name is not a price');
        $this->assertSame('CGST', $viewerRow['payload']['tax_ledgers'][0]['ledger']);

        $agentRow = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $entry->id);
        $this->assertSame('4.5000', $agentRow['payload']['lines'][0]['rate']);
        $this->assertSame('9000.0000', $agentRow['payload']['taxable_value']);
        $this->assertSame('810.0000', $agentRow['payload']['tax_ledgers'][0]['amount'], 'the agent needs the figures the reader may not see');
    }
}
