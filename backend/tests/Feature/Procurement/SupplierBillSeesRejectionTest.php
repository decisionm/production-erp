<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Quality\Models\IncomingInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DEC-20260902-015: the rejected quantity and the Rejections Out reference
 * are visible where Accounts matches the supplier's paper.
 *
 * TWO LEGS, not one, because the Supplier Bill page does NOT read the GRN
 * show endpoint for its line picker. `goods-receipts` show/index sits behind
 * `module:procurement`; the bill's picker,
 * `supplier-bills/receipt-line-options`, sits behind `module:finance` on
 * purpose (SupplierBillController@receiptLineOptions) — an Accounts-only
 * login holds finance permissions and NOT procurement permissions, and would
 * 403 on the procurement-gated endpoint (the exact bug class vendorOptions/
 * itemOptions/orderOptions were each already built to close). So:
 *   1. the GRN line resource itself carries the two fields, proven by the
 *      procurement-side actor who built the receipt;
 *   2. the bill's OWN picker carries them too, proven by a finance-ONLY
 *      actor — mirroring SupplierBillTest::actAsAccounts() — because that
 *      is the login this screen is built for (FC-06).
 *
 * The PO + GRN are built through the same endpoints and the same payload
 * shapes ProcurementEndToEndTest uses (its setUp and its receive() /
 * receiptPayload() helpers), not invented here.
 */
class SupplierBillSeesRejectionTest extends TestCase
{
    use RefreshDatabase;

    private const UNIT_PRICE = '1.25';

    public function test_a_receipt_line_answers_its_rejection_on_the_grn_and_on_the_bills_own_picker(): void
    {
        // The bag manifest is not optional: traceability_enabled defaults
        // true, and a weighed material with no lots is refused before it
        // reaches the service (ProcurementEndToEndTest::receiptPayload).
        config(['production.traceability_enabled' => true]);

        $desk = User::factory()->create(['name' => 'Procurement Desk', 'is_active' => true]);
        // DEC-20260902-025: the requester cannot approve their own
        // requisition, so a second user does it (ProcurementEndToEndTest).
        $approver = User::factory()->create(['name' => 'Procurement Approver', 'is_active' => true]);
        foreach (['procurement.view', 'procurement.manage', 'inventory.view', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $desk->givePermissionTo($permission);
            $approver->givePermissionTo($permission);
        }
        Sanctum::actingAs($desk);

        $vendor = Vendor::create([
            'code' => 'VND-A',
            'name' => 'Vendor Alpha',
            'is_active' => true,
            'tally_ledger_name' => 'Vendor Alpha',
        ]);
        $item = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true, 'category' => ItemCategory::RawMaterial]);
        $store = Warehouse::create(['code' => 'WH-A', 'name' => 'Warehouse A', 'is_active' => true]);

        // ---- requisition → approve → order → send (ProcurementEndToEndTest) --
        $requisition = $this->postJson('/api/v1/procurement/purchase-requisitions', [
            'needed_by_date' => '2026-09-15',
            'lines' => [['item_id' => $item->id, 'quantity' => '1000.0000']],
        ])->assertSuccessful()->json('data');

        Sanctum::actingAs($approver);
        $this->postJson("/api/v1/procurement/purchase-requisitions/{$requisition['id']}/approve")->assertSuccessful();
        Sanctum::actingAs($desk);

        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $vendor->id,
            'purchase_requisition_id' => $requisition['id'],
            'order_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => '1000.0000',
                'unit_price' => self::UNIT_PRICE,
            ]],
        ])->assertSuccessful()->json('data');

        $orderId = $order['id'];
        $orderLineId = $order['lines'][0]['id'];

        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")->assertSuccessful();

        // ---- one receipt, in 25 kg bags (ProcurementEndToEndTest::receiptPayload) --
        $receipt = $this->postJson('/api/v1/procurement/goods-receipts', [
            'receipt_key' => 'arrival-1',
            'purchase_order_id' => $orderId,
            'warehouse_id' => $store->id,
            'received_date' => '2026-09-05 09:00:00',
            'reference' => 'BILL-A/2026-27',
            'lines' => [[
                'purchase_order_line_id' => $orderLineId,
                'quantity' => '400.0000',
                'lots' => [[
                    'supplier_lot_no' => 'LOT-arrival-1',
                    'bag_count' => 16,
                    'bag_weight_kg' => '25',
                ]],
            ]],
        ])->assertSuccessful()->json('data');

        $line = GoodsReceiptNoteLine::query()->where('goods_receipt_note_id', $receipt['id'])->firstOrFail();

        IncomingInspection::create([
            'goods_receipt_note_line_id' => $line->id, 'item_id' => $item->id,
            'inspected_quantity' => $line->quantity, 'accepted_quantity' => bcsub($line->quantity, '25', 4), 'rejected_quantity' => '25',
            'result' => 'partial', 'inspection_date' => now()->toDateString(), 'inspected_by' => $desk->id,
            'rejections_out_reference' => 'RJO-GRN1-L1',
        ]);

        // ---- Leg 1: the GRN line resource itself --------------------------
        $this->getJson("/api/v1/procurement/goods-receipts/{$receipt['id']}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.qc.inspection.rejected_quantity', '25.0000')
            ->assertJsonPath('data.lines.0.qc.inspection.rejections_out_reference', 'RJO-GRN1-L1');

        // ---- Leg 2: the bill's OWN picker, as a finance-only Accounts login
        $accounts = User::factory()->create(['name' => 'Accounts', 'is_active' => true]);
        foreach (['finance.view', 'finance.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $accounts->givePermissionTo($permission);
        }
        Sanctum::actingAs($accounts);

        $lines = collect(
            $this->getJson("/api/v1/procurement/supplier-bills/receipt-line-options?purchase_order_id={$orderId}")
                ->assertOk()
                ->json('data'),
        );
        $pickerLine = $lines->firstWhere('id', $line->id);
        $this->assertNotNull($pickerLine, 'the receipt line must appear in the finance-only picker');
        $this->assertSame('25.0000', $pickerLine['qc']['inspection']['rejected_quantity']);
        $this->assertSame('RJO-GRN1-L1', $pickerLine['qc']['inspection']['rejections_out_reference']);
        // Untouched by this change (SupplierBillTest pins the same rule):
        // identity and quantity only, never a rate.
        $this->assertArrayNotHasKey('unit_cost', $pickerLine, 'identity and quantity only');
    }
}
