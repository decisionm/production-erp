<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\GrnScheduleAllocation;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\PurchaseOrderSchedule;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * OVER-RECEIPT: a goods receipt may never book more of a purchase order
 * LINE than that line still has open — ordered minus already received, at
 * 4 dp — and when it tries, the whole receipt is refused as one 422 that
 * names the line, the remaining and the requested quantity, and the
 * database is exactly as it was: no receipt, no line, no stock movement, no
 * balance, no lot, no bag, no Receipt Note, no change to quantity_received
 * or to the order's status.
 *
 * Through the real endpoint → GoodsReceiptService::create →
 * OverReceiptException::forLine, with the real TallySync listener live so
 * "no Receipt Note" is counted, not assumed.
 *
 * What is pinned here and nowhere else: the refusal on a first receipt; the
 * refusal after a partial naming the REMAINING (not the ordered) quantity,
 * to the fourth decimal; the check being per LINE, not per order total; a
 * multi-line receipt where one line over-receives rolling back the lines
 * that were fine; that a delivery may over-cover the PLAN (schedules) while
 * staying within the line; and the precedence of the status refusal over
 * the quantity refusal once the order is Closed.
 *
 * NOT re-proven here: a schedule window over-allocated by an edited
 * allocation is refused (PoScheduleArrivalTest); the same receipt_key
 * replayed, or reused for different data (AtomicGoodsReceiptTraceabilityTest,
 * GoodsReceiptIdempotencyContractTest).
 *
 * FC-06: synthetic values only ("Vendor Alpha", "ITEM_A"/"ITEM_B", 1.25).
 */
class OverReceiptContractTest extends TestCase
{
    use RefreshDatabase;

    private const UNIT_PRICE = '1.25';

    private Vendor $vendor;

    private Item $itemA;

    private Item $itemB;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha', 'tally_ledger_name' => 'Vendor Alpha']);
        $this->itemA = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'guid-item-a']);
        $this->itemB = Item::create(['sku' => 'ITEM_B', 'name' => 'ITEM_B', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'guid-item-b']);
        $this->store = Warehouse::create(['code' => 'WH-A', 'name' => 'Warehouse A', 'is_active' => true, 'tally_guid' => 'guid-wh-a']);

        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage', 'inventory.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }

    // ---- fixtures through the endpoints -------------------------------------------

    /**
     * A Sent order with the given lines, through store + send.
     *
     * @param  array<int, array{item_id: int, quantity: string, schedules?: array<int, array{due_date: string, quantity: string}>}>  $lines
     * @return array{0: PurchaseOrder, 1: array<int, int>} [order, line ids in order]
     */
    private function sentOrder(array $lines): array
    {
        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-01',
            'lines' => array_map(fn (array $line) => [...$line, 'unit_price' => self::UNIT_PRICE], $lines),
        ])->assertSuccessful()->json('data');

        $this->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/send")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Sent->value);

        return [PurchaseOrder::query()->findOrFail($order['id']), array_column($order['lines'], 'id')];
    }

    /**
     * @param  array<int, array{purchase_order_line_id: int, quantity: string}>  $lines
     * @return array<string, mixed>
     */
    private function receipt(PurchaseOrder $order, array $lines, string $key): array
    {
        return [
            'receipt_key' => $key,
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'received_date' => '2026-08-02',
            'lines' => array_map(fn (array $line) => [
                ...$line,
                // One 25 kg-bag lot per line, so the refusal has bags and lots
                // to roll back as well as stock — the fuller the write, the
                // stronger the "nothing written" proof.
                'lots' => [[
                    'supplier_lot_no' => 'LOT-'.$key.'-'.$line['purchase_order_line_id'],
                    'bag_count' => 1,
                    'bag_weight_kg' => $line['quantity'],
                ]],
            ], $lines),
        ];
    }

    /** @return array<string, int|string> */
    private function snapshot(): array
    {
        return [
            'grns' => GoodsReceiptNote::query()->count(),
            'grn_lines' => GoodsReceiptNoteLine::query()->count(),
            'allocations' => GrnScheduleAllocation::query()->count(),
            'movements' => StockMovement::query()->count(),
            'balances' => StockBalance::query()->orderBy('id')->get()->map(fn ($b) => "{$b->item_id}@{$b->warehouse_id}={$b->quantity}")->implode(';'),
            'lots' => MaterialLot::query()->count(),
            'bags' => MaterialBag::query()->count(),
            'tally_entries' => TallySyncEntry::query()->count(),
            'tally_events' => TallySyncEvent::query()->count(),
            'statuses' => PurchaseOrder::query()->orderBy('id')->pluck('status')->map->value->implode(','),
            'received' => PurchaseOrderLine::query()->orderBy('id')->get()->map(fn ($l) => "{$l->id}={$l->quantity_received}")->implode(';'),
            'schedules_received' => PurchaseOrderSchedule::query()->orderBy('id')->get()->map(fn ($s) => "{$s->id}={$s->quantity_received}")->implode(';'),
        ];
    }

    private function assertRefusedForLine(array $response, int $lineId, string $remaining, string $requested): void
    {
        $this->assertArrayHasKey('message', $response);
        $this->assertStringContainsString("purchase order line #{$lineId}", $response['message']);
        $this->assertStringContainsString("remaining {$remaining}", $response['message']);
        $this->assertStringContainsString("requested {$requested}", $response['message']);
        // The refusal carries quantities, never the rate (FC-06).
        $this->assertStringNotContainsString(self::UNIT_PRICE, $response['message']);
        $this->assertArrayNotHasKey('errors', $response, 'A domain refusal is a plain 422 body, not a validation bag');
    }

    // ---- first receipt over the line ---------------------------------------------

    public function test_a_first_receipt_over_the_ordered_quantity_is_refused_by_line_and_writes_nothing(): void
    {
        [$order, [$lineId]] = $this->sentOrder([['item_id' => $this->itemA->id, 'quantity' => '1000']]);
        $before = $this->snapshot();

        $response = $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '1200'],
        ], 'over-1'))->assertStatus(422)->json();

        $this->assertRefusedForLine($response, $lineId, '1000.0000', '1200');
        $this->assertSame($before, $this->snapshot(), 'A refused over-receipt must leave the database exactly as it was');
        $this->assertSame(0, GoodsReceiptNote::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBalance::query()->count());
        $this->assertSame(0, MaterialLot::query()->count());
        $this->assertSame(0, TallySyncEntry::query()->count(), 'No Receipt Note for a receipt that never happened');
        $this->assertSame(PurchaseOrderStatus::Sent, $order->fresh()->status);
        $this->assertSame('0.0000', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);
    }

    // ---- after a partial: the REMAINING is what counts, to 4 dp -------------------

    public function test_after_a_partial_receipt_the_refusal_names_the_remaining_quantity_and_the_first_receipt_stands(): void
    {
        [$order, [$lineId]] = $this->sentOrder([['item_id' => $this->itemA->id, 'quantity' => '1000']]);

        $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '400'],
        ], 'partial-1'))->assertSuccessful();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
        $before = $this->snapshot();

        // Over by one ten-thousandth of a kilogram is still over.
        $response = $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '600.0001'],
        ], 'partial-2'))->assertStatus(422)->json();

        $this->assertRefusedForLine($response, $lineId, '600.0000', '600.0001');
        $this->assertSame($before, $this->snapshot(), 'The refusal must not touch the earlier receipt or its stock');
        $this->assertSame(1, GoodsReceiptNote::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(1, TallySyncEntry::query()->count(), 'Exactly the first receipt\'s Receipt Note, still');
        $this->assertSame('400.0000', (string) StockBalance::query()->where('item_id', $this->itemA->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $this->assertSame('400.0000', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);

        // Exactly the remaining is accepted and closes the order.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '600'],
        ], 'partial-3'))->assertSuccessful();
        $this->assertSame(PurchaseOrderStatus::Closed, $order->fresh()->status);
        $this->assertSame('1000.0000', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);
        $this->assertSame('1000.0000', (string) StockBalance::query()->where('item_id', $this->itemA->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $this->assertSame(2, TallySyncEntry::query()->count());
    }

    // ---- per LINE, and atomic across lines ---------------------------------------

    public function test_the_check_is_per_line_and_one_over_receiving_line_rolls_back_the_whole_receipt(): void
    {
        [$order, [$lineA, $lineB]] = $this->sentOrder([
            ['item_id' => $this->itemA->id, 'quantity' => '1000'],
            ['item_id' => $this->itemB->id, 'quantity' => '500'],
        ]);
        $before = $this->snapshot();

        // 300 + 501 = 801, well inside the order's 1500 — but line B is over
        // its own 500 by one kilogram, and line A was fine and is written
        // FIRST inside the transaction.
        $response = $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineA, 'quantity' => '300'],
            ['purchase_order_line_id' => $lineB, 'quantity' => '501'],
        ], 'multi-1'))->assertStatus(422)->json();

        $this->assertRefusedForLine($response, $lineB, '500.0000', '501');
        $this->assertStringNotContainsString("line #{$lineA}", $response['message']);
        $this->assertSame($before, $this->snapshot(), 'Line A\'s receipt line, stock, lot and bag must all roll back with line B\'s refusal');
        $this->assertSame(0, GoodsReceiptNoteLine::query()->count());
        $this->assertSame(0, StockMovement::query()->where('item_id', $this->itemA->id)->count(), 'Line A\'s movement was rolled back');
        $this->assertNull(StockBalance::query()->where('item_id', $this->itemA->id)->first(), 'Line A\'s balance was rolled back');
        $this->assertSame(0, MaterialLot::query()->count());
        $this->assertSame(0, MaterialBag::query()->count());
        $this->assertSame(0, TallySyncEntry::query()->count());
        $this->assertSame('0.0000', (string) PurchaseOrderLine::query()->findOrFail($lineA)->quantity_received);
        $this->assertSame('0.0000', (string) PurchaseOrderLine::query()->findOrFail($lineB)->quantity_received);
        $this->assertSame(PurchaseOrderStatus::Sent, $order->fresh()->status);

        // The same two lines within their limits post as one receipt.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineA, 'quantity' => '300'],
            ['purchase_order_line_id' => $lineB, 'quantity' => '500'],
        ], 'multi-2'))->assertSuccessful();
        $this->assertSame(2, GoodsReceiptNoteLine::query()->count());
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame(1, TallySyncEntry::query()->count(), 'One receipt, one Receipt Note — however many lines');
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status, 'Line B is complete but line A is not');
    }

    // ---- the plan (schedules) may be over-covered; the line may not ---------------

    public function test_a_delivery_may_over_cover_the_schedules_but_never_the_line(): void
    {
        [$order, [$lineId]] = $this->sentOrder([[
            'item_id' => $this->itemA->id,
            'quantity' => '300',
            'schedules' => [
                ['due_date' => '2026-08-05', 'quantity' => '100'],
                ['due_date' => '2026-08-15', 'quantity' => '100'],
            ],
        ]]);

        // 300 arrives against a plan of 200: both windows fill, the extra
        // 100 carries no allocation row, the LINE's quantity_received is the
        // authoritative 300, and the order closes.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '300'],
        ], 'plan-1'))->assertSuccessful();

        $this->assertSame(['100.0000', '100.0000'], PurchaseOrderSchedule::query()->orderBy('due_date')->pluck('quantity_received')->map(fn ($q) => (string) $q)->all());
        $this->assertSame('200.0000', GrnScheduleAllocation::query()->get()->reduce(fn (string $c, $a) => bcadd($c, (string) $a->quantity, 4), '0.0000'));
        $this->assertSame('300.0000', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);
        $this->assertSame(PurchaseOrderStatus::Closed, $order->fresh()->status);

        // Nothing more can be received: the order is Closed, so the status
        // refusal fires before any quantity is compared.
        $before = $this->snapshot();
        $message = $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '1'],
        ], 'plan-2'))->assertStatus(422)->json('message');
        $this->assertStringContainsString('"closed"', $message);
        $this->assertStringNotContainsString('remaining', $message);
        $this->assertSame($before, $this->snapshot());
    }

    // ---- exactly the remaining, across several receipts, closes; then Closed wins --

    public function test_receipts_that_sum_to_exactly_the_line_close_the_order_and_the_closed_refusal_precedes_the_quantity_check(): void
    {
        [$order, [$lineId]] = $this->sentOrder([['item_id' => $this->itemA->id, 'quantity' => '1000']]);

        foreach ([['250', 'sum-1'], ['250', 'sum-2'], ['499.9999', 'sum-3']] as [$quantity, $key]) {
            $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
                ['purchase_order_line_id' => $lineId, 'quantity' => $quantity],
            ], $key))->assertSuccessful();
            $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status, "still open after {$key}");
        }
        $this->assertSame('999.9999', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);

        // 0.0002 over the last 0.0001 → refused, to the fourth decimal.
        $response = $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '0.0002'],
        ], 'sum-4'))->assertStatus(422)->json();
        $this->assertRefusedForLine($response, $lineId, '0.0001', '0.0002');

        // The last 0.0001 closes it.
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '0.0001'],
        ], 'sum-5'))->assertSuccessful();
        $this->assertSame(PurchaseOrderStatus::Closed, $order->fresh()->status);
        $this->assertSame('1000.0000', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);
        $this->assertSame('1000.0000', (string) StockBalance::query()->where('item_id', $this->itemA->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $this->assertSame(4, GoodsReceiptNote::query()->count());
        $this->assertSame(4, TallySyncEntry::query()->count(), 'One Receipt Note per receipt, four receipts');

        // Closed: refused as Closed, not as an over-receipt.
        $message = $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, [
            ['purchase_order_line_id' => $lineId, 'quantity' => '0.0001'],
        ], 'sum-6'))->assertStatus(422)->json('message');
        $this->assertStringContainsString('"closed"', $message);
        $this->assertStringNotContainsString('remaining', $message);
        $this->assertSame(4, GoodsReceiptNote::query()->count());
    }
}
