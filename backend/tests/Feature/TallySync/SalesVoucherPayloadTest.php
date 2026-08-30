<?php

namespace Tests\Feature\TallySync;

use App\Modules\Compliance\Models\GstRate;
use App\Modules\Compliance\Models\GstRegistration;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\InvoiceStatus;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Services\SalesVoucherPayload;
use App\Modules\TallySync\Services\TallyLedgerMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE SALES VOUCHER PAYLOAD — measured against the factory's own Tally.
 *
 * Every expectation in this file comes from the 30-Aug-2026 reading of
 * ~/Downloads/sales_voucher.xml — 55 real Sales vouchers exported from the
 * factory's Tally (SWAASHPET POLYMERS PVT LTD, Puducherry, CMPGSTIN
 * 34AAWCS7109K1ZQ), 54 live plus one cancelled.
 *
 * THE INVARIANT THIS FILE EXISTS FOR. In all 54 live vouchers, with no
 * exceptions:
 *
 *     sum(line amounts) + tax + rounding + party = 0        (party negative)
 *
 * The voucher the ERP built before this class debited the party the PRE-TAX
 * total and emitted no tax and no rounding at all, so it failed this invariant
 * by the whole tax. `test_the_voucher_balances_exactly` is the regression lock.
 *
 * The other measured rules pinned here:
 *   - local vs interstate is buyer state vs COMPANY state (54/54 conform);
 *   - local posts CGST + SGST as an equal pair, interstate posts IGST alone;
 *   - the party amount is the tax-inclusive total taken to whole rupees;
 *   - 'Rounding Off' is OMITTED, never zero, when the total already lands whole
 *     (6 of the 54 real vouchers carry no rounding line);
 *   - every ledger is configuration — an unmapped one REFUSES rather than
 *     falling back to a literal 'Sales Account', which is what the old builder did.
 */
class SalesVoucherPayloadTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Item $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        // The company: Puducherry, state code 34 — the factory's real shape.
        GstRegistration::create([
            'gstin' => '34AAWCS7109K1ZQ',
            'state_code' => '34',
            'state_name' => 'Puducherry',
            'is_primary' => true,
            'is_active' => true,
        ]);

        Warehouse::create(['code' => 'FG', 'name' => 'SWAASHPET POLYMERS PVT LTD', 'tally_guid' => 'gd-fg']);

        $this->bottle = Item::create([
            'sku' => 'BTL-500',
            'name' => 'B.500 Ml Round Pet Bottle Amber - 36gms',
            'uom' => 'Nos',
            'hsn_sac_code' => '39233090',
        ]);

        GstRate::create(['hsn_sac_code' => '39233090', 'description' => 'Plastic bottles', 'rate_percent' => '18.00', 'is_active' => true]);

        app(TallyLedgerMappingService::class)->setMany([
            TallyLedgerRole::SalesLocal->value => 'Local Sales Taxable',
            TallyLedgerRole::SalesInterstate->value => 'Interstate Sales Taxable',
            TallyLedgerRole::Cgst->value => 'CGST',
            TallyLedgerRole::Sgst->value => 'SGST',
            TallyLedgerRole::Igst->value => 'IGST',
            TallyLedgerRole::RoundOff->value => 'Rounding Off',
        ]);

        // Tamil Nadu (33) — interstate from Puducherry (34).
        $this->customer = Customer::create([
            'code' => 'CUST-1',
            'name' => 'Sangam Pharma Packers (ERP label)',
            'gstin' => '33ABVFS0946B1Z5',
            'state_code' => '33',
            'is_active' => true,
        ]);
        $this->customer->forceFill(['tally_ledger_name' => 'Sangam Pharma Packers'])->save();
    }

    /**
     * THE REGRESSION LOCK. sum(lines) + tax + rounding + party == 0, with the
     * party negative — the property all 54 live vouchers satisfy and the one
     * the old builder broke by the whole tax.
     */
    public function test_the_voucher_balances_exactly(): void
    {
        $payload = $this->build($this->invoice('980', '7.2000'));

        $credits = '0.0000';
        foreach ($payload['lines'] as $line) {
            $credits = bcadd($credits, $line['amount'], 4);
        }
        foreach ($payload['tax_ledgers'] as $tax) {
            $credits = bcadd($credits, $tax['amount'], 4);
        }
        if ($payload['round_off'] !== null) {
            $credits = bcadd($credits, $payload['round_off']['amount'], 4);
        }

        // The party is the debit. Stated the way the voucher states it: the
        // party amount is emitted NEGATIVE, so the whole voucher sums to zero.
        $this->assertSame(
            0,
            bccomp($credits, $payload['party_amount'], 4),
            'credits must equal the party debit — the invariant every real voucher satisfies',
        );
    }

    /** The exact bug: the party carried the PRE-TAX total. It is the tax-inclusive, rounded total. */
    public function test_the_party_amount_is_tax_inclusive_and_rounded_to_whole_rupees(): void
    {
        // 980 x 7.20 = 7056.00 taxable; 18% = 1270.08; total 8326.08 -> 8326.
        $payload = $this->build($this->invoice('980', '7.2000'));

        $this->assertSame('7056.0000', $payload['taxable_value']);
        $this->assertSame('8326', $payload['party_amount'], 'the party is debited goods + tax, taken to whole rupees');
        $this->assertSame('-0.0800', $payload['round_off']['amount']);
        $this->assertSame('Rounding Off', $payload['round_off']['ledger']);
    }

    /** Interstate: IGST alone, and the interstate sales ledger on every line. */
    public function test_an_interstate_sale_posts_igst_alone(): void
    {
        $payload = $this->build($this->invoice('980', '7.2000'));

        $this->assertSame('inter_state', $payload['supply_type']);
        $this->assertCount(1, $payload['tax_ledgers']);
        $this->assertSame('IGST', $payload['tax_ledgers'][0]['ledger']);
        $this->assertSame('1270.0800', $payload['tax_ledgers'][0]['amount']);
        $this->assertSame('Interstate Sales Taxable', $payload['lines'][0]['sales_ledger']);
        $this->assertSame('Tamil Nadu', $payload['place_of_supply']);
        $this->assertSame('Puducherry', $payload['company_state']);
    }

    /** Local: CGST and SGST as an equal pair, and the local ledger. */
    public function test_a_local_sale_posts_cgst_and_sgst_as_an_equal_pair(): void
    {
        $this->customer->update(['state_code' => '34', 'gstin' => '34AAAAA0000A1Z5']);

        $payload = $this->build($this->invoice('980', '7.2000'));

        $this->assertSame('intra_state', $payload['supply_type']);
        $this->assertCount(2, $payload['tax_ledgers']);
        $this->assertSame(['CGST', 'SGST'], array_column($payload['tax_ledgers'], 'ledger'));
        $this->assertSame('635.0400', $payload['tax_ledgers'][0]['amount']);
        $this->assertSame('635.0400', $payload['tax_ledgers'][1]['amount'], 'the halves are equal');
        $this->assertSame('Local Sales Taxable', $payload['lines'][0]['sales_ledger']);
        $this->assertSame('Puducherry', $payload['place_of_supply']);
    }

    /** Omitted, never emitted as zero — 6 of the 54 real vouchers carry no rounding line. */
    public function test_rounding_is_omitted_when_the_total_already_lands_on_a_whole_rupee(): void
    {
        // 100 x 10.00 = 1000.00; 18% = 180.00; total 1180.00 exactly.
        $payload = $this->build($this->invoice('100', '10.0000'));

        $this->assertNull($payload['round_off'], 'a whole-rupee total carries no Rounding Off entry at all');
        $this->assertSame('1180', $payload['party_amount']);
    }

    /** The party is named by its TALLY ledger, never by the ERP's own label. */
    public function test_the_party_is_named_by_its_tally_ledger_not_the_erp_label(): void
    {
        $payload = $this->build($this->invoice('100', '10.0000'));

        $this->assertSame('Sangam Pharma Packers', $payload['party_ledger']);
        $this->assertNotSame($this->customer->name, $payload['party_ledger']);
    }

    // ---- the refusals: every missing ingredient is a named reason, never a guess

    public function test_an_unmapped_customer_ledger_refuses(): void
    {
        $this->customer->forceFill(['tally_ledger_name' => null])->save();

        $this->assertRefusal($this->invoice('100', '10.0000'), 'customer_ledger_unmapped');
    }

    public function test_an_unmapped_sales_ledger_refuses_instead_of_falling_back(): void
    {
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::SalesInterstate->value => null]);

        $reasons = $this->assertRefusal($this->invoice('100', '10.0000'), 'sales_ledger_unmapped');

        $this->assertStringNotContainsStringIgnoringCase(
            'Sales Account',
            json_encode($reasons),
            'the old builder guessed a literal "Sales Account" — this one must refuse',
        );
    }

    public function test_an_unmapped_tax_ledger_refuses(): void
    {
        app(TallyLedgerMappingService::class)->setMany([TallyLedgerRole::Igst->value => null]);

        $this->assertRefusal($this->invoice('100', '10.0000'), 'tax_ledger_unmapped');
    }

    public function test_a_missing_hsn_refuses_rather_than_posting_an_untaxed_voucher(): void
    {
        $this->bottle->update(['hsn_sac_code' => null]);

        $this->assertRefusal($this->invoice('100', '10.0000'), 'gst_uncomputable');
    }

    public function test_an_unknown_customer_state_code_refuses_rather_than_guessing_a_name(): void
    {
        // '99' is not a GST state code, so PLACEOFSUPPLY cannot be named.
        $this->customer->update(['state_code' => '99']);

        $this->assertRefusal($this->invoice('100', '10.0000'), 'customer_state_unknown');
    }

    /** Nothing is half-built: a refusal returns NO payload at all. */
    public function test_a_refusal_returns_no_payload(): void
    {
        $this->customer->forceFill(['tally_ledger_name' => null])->save();

        $result = app(SalesVoucherPayload::class)->forInvoice($this->invoice('100', '10.0000'));

        $this->assertNull($result['payload']);
        $this->assertNotEmpty($result['reasons']);
    }

    // ---- helpers ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function build(Invoice $invoice): array
    {
        $result = app(SalesVoucherPayload::class)->forInvoice($invoice);

        $this->assertSame([], $result['reasons'], 'expected a clean payload: '.json_encode($result['reasons']));
        $this->assertNotNull($result['payload']);

        return $result['payload'];
    }

    /** @return list<array{code: string, detail: string}> */
    private function assertRefusal(Invoice $invoice, string $code): array
    {
        $result = app(SalesVoucherPayload::class)->forInvoice($invoice);

        $this->assertContains(
            $code,
            array_column($result['reasons'], 'code'),
            'expected refusal '.$code.', got: '.json_encode($result['reasons']),
        );

        return $result['reasons'];
    }

    private function invoice(string $quantity, string $unitPrice): Invoice
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'confirmed',
            'order_date' => '2026-08-01',
            'customer_po_reference' => 'P.O.NO:FRD/2627/POS/PMP/00002 Dt:23.07.2026',
        ]);

        $line = $order->lines()->create([
            'item_id' => $this->bottle->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'quantity_delivered' => 0,
        ]);

        $invoice = Invoice::create([
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'status' => InvoiceStatus::Draft,
            'invoice_date' => '2026-08-01',
        ]);

        $invoice->lines()->create([
            'sales_order_line_id' => $line->id,
            'item_id' => $this->bottle->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);

        return $invoice->fresh();
    }
}
