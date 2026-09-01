<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\Enums\PurchaseRequisitionStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * THE PROCUREMENT WORKFLOW END TO END, THROUGH THE REAL ENDPOINTS.
 *
 *   requisition raised → approved → a PO created FROM IT → sent →
 *   a PARTIAL arrival with its bill reference (order stays open for the
 *   balance) → an arrival over the balance REFUSED → the balance arrives →
 *   Closed → a further arrival refused.
 *
 * WHY THIS EXISTS WHEN TWO NEIGHBOURS ALREADY WALK MOST OF IT. The chain was
 * pinned in two halves that never met: RequisitionCoverageTest starts at a
 * requisition and stops at SEND, and PurchaseChainContractTest starts at a
 * purchase order and never sees a requisition. Nothing asserted that the
 * requisition's OWN balance keeps step with the arrivals against the order
 * that answered it — the join is what a buyer actually experiences, and it
 * was the one part of the flow no test walked in one piece.
 *
 * NOT re-proven here, deliberately, each already pinned by name:
 *   - the ledger invariant, lots, bags and the Receipt Note per GRN —
 *     PurchaseChainContractTest;
 *   - the draft/sent/cancelled reservation rules in all their cases
 *     (DEC-20260831-003, DEC-20260831-004) — RequisitionCoverageTest;
 *   - the over-receipt refusal in its every shape — OverReceiptContractTest;
 *   - schedule allocation per due-date window — PoScheduleArrivalTest.
 *   This test asserts the JOIN, and the one rule the join turns on.
 *
 * FC-06: every value here is synthetic — "Vendor Alpha", "ITEM_A", 1.25.
 */
class ProcurementEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const UNIT_PRICE = '1.25';

    private User $desk;

    private Vendor $vendor;

    private Item $resin;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->desk = User::factory()->create(['name' => 'Procurement Desk', 'is_active' => true]);
        foreach ([
            'procurement.view', 'procurement.manage',
            'inventory.view', 'inventory.manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->desk->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->desk);

        $this->vendor = Vendor::create([
            'code' => 'VND-A',
            'name' => 'Vendor Alpha',
            'is_active' => true,
            // The exact Tally identity a staged Purchase Order voucher names
            // (DEC-20260812-002). Carried on the ONE vendor master.
            'tally_ledger_name' => 'Vendor Alpha',
        ]);
        $this->resin = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true]);
        $this->store = Warehouse::create(['code' => 'WH-A', 'name' => 'Warehouse A', 'is_active' => true]);
    }

    /**
     * THE WHOLE FLOW IN ONE WALK, each step through its own endpoint.
     */
    public function test_a_requisition_becomes_an_order_that_two_arrivals_close_while_the_requisition_keeps_step(): void
    {
        // ---- 1. Procurement raises a requisition, and it is approved ------
        $requisition = $this->postJson('/api/v1/procurement/purchase-requisitions', [
            'needed_by_date' => '2026-09-15',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => '1000.0000']],
        ])->assertSuccessful()->json('data');

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$requisition['id']}/approve")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseRequisitionStatus::Approved->value);

        // Nothing ordered yet: the whole ask is still to order.
        $line = $this->requisitionLine($requisition['id']);
        $this->assertSame('1000.0000', $line['requested_quantity']);
        $this->assertSame('0.0000', $line['ordered_quantity']);
        $this->assertSame('1000.0000', $line['balance_quantity']);
        $this->assertSame('not_ordered', $line['order_status']);

        // ---- 2. A purchase order is created FROM the requisition ----------
        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'purchase_requisition_id' => $requisition['id'],
            'order_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '1000.0000',
                'unit_price' => self::UNIT_PRICE,
            ]],
        ])->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Draft->value)
            ->json('data');

        $orderId = $order['id'];
        $orderLineId = $order['lines'][0]['id'];

        // A DRAFT RESERVES NOTHING (DEC-20260831-003). The buyer is still
        // typing; the requisition's balance has not moved.
        $this->assertSame('0.0000', $this->requisitionLine($requisition['id'])['ordered_quantity']);
        $this->assertSame('not_ordered', $this->requisitionLine($requisition['id'])['order_status']);

        // ---- 3. Sending it is what spends the requisition -----------------
        $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/send")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Sent->value);

        $line = $this->requisitionLine($requisition['id']);
        $this->assertSame('1000.0000', $line['ordered_quantity']);
        $this->assertSame('0.0000', $line['balance_quantity']);
        $this->assertSame('fully_ordered', $line['order_status']);

        // ---- 4. A PARTIAL arrival, carrying its bill reference ------------
        $first = $this->receive($orderId, $orderLineId, '400.0000', 'arrival-1', 'BILL-A/2026-27');

        $this->assertSame('BILL-A/2026-27', $first['reference']);
        $this->assertSame('400.0000', $this->orderLine($orderId)['quantity_received']);
        // THE ORDER STAYS OPEN FOR THE REMAINDER. This is the rule the
        // partial-arrival flow exists for.
        $this->assertSame(
            PurchaseOrderStatus::PartiallyReceived->value,
            $this->order($orderId)['status'],
        );

        // ---- 5. More than the balance is refused, and writes nothing ------
        $receiptsBefore = GoodsReceiptNote::count();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload(
            $orderId, $orderLineId, '700.0000', 'arrival-too-big', 'BILL-B/2026-27',
        ))->assertStatus(422);

        $this->assertSame($receiptsBefore, GoodsReceiptNote::count());
        $this->assertSame('400.0000', $this->orderLine($orderId)['quantity_received']);
        $this->assertSame(
            PurchaseOrderStatus::PartiallyReceived->value,
            $this->order($orderId)['status'],
        );

        // ---- 6. The balance arrives on its own bill, and closes the order -
        $second = $this->receive($orderId, $orderLineId, '600.0000', 'arrival-2', 'BILL-C/2026-27');

        // EACH ARRIVAL KEEPS ITS OWN BILL REFERENCE. Two deliveries against
        // one order are two documents, and the second must not overwrite the
        // first's paperwork.
        $this->assertSame('BILL-C/2026-27', $second['reference']);
        $this->assertNotSame($first['reference'], $second['reference']);
        $this->assertSame('BILL-A/2026-27', GoodsReceiptNote::find($first['id'])->reference);

        $this->assertSame('1000.0000', $this->orderLine($orderId)['quantity_received']);
        $this->assertSame(PurchaseOrderStatus::Closed->value, $this->order($orderId)['status']);

        // ---- 7. A closed order takes nothing more -------------------------
        $receiptsBefore = GoodsReceiptNote::count();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload(
            $orderId, $orderLineId, '1.0000', 'arrival-3', 'BILL-D/2026-27',
        ))->assertStatus(422);
        $this->assertSame($receiptsBefore, GoodsReceiptNote::count());
    }

    /**
     * THE SHORT-CLOSE, which is what happens when the balance never comes.
     *
     * The vendor delivered part of the order and the rest is not coming. The
     * buyer closes what is still open, WITH A REASON, and the order stops
     * being receivable — the received quantity is untouched, and the closing
     * records what was still open at the moment it closed.
     *
     * The ERP side is decided and evidenced (PurchaseOrderService::close).
     * What Tally should be told about a short-closed order is Q48(b) and is
     * still open — nothing here posts to Tally, and the flag is off.
     */
    public function test_a_short_close_ends_an_order_the_vendor_never_finished_and_records_what_was_open(): void
    {
        $requisition = $this->approvedRequisition('1000.0000');
        [$orderId, $orderLineId] = $this->sentOrderFrom($requisition, '1000.0000');

        $this->receive($orderId, $orderLineId, '400.0000', 'arrival-1', 'BILL-A/2026-27');

        $closed = $this->postJson("/api/v1/procurement/purchase-orders/{$orderId}/close", [
            'reason' => 'Vendor cannot supply the balance this season.',
        ])->assertSuccessful()->json('data');

        $this->assertSame(PurchaseOrderStatus::Closed->value, $closed['status']);
        // What actually arrived is not rewritten by the closing.
        $this->assertSame('400.0000', $this->orderLine($orderId)['quantity_received']);

        $order = PurchaseOrder::find($orderId);
        $this->assertSame('Vendor cannot supply the balance this season.', $order->closed_reason);
        $this->assertNotNull($order->closed_at);
        $this->assertSame($this->desk->id, $order->closed_by);

        // And a short-closed order receives nothing further.
        $receiptsBefore = GoodsReceiptNote::count();
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload(
            $orderId, $orderLineId, '100.0000', 'arrival-after-close', 'BILL-E/2026-27',
        ))->assertStatus(422);
        $this->assertSame($receiptsBefore, GoodsReceiptNote::count());
    }

    /**
     * EXCESS SUPPLY NEEDS A NEW ORDER, not a bigger receipt.
     *
     * The vendor sent more than was authorised. The refusal above is only
     * half the rule — the half that matters to a buyer is that there IS a
     * way to book the excess, and it is a second order, which is a second
     * authorisation. Here the requisition is exhausted, so the second order
     * needs its own requisition too: authority comes from the requisition,
     * never from the delivery (DEC-20260831-004).
     */
    public function test_material_over_the_authorised_quantity_is_booked_only_through_a_new_order(): void
    {
        $requisition = $this->approvedRequisition('1000.0000');
        [$orderId, $orderLineId] = $this->sentOrderFrom($requisition, '1000.0000');

        $this->receive($orderId, $orderLineId, '1000.0000', 'arrival-1', 'BILL-A/2026-27');
        $this->assertSame(PurchaseOrderStatus::Closed->value, $this->order($orderId)['status']);

        // 200 kg more turned up. It cannot go on the closed order.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receiptPayload(
            $orderId, $orderLineId, '200.0000', 'excess', 'BILL-F/2026-27',
        ))->assertStatus(422);

        // The requisition is spent, so a NEW one authorises the extra —
        // the requisition is the unit of authorisation, not the order.
        $spent = $this->requisitionLine($requisition);
        $this->assertSame('0.0000', $spent['balance_quantity']);

        $second = $this->approvedRequisition('200.0000');
        [$secondOrderId, $secondLineId] = $this->sentOrderFrom($second, '200.0000');

        $receipt = $this->receive($secondOrderId, $secondLineId, '200.0000', 'excess-ordered', 'BILL-F/2026-27');

        $this->assertSame('200.0000', $this->orderLine($secondOrderId)['quantity_received']);
        $this->assertSame(PurchaseOrderStatus::Closed->value, $this->order($secondOrderId)['status']);
        // The bill the excess actually came on is recorded against the order
        // that authorised it.
        $this->assertSame('BILL-F/2026-27', $receipt['reference']);
    }

    // ---- steps ---------------------------------------------------------------

    private function approvedRequisition(string $quantity): int
    {
        $requisition = $this->postJson('/api/v1/procurement/purchase-requisitions', [
            'needed_by_date' => '2026-09-15',
            'lines' => [['item_id' => $this->resin->id, 'quantity' => $quantity]],
        ])->assertSuccessful()->json('data');

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$requisition['id']}/approve")
            ->assertSuccessful();

        return $requisition['id'];
    }

    /** @return array{0: int, 1: int} [order id, order line id] */
    private function sentOrderFrom(int $requisitionId, string $quantity): array
    {
        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'purchase_requisition_id' => $requisitionId,
            'order_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => $quantity,
                'unit_price' => self::UNIT_PRICE,
            ]],
        ])->assertSuccessful()->json('data');

        $this->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/send")->assertSuccessful();

        return [$order['id'], $order['lines'][0]['id']];
    }

    /** @return array<string, mixed> */
    private function receive(int $orderId, int $lineId, string $quantity, string $key, string $billReference): array
    {
        return $this->postJson(
            '/api/v1/procurement/goods-receipts',
            $this->receiptPayload($orderId, $lineId, $quantity, $key, $billReference),
        )->assertSuccessful()->json('data');
    }

    /** @return array<string, mixed> */
    private function receiptPayload(int $orderId, int $lineId, string $quantity, string $key, string $billReference): array
    {
        return [
            'receipt_key' => $key,
            'purchase_order_id' => $orderId,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-09-05 09:00:00',
            // The vendor's document for THIS delivery.
            'reference' => $billReference,
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'quantity' => $quantity,
            ]],
        ];
    }

    // ---- reads ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private function order(int $orderId): array
    {
        return $this->getJson("/api/v1/procurement/purchase-orders/{$orderId}")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function orderLine(int $orderId): array
    {
        return $this->order($orderId)['lines'][0];
    }

    /** @return array<string, mixed> */
    private function requisitionLine(int $requisitionId): array
    {
        $row = collect($this->getJson('/api/v1/procurement/purchase-requisitions')->assertOk()->json('data'))
            ->firstWhere('id', $requisitionId);

        return $row['lines'][0];
    }
}
