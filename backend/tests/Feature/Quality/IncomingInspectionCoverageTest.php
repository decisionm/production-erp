<?php

namespace Tests\Feature\Quality;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Quality\Exceptions\InvalidInspectionQuantityException;
use App\Modules\Quality\Services\IncomingInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHAT AN INSPECTION MEANS WHEN IT COVERS ONLY PART OF A LINE.
 *
 * Two defects from the 28-Aug hunt, and they are the same question from
 * opposite sides. Neither is answered by reinterpreting what an inspection
 * does; both are answered by refusing to pretend.
 *
 * PART OF A LINE IS REFUSED. The disposition releases every bag that was not
 * rejected, and it cannot do otherwise: a bag held back would have no way out,
 * because a line that already has an inspection refuses a second one. So
 * inspecting 10 of 20 bags quietly released the other 10 into available stock
 * as though someone had looked at them — a quality escape with nothing on the
 * record to show it. Partial inspection is a reasonable thing to want; it needs
 * re-inspection built alongside it and a rule for the remainder that only the
 * quality desk can give. Until then the refusal names it.
 *
 * A BAGLESS REJECTION IS RECORDED, NOT ACTED ON. A line with no bags has
 * nothing to reject, which is deliberate for a non-traceability item. But the
 * rejected figure was written to the inspection while the material stayed in
 * the store, and the record said nothing about that — anyone reading "50
 * rejected" would reasonably believe 50 had left the balance. The quantity is
 * not this code's to move: on a bag-tracked line the rejected weight is summed
 * from real bags, and here the only source is a typed figure, which this
 * service refuses to move stock on by design. So the fact is written down.
 */
class IncomingInspectionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $quantity): GoodsReceiptNoteLine
    {
        $vendor = Vendor::create(['code' => 'V-QC', 'name' => 'Vendor Alpha']);
        $item = Item::create(['sku' => 'QC-ITEM', 'name' => 'QC Item', 'uom' => 'Kgs']);
        $warehouse = Warehouse::create(['code' => 'WH-QC', 'name' => 'QC Store', 'is_active' => true]);

        $order = PurchaseOrder::create([
            'vendor_id' => $vendor->id,
            'order_date' => '2026-08-28',
            'status' => 'sent',
        ]);

        $grn = GoodsReceiptNote::create([
            'purchase_order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'received_date' => '2026-08-28',
        ]);

        $orderLine = PurchaseOrderLine::create([
            'purchase_order_id' => $order->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '10.0000',
        ]);

        return GoodsReceiptNoteLine::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_line_id' => $orderLine->id,
            'item_id' => $item->id,
            'quantity' => $quantity,
            'unit_cost' => '10.0000',
        ]);
    }

    private function inspect(GoodsReceiptNoteLine $line, string $inspected, string $accepted, string $rejected)
    {
        return app(IncomingInspectionService::class)->create([
            'goods_receipt_note_line_id' => $line->id,
            'inspected_quantity' => $inspected,
            'accepted_quantity' => $accepted,
            'rejected_quantity' => $rejected,
            'inspection_date' => '2026-08-28',
        ], null);
    }

    public function test_inspecting_only_part_of_a_line_is_refused(): void
    {
        $line = $this->line('100.0000');

        $this->expectException(InvalidInspectionQuantityException::class);
        $this->inspect($line, '40.0000', '40.0000', '0.0000');
    }

    /** The refusal says WHY, because the reader has to decide what to do next. */
    public function test_the_refusal_names_both_horns_of_the_problem(): void
    {
        $line = $this->line('100.0000');

        try {
            $this->inspect($line, '40.0000', '40.0000', '0.0000');
            $this->fail('a partial inspection was accepted');
        } catch (InvalidInspectionQuantityException $refusal) {
            $this->assertStringContainsString('whole arrival line', $refusal->getMessage());
            $this->assertStringContainsString('nobody looked at', $refusal->getMessage());
            $this->assertStringContainsString('strand', $refusal->getMessage());
        }
    }

    public function test_inspecting_the_whole_line_is_accepted(): void
    {
        $line = $this->line('100.0000');

        $inspection = $this->inspect($line, '100.0000', '100.0000', '0.0000');

        $this->assertEqualsWithDelta(100, (float) $inspection->inspected_quantity, 0.0001);
    }

    /**
     * A rejection on a line with no bags moves no stock — and now SAYS so on
     * the record, instead of leaving a reader to assume it did.
     */
    public function test_a_bagless_rejection_records_that_no_stock_was_issued(): void
    {
        $line = $this->line('100.0000');

        $inspection = $this->inspect($line, '100.0000', '50.0000', '50.0000');

        $this->assertNotNull($inspection->bag_disposition_note, 'a bagless rejection left no trace of what did not happen');
        $this->assertStringContainsString('no stock was issued', $inspection->bag_disposition_note);
        $this->assertStringContainsString('remains in the store', $inspection->bag_disposition_note);
        $this->assertNull($inspection->rejections_out_reference, 'no stock moved, so there is no Rejections Out reference');
    }

    /** A clean pass on a bagless line needs no such note — nothing failed to happen. */
    public function test_a_bagless_pass_records_no_note(): void
    {
        $line = $this->line('100.0000');

        $inspection = $this->inspect($line, '100.0000', '100.0000', '0.0000');

        $this->assertNull($inspection->bag_disposition_note);
    }
}
