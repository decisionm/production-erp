<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
