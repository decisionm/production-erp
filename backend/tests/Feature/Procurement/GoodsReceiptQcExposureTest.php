<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Quality\Models\IncomingInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WHERE AN ARRIVAL LINE STANDS WITH INCOMING QC, on the receipt itself —
 * 28-Aug audit finding 9: the receipt and the inspection lived on two
 * screens with no road between them, so "is this material usable yet?" had
 * no answer at the receiving desk.
 *
 * Each GRN line now carries `qc`: the line's inspection when one exists
 * (the quality service refuses a second, so 0..1) and the physical bag
 * hold counted from its lots (waiting_qc / rejected_qc / total) — null for
 * a line with no bag-tracked lots, because counted packaging has no hold
 * by construction (DEC-20260825-001). Quantities only, never rates:
 * FC-06's gate on unit_cost is untouched beside it.
 */
class GoodsReceiptQcExposureTest extends TestCase
{
    use RefreshDatabase;

    private GoodsReceiptNote $receipt;

    private int $lineId;

    private Item $resin;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resin = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true]);
        $this->store = Warehouse::create(['code' => 'RM', 'name' => 'RM Store', 'is_active' => true]);
        $vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha']);
        $order = PurchaseOrder::create(['vendor_id' => $vendor->id, 'status' => PurchaseOrderStatus::Sent, 'order_date' => '2026-08-01']);
        $poLine = $order->lines()->create(['item_id' => $this->resin->id, 'quantity' => '1000', 'unit_price' => '1', 'quantity_received' => '0']);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/procurement/goods-receipts', [
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-28',
            'lines' => [['purchase_order_line_id' => $poLine->id, 'quantity' => '100', 'unit_cost' => '1']],
        ])->assertSuccessful()->json('data.id');

        $this->receipt = GoodsReceiptNote::query()->findOrFail($id);
        $this->lineId = $this->receipt->lines()->sole()->id;
    }

    public function test_a_line_nobody_has_inspected_says_so_rather_than_nothing(): void
    {
        $line = $this->showLine();

        $this->assertNull($line['qc']['inspection']);
        $this->assertNull($line['qc']['bags'], 'no bag-tracked lots — no hold by construction');
    }

    public function test_an_inspection_rides_the_line_with_its_result_and_quantities(): void
    {
        IncomingInspection::create([
            'goods_receipt_note_line_id' => $this->lineId,
            'item_id' => $this->resin->id,
            'inspected_quantity' => '100.0000',
            'accepted_quantity' => '90.0000',
            'rejected_quantity' => '10.0000',
            'result' => 'partial',
            'inspection_date' => '2026-08-28',
        ]);

        $qc = $this->showLine()['qc'];

        $this->assertSame('partial', $qc['inspection']['result']);
        $this->assertSame('90.0000', $qc['inspection']['accepted_quantity']);
        $this->assertSame('10.0000', $qc['inspection']['rejected_quantity']);
        $this->assertSame('2026-08-28', $qc['inspection']['inspection_date']);
    }

    public function test_the_bag_hold_is_counted_from_the_lots(): void
    {
        $lot = MaterialLot::create([
            'grn_id' => $this->receipt->id,
            'goods_receipt_note_line_id' => $this->lineId,
            'item_id' => $this->resin->id,
            'received_date' => '2026-08-28',
            'bag_count' => 3,
            'bag_weight_kg' => '25.0000',
            'total_received_kg' => '75.0000',
            'warehouse_id' => $this->store->id,
        ]);
        foreach ([['B-1', 'waiting_qc'], ['B-2', 'waiting_qc'], ['B-3', 'rejected_qc']] as [$barcode, $status]) {
            $lot->bags()->create([
                'barcode' => $barcode,
                'original_kg' => '25.0000',
                'remaining_kg' => '25.0000',
                'status' => $status,
                'current_warehouse_id' => $this->store->id,
            ]);
        }

        $bags = $this->showLine()['qc']['bags'];

        $this->assertSame(['waiting_qc' => 2, 'rejected_qc' => 1, 'total' => 3], $bags);
    }

    /** The show endpoint's first (only) line. */
    private function showLine(): array
    {
        return $this->getJson("/api/v1/procurement/goods-receipts/{$this->receipt->id}")
            ->assertOk()
            ->json('data.lines.0');
    }
}
