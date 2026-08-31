<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\PurchaseRequisitionService;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Modules\Quality\Models\IncomingInspection;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SUPPLIER BILLS — the vendor's invoice recorded in the ERP (28-Aug audit
 * finding 10). The contracts, in the order they protect money:
 *
 *   FC-06 — the whole surface is finance-gated. A procurement login
 *   without finance access reads NOTHING here, because every figure on a
 *   bill is a purchase rate.
 *
 *   THE PAPER'S OWN ARITHMETIC — subtotal = Σ line amounts and total =
 *   subtotal + CGST + SGST + IGST + rounding, to the paisa, refused with
 *   the gap named. Taxes are TYPED from the paper, never computed
 *   (DEC-20260812-003: no rate is ever seeded; Q39 open).
 *
 *   NO DOUBLE ENTRY — the same vendor's invoice number twice is the
 *   classic double-payment path; unique per vendor.
 *
 *   MATCHING IS HONEST — a line matched to a GRN line must name the same
 *   item and (when the bill names a PO) the same order; matching is never
 *   FORCED, because purchases without POs are Q64's open territory.
 *
 *   LIFECYCLE — draft (editable) → recorded (stamped who/when, read-only)
 *   or cancelled (reason kept). Nothing is deleted; nothing posts to
 *   Tally (no route exists — Q39/Q41/Q28).
 */
class SupplierBillTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Item $resin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha']);
        $this->resin = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true]);
    }

    public function test_a_procurement_login_without_finance_access_reads_nothing_here(): void
    {
        $this->actAs(['procurement.view', 'procurement.manage']);

        $this->getJson('/api/v1/procurement/supplier-bills')->assertForbidden();
        $this->postJson('/api/v1/procurement/supplier-bills', [])->assertForbidden();
    }

    public function test_a_bill_that_adds_up_is_recorded_with_its_lines(): void
    {
        $this->actAsAccounts();

        $data = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())
            ->assertSuccessful()
            ->json('data');

        $this->assertSame('draft', $data['status']);
        $this->assertSame('INV/2026/077', $data['bill_number']);
        $this->assertSame('1180.0000', $data['total']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame("BILL-{$data['id']}", $data['document_number']);
    }

    public function test_a_subtotal_the_lines_do_not_sum_to_is_refused_with_the_gap_named(): void
    {
        $this->actAsAccounts();

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['subtotal' => '999.0000']))
            ->assertStatus(422)
            ->assertJsonPath('errors.subtotal.0', fn (string $message) => str_contains($message, '1000.0000'));
    }

    public function test_a_total_that_does_not_add_up_is_refused(): void
    {
        $this->actAsAccounts();

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['total' => '1200.0000']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['total']);
    }

    public function test_the_same_vendors_invoice_number_twice_is_refused(): void
    {
        $this->actAsAccounts();

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->assertSuccessful();

        // Refused in words (422 from the request rule), and one bill stands.
        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bill_number']);
        $this->assertSame(1, SupplierBill::query()->count());

        // A DIFFERENT vendor with the same invoice number is ordinary.
        $other = Vendor::create(['code' => 'VND-B', 'name' => 'Vendor Beta']);
        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['vendor_id' => $other->id]))
            ->assertSuccessful();
    }

    public function test_a_concurrent_duplicate_that_slips_past_validation_is_still_a_422_not_a_500(): void
    {
        $this->actAsAccounts();
        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->assertSuccessful();

        // The race: both requests pass the unique RULE before either row
        // exists. Reproduced by calling the service directly, past the rule —
        // the schema unique throws and the service must translate it.
        try {
            app(SupplierBillService::class)->create([
                'vendor_id' => $this->vendor->id,
                'bill_number' => 'INV/2026/077',
                'bill_date' => '2026-08-28',
                'subtotal' => '1000.0000',
                'igst' => '180.0000',
                'total' => '1180.0000',
                'lines' => [[
                    'item_id' => $this->resin->id,
                    'quantity' => '100.0000',
                    'rate' => '10.0000',
                    'amount' => '1000.0000',
                ]],
            ], null);
            $this->fail('expected the duplicate to be refused');
        } catch (ValidationException $refusal) {
            $this->assertArrayHasKey('bill_number', $refusal->errors());
        }

        $this->assertSame(1, SupplierBill::query()->count());
    }

    public function test_another_vendors_receipt_line_cannot_be_matched_even_on_a_bill_with_no_po(): void
    {
        $this->actAsAccounts();
        $grnLineId = $this->receiveLine(); // vendor A's arrival
        $other = Vendor::create(['code' => 'VND-B', 'name' => 'Vendor Beta']);

        // Vendor B's bill, NO purchase order named — the gap Codex found:
        // the PO consistency check only ran when the bill named one.
        $payload = $this->payload(['vendor_id' => $other->id]);
        $payload['lines'][0]['goods_receipt_note_line_id'] = $grnLineId;

        $this->postJson('/api/v1/procurement/supplier-bills', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.goods_receipt_note_line_id']);
    }

    public function test_rounding_in_exponent_notation_is_a_422_not_a_500(): void
    {
        $this->actAsAccounts();

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['rounding' => '1e-1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rounding']);
    }

    public function test_precision_beyond_the_columns_four_decimals_is_refused(): void
    {
        $this->actAsAccounts();

        // 1.00009 would pass bc-math at scale 4 and then round to 1.0001 in
        // the column — a stored bill that no longer adds up.
        $payload = $this->payload();
        $payload['lines'][0]['amount'] = '1000.00009';

        $this->postJson('/api/v1/procurement/supplier-bills', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.amount']);
    }

    public function test_a_soft_deleted_vendor_or_item_cannot_be_billed(): void
    {
        $this->actAsAccounts();
        $this->vendor->delete(); // soft delete — the row remains

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);

        $this->vendor->restore();
        $this->resin->delete();
        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.item_id']);
    }

    public function test_surrounding_whitespace_cannot_sidestep_the_duplicate_guard(): void
    {
        $this->actAsAccounts();
        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->assertSuccessful();

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['bill_number' => '  INV/2026/077  ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bill_number']);
    }

    public function test_a_figure_beyond_the_columns_ten_integer_digits_is_a_422_not_a_500(): void
    {
        $this->actAsAccounts();

        // decimal(14,4) holds at most 9999999999.9999; the old bound let one
        // more digit through to MySQL strict mode as an out-of-range 500.
        $payload = $this->payload(['subtotal' => '10000000000', 'total' => '10000000180']);
        $payload['lines'][0]['amount'] = '10000000000';

        $this->postJson('/api/v1/procurement/supplier-bills', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subtotal']);
    }

    public function test_a_purchase_ledger_name_must_exist_in_the_pulled_ledger_master(): void
    {
        $this->actAsAccounts();

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['purchase_ledger_name' => 'Imagined Ledger']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_ledger_name']);

        Ledger::create(['tally_guid' => 'g-lp', 'name' => 'Local Purchase Taxable @ 18%', 'tally_group_name' => 'Purchase Accounts']);
        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['purchase_ledger_name' => 'Local Purchase Taxable @ 18%']))
            ->assertSuccessful();
    }

    public function test_the_item_picker_is_served_inside_the_finance_gate(): void
    {
        // Accounts holds NO inventory permission — /inventory/items answers
        // 403; this endpoint must not.
        $this->actAsAccounts();
        Item::create(['sku' => 'ITEM_C', 'name' => 'Another Item', 'uom' => 'Nos', 'is_active' => true]);
        Item::create(['sku' => 'ITEM_D', 'name' => 'Retired Item', 'uom' => 'Nos', 'is_active' => false]);

        $names = collect($this->getJson('/api/v1/procurement/supplier-bills/item-options')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertContains('Another Item', $names);
        $this->assertNotContains('Retired Item', $names, 'inactive items are not offered');

        $searched = collect($this->getJson('/api/v1/procurement/supplier-bills/item-options?q=ITEM_C')->assertOk()->json('data'))
            ->pluck('sku');
        $this->assertSame(['ITEM_C'], $searched->all());
    }

    public function test_every_reference_picker_is_served_inside_the_finance_gate(): void
    {
        // Finance-only — the login shape this screen exists for.
        $grnLineId = $this->receiveLine();
        $this->actAsAccounts();

        $vendors = collect($this->getJson('/api/v1/procurement/supplier-bills/vendor-options')->assertOk()->json('data'));
        $this->assertContains('Vendor Alpha', $vendors->pluck('name'));

        $orders = collect($this->getJson('/api/v1/procurement/supplier-bills/order-options?vendor_id='.$this->vendor->id)->assertOk()->json('data'));
        $this->assertNotEmpty($orders);

        $lines = collect($this->getJson('/api/v1/procurement/supplier-bills/receipt-line-options?purchase_order_id='.$orders[0]['id'])->assertOk()->json('data'));
        $this->assertSame($grnLineId, $lines[0]['id']);
        $this->assertSame('100.0000', $lines[0]['quantity']);
        $this->assertArrayNotHasKey('unit_cost', $lines[0], 'identity and quantity only');
    }

    public function test_approve_and_reject_racing_on_one_requisition_cannot_both_stamp(): void
    {
        // The requisition sibling of the bill locks (Codex on 073a8c2):
        // the second decision must be refused as the status transition it
        // is, not stamped beside the first.
        $this->actAsAccounts();
        $requisition = PurchaseRequisition::create([
            'status' => PurchaseRequisitionStatus::Draft,
        ]);
        $service = app(PurchaseRequisitionService::class);
        $service->approve($requisition, null);

        try {
            $service->reject(PurchaseRequisition::findOrFail($requisition->id), null);
            $this->fail('expected the second decision to be refused');
        } catch (InvalidStatusTransitionException) {
            // refused — and the trail carries exactly one decision:
            $fresh = $requisition->fresh();
            $this->assertSame('approved', $fresh->status->value);
            $this->assertNull($fresh->rejected_at);
        }
    }

    public function test_a_draft_update_that_collides_on_the_unique_index_is_a_422(): void
    {
        $this->actAsAccounts();
        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->assertSuccessful();
        $second = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['bill_number' => 'INV/2026/078']))->json('data.id');

        // Past the request rule, straight at the service — the race window.
        try {
            app(SupplierBillService::class)->update(SupplierBill::findOrFail($second), [
                'vendor_id' => $this->vendor->id,
                'bill_number' => 'INV/2026/077',
                'bill_date' => '2026-08-28',
                'subtotal' => '1000.0000',
                'igst' => '180.0000',
                'total' => '1180.0000',
                'lines' => [[
                    'item_id' => $this->resin->id,
                    'quantity' => '100.0000',
                    'rate' => '10.0000',
                    'amount' => '1000.0000',
                ]],
            ]);
            $this->fail('expected the duplicate to be refused');
        } catch (ValidationException $refusal) {
            $this->assertArrayHasKey('bill_number', $refusal->errors());
        }
    }

    public function test_a_grn_line_for_another_item_cannot_be_matched(): void
    {
        $this->actAsAccounts();
        $other = Item::create(['sku' => 'ITEM_B', 'name' => 'ITEM_B', 'uom' => 'Nos', 'is_active' => true]);
        $grnLineId = $this->receiveLine();

        $payload = $this->payload();
        $payload['lines'][0]['goods_receipt_note_line_id'] = $grnLineId;
        $payload['lines'][0]['item_id'] = $other->id;

        $this->postJson('/api/v1/procurement/supplier-bills', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.goods_receipt_note_line_id']);
    }

    public function test_a_matched_line_carries_the_received_quantity_for_the_variance(): void
    {
        $this->actAsAccounts();
        $grnLineId = $this->receiveLine();

        $payload = $this->payload();
        $payload['lines'][0]['goods_receipt_note_line_id'] = $grnLineId;

        $data = $this->postJson('/api/v1/procurement/supplier-bills', $payload)->assertSuccessful()->json('data');

        $this->assertSame('100.0000', $data['lines'][0]['received_quantity']);
    }

    public function test_recording_stamps_who_and_when_and_freezes_the_bill(): void
    {
        $this->actAsAccounts('The Accountant');
        $id = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->json('data.id');

        $data = $this->postJson("/api/v1/procurement/supplier-bills/{$id}/record")->assertOk()->json('data');
        $this->assertSame('recorded', $data['status']);
        $this->assertSame('The Accountant', $data['recorded_by']);
        $this->assertNotNull($data['recorded_at']);

        // Frozen: editing a recorded bill is refused.
        $this->putJson("/api/v1/procurement/supplier-bills/{$id}", $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_cancelling_keeps_the_row_and_the_reason(): void
    {
        $this->actAsAccounts();
        $id = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->json('data.id');

        $data = $this->postJson("/api/v1/procurement/supplier-bills/{$id}/cancel", ['reason' => 'Entered against the wrong vendor'])
            ->assertOk()
            ->json('data');

        $this->assertSame('cancelled', $data['status']);
        $this->assertSame('Entered against the wrong vendor', $data['cancelled_reason']);
        $this->assertSame(1, SupplierBill::query()->count(), 'cancelled, never deleted');
    }

    public function test_the_scan_of_the_paper_attaches_and_downloads(): void
    {
        Storage::fake('local');
        $this->actAsAccounts();
        $id = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->json('data.id');

        $data = $this->post("/api/v1/procurement/supplier-bills/{$id}/attachment", [
            'file' => UploadedFile::fake()->create('bill-077.pdf', 100, 'application/pdf'),
        ])->assertSuccessful()->json('data');

        $this->assertTrue($data['has_attachment']);
        $this->assertSame('bill-077.pdf', $data['attachment_name']);

        $this->get("/api/v1/procurement/supplier-bills/{$id}/attachment")->assertOk();
    }

    public function test_the_ledger_picker_offers_pulled_names_and_derives_nothing(): void
    {
        $this->actAsAccounts();
        Ledger::create(['tally_guid' => 'g-1', 'name' => 'Local Purchase Taxable @ 18%', 'tally_group_name' => 'Purchase Accounts']);
        Ledger::create(['tally_guid' => 'g-2', 'name' => 'Interstate Purchase Taxable', 'tally_group_name' => 'Purchase Accounts']);
        Ledger::create(['tally_guid' => 'g-3', 'name' => 'Salaries', 'tally_group_name' => 'Indirect Expenses']);

        $names = collect($this->getJson('/api/v1/procurement/supplier-bills/ledger-options')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertContains('Local Purchase Taxable @ 18%', $names);
        $this->assertNotContains('Salaries', $names, 'with no q, only the purchase-group candidates');

        $searched = collect($this->getJson('/api/v1/procurement/supplier-bills/ledger-options?q=Salar')->assertOk()->json('data'))
            ->pluck('name');
        $this->assertContains('Salaries', $searched, 'a search finds any ledger — nothing is unfindable');
    }

    public function test_the_status_filter_and_q_narrow_server_side(): void
    {
        $this->actAsAccounts();
        $draft = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())->json('data.id');
        $recorded = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['bill_number' => 'INV/2026/078']))->json('data.id');
        $this->postJson("/api/v1/procurement/supplier-bills/{$recorded}/record")->assertOk();

        $ids = collect($this->getJson('/api/v1/procurement/supplier-bills?status=draft')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$draft], $ids->all());

        $byNumber = collect($this->getJson('/api/v1/procurement/supplier-bills?q=INV%2F2026%2F078')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$recorded], $byNumber->all());
    }

    // -- capture as a draft, commit only up to what Quality passed (-011) --

    public function test_accounts_may_draft_the_vendors_paper_the_moment_it_arrives_uninspected(): void
    {
        // The ordinary case: the invoice arrives with the lorry, before
        // Quality has looked at anything. The ERP does not stand between
        // Accounts and the paper on the desk.
        $grnLineId = $this->receiveLine();           // 100 arrived, uninspected

        $this->actAsAccounts();
        $bill = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload([
            'lines' => [$this->matchedLine($grnLineId, '100.0000')],
        ]))->assertSuccessful()->json('data');

        $this->assertSame('draft', $bill['status']);
    }

    public function test_recording_is_refused_while_the_receipt_is_uninspected_and_the_bill_stays_a_draft(): void
    {
        $grnLineId = $this->receiveLine();
        $this->actAsAccounts();
        $bill = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload([
            'lines' => [$this->matchedLine($grnLineId, '100.0000')],
        ]))->assertSuccessful()->json('data');

        // Uninspected contributes ZERO to the recordable amount.
        $response = $this->postJson("/api/v1/procurement/supplier-bills/{$bill['id']}/record");
        $response->assertStatus(422);
        $this->assertStringContainsString('not inspected', $this->validationMessage($response, 'lines.0.quantity'));

        $this->assertSame('draft', SupplierBill::query()->findOrFail($bill['id'])->status->value);
    }

    public function test_rejected_quantity_contributes_zero_so_only_the_accepted_amount_records(): void
    {
        $grnLineId = $this->receiveLine();           // 100 arrived
        $this->inspect($grnLineId, accepted: '90.0000');   // 10 rejected

        $this->actAsAccounts();
        $over = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload([
            'lines' => [$this->matchedLine($grnLineId, '100.0000')],
        ]))->assertSuccessful()->json('data');

        $response = $this->postJson("/api/v1/procurement/supplier-bills/{$over['id']}/record");
        $response->assertStatus(422);
        $message = $this->validationMessage($response, 'lines.0.quantity');
        $this->assertStringContainsString('90.0000', $message);
        $this->assertStringContainsString('100', $message);
        $this->assertSame('draft', SupplierBill::query()->findOrFail($over['id'])->status->value);
    }

    public function test_a_bill_within_the_accepted_quantity_records(): void
    {
        $grnLineId = $this->receiveLine();
        $this->inspect($grnLineId, accepted: '90.0000');

        $this->actAsAccounts();
        $bill = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload([
            'subtotal' => '900.0000', 'igst' => '162.0000', 'total' => '1062.0000',
            'lines' => [$this->matchedLine($grnLineId, '90.0000', '900.0000')],
        ]))->assertSuccessful()->json('data');

        $this->postJson("/api/v1/procurement/supplier-bills/{$bill['id']}/record")->assertSuccessful();
        $this->assertSame('recorded', SupplierBill::query()->findOrFail($bill['id'])->status->value);
    }

    public function test_acceptance_is_cumulative_across_re_inspections(): void
    {
        $grnLineId = $this->receiveLine();
        // Quality passes 60, then a re-inspection after rework passes 30 more.
        $this->inspect($grnLineId, accepted: '60.0000');
        $this->inspect($grnLineId, accepted: '30.0000');

        $this->actAsAccounts();
        $bill = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload([
            'subtotal' => '900.0000', 'igst' => '162.0000', 'total' => '1062.0000',
            'lines' => [$this->matchedLine($grnLineId, '90.0000', '900.0000')],
        ]))->assertSuccessful()->json('data');

        // 60 + 30 = 90 recordable; a single inspection reading would refuse this.
        $this->postJson("/api/v1/procurement/supplier-bills/{$bill['id']}/record")->assertSuccessful();
    }

    public function test_the_same_receipt_line_cannot_be_billed_twice(): void
    {
        $grnLineId = $this->receiveLine();

        $this->actAsAccounts();
        $line = [$this->matchedLine($grnLineId, '100.0000')];

        $this->postJson('/api/v1/procurement/supplier-bills', $this->payload(['lines' => $line]))->assertSuccessful();

        // A SECOND bill against the same arrival — paying the vendor twice
        // for one delivery is exactly what the matching column exists to
        // stop, and it is caught at the DRAFT, while it is cheap to fix.
        $second = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload([
            'bill_number' => 'INV/2026/078',
            'lines' => $line,
        ]));
        $second->assertStatus(422);
        $this->assertStringContainsString(
            'already been billed',
            $this->validationMessage($second, 'lines.0.goods_receipt_note_line_id'),
        );

        $this->assertSame(1, SupplierBill::query()->count());
    }

    public function test_an_unmatched_bill_records_freely_because_there_is_no_acceptance_to_measure(): void
    {
        // A bill for something never on a purchase order stays recordable —
        // Q64 territory, deliberately untouched by the cap.
        $this->actAsAccounts();
        $bill = $this->postJson('/api/v1/procurement/supplier-bills', $this->payload())
            ->assertSuccessful()->json('data');

        $this->postJson("/api/v1/procurement/supplier-bills/{$bill['id']}/record")->assertSuccessful();
    }

    // ---- fixtures -----------------------------------------------------------

    /** A bill that adds up: 100 kg × 10 = 1000, +18% IGST 180, total 1180. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => $this->vendor->id,
            'bill_number' => 'INV/2026/077',
            'bill_date' => '2026-08-28',
            'subtotal' => '1000.0000',
            'igst' => '180.0000',
            'rounding' => '0',
            'total' => '1180.0000',
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '100.0000',
                'rate' => '10.0000',
                'amount' => '1000.0000',
            ]],
        ], $overrides);
    }

    /** One received GRN line for the resin, quantity 100. */
    private function receiveLine(): int
    {
        $store = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true]);
        $order = PurchaseOrder::create(['vendor_id' => $this->vendor->id, 'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-08-01']);
        $poLine = $order->lines()->create(['item_id' => $this->resin->id, 'quantity' => '1000', 'unit_price' => '10', 'quantity_received' => '0']);

        $this->actAs(['procurement.view', 'procurement.manage', 'finance.view', 'finance.manage']);
        $grnId = $this->postJson('/api/v1/procurement/goods-receipts', [
            'purchase_order_id' => $order->id,
            'warehouse_id' => $store->id,
            'received_date' => '2026-08-27',
            'lines' => [['purchase_order_line_id' => $poLine->id, 'quantity' => '100', 'unit_cost' => '10']],
        ])->assertSuccessful()->json('data.id');

        return GoodsReceiptNote::query()->findOrFail($grnId)->lines()->sole()->id;
    }

    /**
     * One validation message off the LAST response, by its literal key.
     * Laravel keys nested-field errors as the string "lines.0.quantity", so
     * assertJsonPath would split it into a path that does not exist and hand
     * the assertion a null.
     */
    private function validationMessage(TestResponse $response, string $key): string
    {
        $errors = $response->json('errors') ?? [];
        $this->assertArrayHasKey($key, $errors, "no validation error on {$key}; got: ".implode(', ', array_keys($errors)));

        return (string) ($errors[$key][0] ?? '');
    }

    /** One bill line matched to a receipt line. */
    private function matchedLine(int $grnLineId, string $quantity, string $amount = '1000.0000'): array
    {
        return [
            'item_id' => $this->resin->id,
            'goods_receipt_note_line_id' => $grnLineId,
            'quantity' => $quantity,
            'rate' => '10.0000',
            'amount' => $amount,
        ];
    }

    /** Quality's verdict on a receipt line — accepted, and the rest rejected. */
    private function inspect(int $grnLineId, string $accepted): void
    {
        $grnLine = GoodsReceiptNoteLine::query()->findOrFail($grnLineId);

        IncomingInspection::create([
            'goods_receipt_note_line_id' => $grnLine->id,
            'item_id' => $grnLine->item_id,
            'inspected_quantity' => $accepted,
            'accepted_quantity' => $accepted,
            'rejected_quantity' => '0',
            'result' => 'pass',
            'inspection_date' => '2026-08-28',
        ]);
    }

    private function actAs(array $permissions, string $name = 'Someone'): void
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    private function actAsAccounts(string $name = 'Accounts'): void
    {
        $this->actAs(['finance.view', 'finance.manage'], $name);
    }
}
