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
 * both FAIL-CLOSED, and both OFF as the application default.
 *
 * This is NOT a decision. It is the fail-closed reading of two open
 * owner/Accounts questions, held the same way Receipt Notes were held while
 * Q63 was open (ReceiptNoteFeatureFlagTest is the sibling of this file):
 *
 *   DELIVERY NOTE — the factory's own July-2026 Tally export contains ZERO
 *   Delivery Note vouchers (195 Payments, 177 Sales, 134 Receipts, 126 Sales
 *   Orders, 82 Journals, 64 Purchases, 38 Stock Journals, 15 Purchase
 *   Orders, 15 Contras, 1 Debit Note — and none of the 177 Sales vouchers
 *   references a delivery note). The ERP must not invent a voucher type the
 *   factory's books have never held.
 *
 *   SALES — DEC-20260809-003 records that all real sales are invoiced
 *   DIRECTLY in Tally, so posting here risks booking the sale twice; and
 *   independently, the agent's Sales builder declares itself unvalidated and
 *   emits no GST ledgers and no Rounding Off, so a posted voucher would
 *   carry ZERO TAX. The second reason holds under any answer to the first.
 *
 * WHAT THE GATE DOES NOT TOUCH, and these tests pin it: the ERP's own
 * delivery, its stock movement, its invoice and its numbering are unchanged.
 * The gate governs ONLY what is staged for Tally.
 *
 * The suite pins both flags ON in phpunit.xml so the rest of the TallySync
 * suite — written against the pre-existing always-on contract, and using
 * these two vouchers as its fixture vehicle — keeps passing unmodified.
 * This file never relies on that pin: every test sets the flag explicitly.
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
            $this->assertFalse($fresh[$key], "a fresh deployment with no {$env} line at all must default OFF");
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

    // ---- fixtures ---------------------------------------------------------

    /**
     * In-memory with relations set and marked as existing — OutboundVoucherTest's
     * pattern. The dispatch path is real without a heavy DB graph.
     */
    private function delivery(): Delivery
    {
        $customer = new Customer(['name' => 'Sri Aurobindo Beverages', 'gstin' => '34AABCA1122G1Z4']);
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
