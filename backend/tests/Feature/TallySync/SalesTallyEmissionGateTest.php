<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tally-sync.delivery_notes_enabled and tally-sync.sales_invoices_enabled —
 * both OFF, and that is the DECIDED state, not a holding position.
 *
 * THE DEFAULT HAS NOW POINTED BOTH WAYS, and this file has followed it both
 * times rather than being deleted, because a default is worth pinning in
 * whichever direction it points: it is what a fresh deployment gets.
 *   · OFF originally — real sales were invoiced directly in Tally.
 *   · ON under DEC-20260831-007 — the ERP was to originate the sale.
 *   · OFF again under DEC-20260831-012, which supersedes it the same day:
 *     the ERP sends NO Sales Order, NO Delivery Note and NO Sales Invoice to
 *     Tally. Tally creates the Sales Invoice, the e-invoice and the e-way
 *     details, and the ERP IMPORTS that voucher instead
 *     (TallySalesInvoiceImporter, and TallySalesInvoiceImportTest beside it).
 *
 * WHY THE ON-PATH TESTS SURVIVE. The builder and both listeners are left in
 * the tree, dormant behind the flags. A superseded decision's code is history,
 * not litter, and the direction has already reversed twice — so the mechanism
 * stays pinned, and what changed is only which way the shipped default points.
 *
 * WHAT NEVER CHANGED, across all three positions: staging refuses by name when
 * the master data is incomplete, and a refusal NEVER blocks the factory's own
 * act — the invoice still issues, the delivery still stands.
 */
class SalesTallyEmissionGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The phpunit.xml pin only supplies a value when the env var is set; this
     * reads config/tally-sync.php FRESH with the env var cleared — the same
     * default a server with no such line in its .env would get.
     */
    private function assertFreshDefaultIsOff(string $env, string $key): void
    {
        $original = ['putenv' => getenv($env), 'env' => $_ENV[$env] ?? null, 'server' => $_SERVER[$env] ?? null];

        putenv($env);
        unset($_ENV[$env], $_SERVER[$env]);

        try {
            $fresh = require config_path('tally-sync.php');
            $this->assertFalse($fresh[$key], "a fresh deployment with no {$env} line at all must default OFF — DEC-20260831-012, the ERP posts no sales document to Tally");
        } finally {
            if ($original['putenv'] !== false) {
                putenv("{$env}={$original['putenv']}");
            }
            if ($original['env'] !== null) {
                $_ENV[$env] = $original['env'];
            }
            if ($original['server'] !== null) {
                $_SERVER[$env] = $original['server'];
            }
        }
    }

    public function test_the_delivery_note_config_default_is_off_when_no_env_is_set(): void
    {
        $this->assertFreshDefaultIsOff('TALLY_SYNC_DELIVERY_NOTES_ENABLED', 'delivery_notes_enabled');
    }

    public function test_the_sales_invoice_config_default_is_off_when_no_env_is_set(): void
    {
        $this->assertFreshDefaultIsOff('TALLY_SYNC_SALES_INVOICES_ENABLED', 'sales_invoices_enabled');
    }

    public function test_with_the_flag_off_a_dispatch_stages_no_delivery_note(): void
    {
        config(['tally-sync.delivery_notes_enabled' => false]);

        event(new DeliveryDispatched($this->delivery()));

        $this->assertSame(0, TallySyncEntry::query()->count(), 'the listener no-ops rather than staging a Delivery Note');
    }

    public function test_with_the_flag_on_a_dispatch_stages_exactly_one_delivery_note(): void
    {
        config(['tally-sync.delivery_notes_enabled' => true]);

        event(new DeliveryDispatched($this->delivery()));

        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Delivery Note', $entry->tally_voucher_type);
        $this->assertSame('DN-3', $entry->payload['voucher_number']);
    }

    public function test_with_the_flag_off_issuing_an_invoice_stages_no_sales_voucher(): void
    {
        config(['tally-sync.sales_invoices_enabled' => false]);

        $invoice = $this->draftInvoice();
        $invoice->update(['status' => InvoiceStatus::Issued]);

        $this->assertSame(0, TallySyncEntry::query()->count(), 'the listener no-ops rather than staging a Sales voucher');
    }

    /**
     * THE SECOND LOCK, and the more important one now. Opening the feature flag
     * is NOT sufficient to stage a Sales voucher: SalesVoucherPayload refuses
     * unless the master data a GST-correct voucher needs is actually present —
     * the customer's Tally ledger name, its state, an HSN and rate per item, the
     * sales and tax ledger mappings, and a resolvable godown.
     *
     * This fixture invoice has none of that, which is exactly the state a live
     * instance is in today (tally_ledger_mappings is empty, and 11 of 146 items
     * carry an HSN). So the flag is on and NOTHING is staged — which is the
     * whole point: the old builder would have happily staged a zero-tax voucher
     * here. `SalesVoucherPayloadTest` covers the complete-data path.
     */
    public function test_the_flag_alone_does_not_stage_a_voucher_without_the_master_data(): void
    {
        config(['tally-sync.sales_invoices_enabled' => true]);

        $invoice = $this->draftInvoice();
        $invoice->update(['status' => InvoiceStatus::Issued]);

        $this->assertSame(
            0,
            TallySyncEntry::query()->count(),
            'an invoice whose GST/ledger master data is incomplete stages NOTHING rather than a malformed voucher',
        );
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status, 'and the ERP invoice still issues');
    }

    /**
     * THE POINT OF THE WHOLE GATE: what it withholds is the Tally voucher and
     * nothing else. With both flags off the ERP's own invoice still issues and
     * the ERP's own delivery still stands — the factory's paperwork is
     * untouched, only the posting to Tally is withheld.
     */
    public function test_the_gate_withholds_the_voucher_and_nothing_else(): void
    {
        config(['tally-sync.delivery_notes_enabled' => false, 'tally-sync.sales_invoices_enabled' => false]);

        $invoice = $this->draftInvoice();
        $invoice->update(['status' => InvoiceStatus::Issued]);
        event(new DeliveryDispatched($this->delivery()));

        $this->assertSame(0, TallySyncEntry::query()->count(), 'nothing at all reaches the Tally queue');
        $this->assertSame(
            InvoiceStatus::Issued,
            $invoice->fresh()->status,
            'the ERP invoice is issued regardless — the gate is about Tally, not about the sale',
        );
    }

    /**
     * THE FAIL-CLOSED HALF, in the owner's own terms: "missing customer ledger
     * ... must record a clear refusal and must never block invoice issuance".
     *
     * The delivery's customer has no Tally ledger name. Nothing is staged, and
     * the DELIVERY ITSELF STILL STANDS — the goods physically went, and Tally
     * is bookkeeping that follows rather than a veto over what already happened.
     */
    public function test_a_delivery_whose_customer_has_no_tally_ledger_stages_nothing_and_still_stands(): void
    {
        config(['tally-sync.delivery_notes_enabled' => true]);

        $delivery = $this->delivery();
        $delivery->salesOrder->customer->forceFill(['tally_ledger_name' => null]);

        event(new DeliveryDispatched($delivery));

        $this->assertSame(0, TallySyncEntry::query()->count(), 'an unmapped customer ledger refuses rather than guessing');
        $this->assertTrue($delivery->exists, 'the dispatch is untouched by a Tally refusal');
    }

    // ---- fixtures ---------------------------------------------------------

    /**
     * In-memory with relations set and marked as existing — OutboundVoucherTest's
     * pattern. The dispatch path is real without a heavy DB graph.
     */
    private function delivery(): Delivery
    {
        // The Tally ledger name is what the voucher posts against — never the
        // ERP's own label — so a fixture without one is refused by design.
        $customer = new Customer(['name' => 'Sri Aurobindo Beverages', 'gstin' => '34AABCA1122G1Z4']);
        $customer->forceFill(['tally_ledger_name' => 'Sri Aurobindo Beverages']);
        $order = new SalesOrder;
        $order->setRelation('customer', $customer);

        $line = new DeliveryLine(['quantity' => '2000.0000']);
        $line->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $delivery = new Delivery(['delivered_date' => now(), 'notes' => 'Truck A']);
        $delivery->id = 3;
        $delivery->exists = true;
        $delivery->setRelation('lines', collect([$line]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $order);

        return $delivery;
    }

    /**
     * A real persisted invoice — the Sales listener hangs off Invoice::updated,
     * so this one has to be a genuine row that genuinely changes status.
     */
    private function draftInvoice(): Invoice
    {
        $customer = Customer::create([
            'code' => 'CUST-GATE-1',
            'name' => 'Sri Aurobindo Beverages',
            'is_active' => true,
        ]);

        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => 'confirmed',
            'order_date' => '2026-08-10',
        ]);

        return Invoice::create([
            'sales_order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => '2026-08-10',
        ]);
    }
}
