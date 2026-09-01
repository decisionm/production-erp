<?php

namespace Tests\Feature\TallySync;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Exceptions\TallyXmlUnreadable;
use App\Modules\TallySync\Models\Enums\TallyInvoiceMatchState;
use App\Modules\TallySync\Models\TallySalesInvoice;
use App\Modules\TallySync\Services\TallySalesInvoiceImporter;
use App\Modules\TallySync\Services\TallyVoucherXmlReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE SALES INTEGRATION IN THE ONLY DIRECTION THAT SURVIVES — inbound.
 *
 * DEC-20260831-012: the ERP sends no Sales Order, no Delivery Note and no
 * Sales Invoice to Tally. Tally creates the Sales Invoice, the e-invoice and
 * the e-way details, and the ERP imports that voucher and matches it to its
 * own Sales Order.
 *
 * EVERY ASSERTION HERE RUNS AGAINST REAL TALLY BYTES.
 * `tests/fixtures/tally/sales-invoices.xml` is three vouchers lifted verbatim
 * out of the factory's own 31-Aug-2026 export — UTF-16LE with a BOM, no XML
 * declaration, the full e-way and IRN scaffolding, and Tally's stray &#4;
 * control entity in ORDERNO. Only the government identifiers (IRN, IRN ack
 * number, QR code, GSTINs, e-way bill numbers) are redacted; the party names,
 * order references, items, quantities and amounts — everything the importer
 * actually reads — are untouched, because a fixture that has been tidied up
 * proves the parser handles tidy files.
 *
 * The three were chosen to cover the three verdicts the real export contains:
 *   696/26-27  Sangam Pharma Packers  — no order reference at all (6 of 55)
 *   699/26-27  Revive Formulations    — numeric reference "480"
 *   701/26-27  Areete Life Science    — a long free-text customer PO string
 */
class TallySalesInvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    /** Lowercase `fixtures` is the repo's convention and the live server is case-sensitive. */
    private function fixture(): string
    {
        return base_path('tests/fixtures/tally/sales-invoices.xml');
    }

    private function xml(): string
    {
        return (string) file_get_contents($this->fixture());
    }

    private function importer(): TallySalesInvoiceImporter
    {
        return app(TallySalesInvoiceImporter::class);
    }

    /** A customer linked to a Tally ledger the way the import command links one. */
    private function customerFor(string $ledger, string $name): Customer
    {
        $customer = Customer::create([
            'code' => 'C-'.substr(md5($ledger), 0, 6),
            'name' => $name,
            'is_active' => true,
        ]);

        // tally_ledger_name is outside Customer's #[Fillable] on purpose — the
        // import command is its only writer — so it is set the same way here.
        $customer->forceFill(['tally_ledger_name' => $ledger])->save();

        return $customer;
    }

    private function orderFor(Customer $customer, ?string $reference, SalesOrderStatus $status = SalesOrderStatus::Confirmed): SalesOrder
    {
        return SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => $status,
            'order_date' => '2026-07-28',
            'customer_po_reference' => $reference,
        ]);
    }

    // ── The encoding, which is the whole reason a reader class exists ────────

    public function test_it_reads_tallys_own_utf16_bytes_without_being_told_the_encoding(): void
    {
        $raw = $this->xml();

        $this->assertSame("\xFF\xFE", substr($raw, 0, 2), 'the fixture must still be real UTF-16LE Tally output');

        $vouchers = (new TallyVoucherXmlReader)->vouchers($raw, 'Sales');

        $this->assertCount(3, $vouchers);
    }

    public function test_it_reads_only_sales_vouchers_and_ignores_sales_order_vouchers(): void
    {
        $reader = new TallyVoucherXmlReader;
        $orders = (string) file_get_contents(base_path('tests/fixtures/tally/sales-orders.xml'));

        // The Sales Order export holds Sales ORDER vouchers, which the invoice
        // importer must not mistake for invoices.
        $this->assertCount(2, $reader->vouchers($orders, 'Sales Order'));
        $this->assertCount(0, $reader->vouchers($orders, 'Sales'));
    }

    public function test_unreadable_bytes_are_refused_by_name_rather_than_parsed_into_nothing(): void
    {
        $this->expectException(TallyXmlUnreadable::class);

        (new TallyVoucherXmlReader)->vouchers('this is not a Tally export', 'Sales');
    }

    // ── The match ───────────────────────────────────────────────────────────

    public function test_a_tally_invoice_matches_the_erp_order_carrying_the_same_customer_po_reference(): void
    {
        $customer = $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');
        $order = $this->orderFor($customer, '480');

        $result = $this->importer()->import($this->xml(), write: true);

        $this->assertSame(3, $result['read']);
        $this->assertSame(1, $result['matched']);

        $invoice = TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole();

        $this->assertSame(TallyInvoiceMatchState::Matched, $invoice->match_state);
        $this->assertSame($order->id, $invoice->sales_order_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame('480', $invoice->customer_po_reference);
        $this->assertSame('2026-08-01', $invoice->voucher_date->toDateString());
    }

    public function test_it_matches_a_long_free_text_customer_po_string_exactly_as_tally_holds_it(): void
    {
        $customer = $this->customerFor('Areete Life Science Pvt.Ltd', 'Areete Life Science');
        $order = $this->orderFor($customer, 'P.O.No:108-ALS-26-27 Dt:31.07.2026');

        $this->importer()->import($this->xml(), write: true);

        $invoice = TallySalesInvoice::query()->where('voucher_number', '701/26-27')->sole();

        $this->assertSame(TallyInvoiceMatchState::Matched, $invoice->match_state);
        $this->assertSame($order->id, $invoice->sales_order_id);
    }

    public function test_the_matched_order_can_read_back_the_tally_invoice_that_closed_it(): void
    {
        $customer = $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');
        $order = $this->orderFor($customer, '480');

        $this->importer()->import($this->xml(), write: true);

        $this->assertSame('699/26-27', $order->fresh()->tallyInvoices()->sole()->voucher_number);
        $this->assertTrue($order->fresh()->isInvoicedInTally());
    }

    // ── Every way it refuses to guess ───────────────────────────────────────

    public function test_a_voucher_with_no_order_reference_is_recorded_unmatched_not_dropped(): void
    {
        $this->customerFor('Sangam Pharma Packers', 'Sangam Pharma Packers');

        $this->importer()->import($this->xml(), write: true);

        $invoice = TallySalesInvoice::query()->where('voucher_number', '696/26-27')->sole();

        $this->assertSame(TallyInvoiceMatchState::UnmatchedNoReference, $invoice->match_state);
        $this->assertNull($invoice->sales_order_id);
        $this->assertStringContainsString('no BASICPURCHASEORDERNO', $invoice->match_detail);
    }

    public function test_tallys_literal_not_applicable_is_never_treated_as_an_order_reference(): void
    {
        $this->customerFor('Sangam Pharma Packers', 'Sangam Pharma Packers');
        // The order below carries the sentence Tally puts in ORDERNO. If the
        // importer ever read ORDERNO it would match this, which would be wrong.
        $this->orderFor($this->customerFor('Sangam Pharma Packers 2', 'Decoy'), 'Not Applicable');

        $this->importer()->import($this->xml(), write: true);

        $this->assertSame(
            TallyInvoiceMatchState::UnmatchedNoReference,
            TallySalesInvoice::query()->where('voucher_number', '696/26-27')->sole()->match_state,
        );
    }

    public function test_an_unknown_tally_party_never_creates_a_customer(): void
    {
        $result = $this->importer()->import($this->xml(), write: true);

        $this->assertSame(0, Customer::query()->count(), 'the importer must never invent a commercial party');
        $this->assertSame(0, $result['matched']);
        $this->assertSame(3, $result['unmatched']);

        $invoice = TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole();
        $this->assertSame(TallyInvoiceMatchState::UnmatchedNoCustomer, $invoice->match_state);
        $this->assertStringContainsString('Revive Formulations India Pvt Ltd', $invoice->match_detail);
    }

    public function test_a_known_customer_with_no_matching_order_never_creates_one(): void
    {
        $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');

        $this->importer()->import($this->xml(), write: true);

        $this->assertSame(0, SalesOrder::query()->count(), 'the importer must never invent a sales order');
        $this->assertSame(
            TallyInvoiceMatchState::UnmatchedNoOrder,
            TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole()->match_state,
        );
    }

    public function test_two_orders_on_one_po_reference_are_refused_rather_than_picked_between(): void
    {
        // One PO covering several orders is normal and allowed
        // (SalesOrderCustomerPoReferenceTest pins that it is not unique), so
        // the importer meets this and must not choose.
        $customer = $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');
        $first = $this->orderFor($customer, '480');
        $second = $this->orderFor($customer, '480');

        $this->importer()->import($this->xml(), write: true);

        $invoice = TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole();

        $this->assertSame(TallyInvoiceMatchState::Ambiguous, $invoice->match_state);
        $this->assertNull($invoice->sales_order_id);
        $this->assertStringContainsString('#'.$first->id, $invoice->match_detail);
        $this->assertStringContainsString('#'.$second->id, $invoice->match_detail);
    }

    public function test_a_cancelled_order_is_not_a_match_candidate(): void
    {
        $customer = $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');
        $this->orderFor($customer, '480', SalesOrderStatus::Cancelled);

        $this->importer()->import($this->xml(), write: true);

        $this->assertSame(
            TallyInvoiceMatchState::UnmatchedNoOrder,
            TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole()->match_state,
        );
    }

    // ── Dry run, and re-import ──────────────────────────────────────────────

    public function test_a_dry_run_reaches_the_same_verdicts_and_writes_nothing(): void
    {
        $customer = $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');
        $order = $this->orderFor($customer, '480');

        $dry = $this->importer()->import($this->xml(), write: false);

        $this->assertSame(0, TallySalesInvoice::query()->count(), 'a dry run must write nothing at all');
        $this->assertFalse($dry['written']);
        $this->assertSame(1, $dry['matched']);

        $written = $this->importer()->import($this->xml(), write: true);

        $this->assertSame($dry['matched'], $written['matched'], 'the dry run must not disagree with the write that follows it');
        $this->assertSame($order->id, TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole()->sales_order_id);
    }

    public function test_re_importing_the_same_export_does_not_duplicate_a_voucher(): void
    {
        $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');
        $this->orderFor($this->customerFor('Areete Life Science Pvt.Ltd', 'Areete'), 'P.O.No:108-ALS-26-27 Dt:31.07.2026');

        $this->importer()->import($this->xml(), write: true);
        $this->importer()->import($this->xml(), write: true);

        $this->assertSame(3, TallySalesInvoice::query()->count(), 'Tally GUID is the identity, so a re-export is a no-op');
    }

    public function test_a_late_order_makes_a_previously_unmatched_voucher_match_on_re_import(): void
    {
        $customer = $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');

        $this->importer()->import($this->xml(), write: true);
        $this->assertSame(
            TallyInvoiceMatchState::UnmatchedNoOrder,
            TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole()->match_state,
        );

        $order = $this->orderFor($customer, '480');
        $this->importer()->import($this->xml(), write: true);

        $invoice = TallySalesInvoice::query()->where('voucher_number', '699/26-27')->sole();
        $this->assertSame(TallyInvoiceMatchState::Matched, $invoice->match_state);
        $this->assertSame($order->id, $invoice->sales_order_id);
        $this->assertNull($invoice->match_detail);
    }

    // ── The command ─────────────────────────────────────────────────────────

    public function test_the_command_is_a_dry_run_unless_told_to_write(): void
    {
        $this->customerFor('Revive Formulations India Pvt Ltd', 'Revive Formulations');
        $this->orderFor(Customer::query()->sole(), '480');

        $this->artisan('tally:import-sales-invoices', ['path' => $this->fixture()])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, TallySalesInvoice::query()->count());

        $this->artisan('tally:import-sales-invoices', ['path' => $this->fixture(), '--write' => true])
            ->assertSuccessful();

        $this->assertSame(3, TallySalesInvoice::query()->count());
    }

    public function test_the_command_fails_loudly_on_a_path_it_cannot_read(): void
    {
        $this->artisan('tally:import-sales-invoices', ['path' => '/no/such/export.xml'])
            ->expectsOutputToContain('Not a readable file')
            ->assertFailed();
    }
}
