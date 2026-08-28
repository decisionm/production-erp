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
 * receipt_key IDEMPOTENCY ACROSS THE WHOLE CHAIN. A goods receipt sent
 * twice with the same key is ONE receipt: one GRN with one line set, one
 * stock movement set, one lot set with its bags, one schedule allocation
 * set, one increment of the order line and of each schedule, one order
 * status transition — and, through the real TallySync listener (no
 * Event::fake here), ONE Receipt Note in tally_sync_entries with ONE
 * voucher.enqueued event. The replay returns the original receipt, in
 * full, whatever the order's status has become since.
 *
 * AtomicGoodsReceiptTraceabilityTest already pins the replay's GRN/movement/
 * lot/bag counts and the event dispatched once (with the listener faked),
 * the different-data 422, and the legacy no-key client. Those are not
 * repeated. What is added: the Tally row and event counts through the live
 * listener; the schedule allocation set; the byte-identical replay body; a
 * replay after the order has Closed (idempotency wins over the state
 * check); the key — not the content — being the identity; and a replay
 * whose keys arrive in another order.
 *
 * NOT covered anywhere (and not attempted here): the concurrent-retry race
 * that the unique receipt_key + QueryException catch resolves — two writers
 * cannot be staged on the suite's in-memory SQLite.
 *
 * FC-06: synthetic values only ("Vendor Alpha", "ITEM_A", 1.25).
 */
class GoodsReceiptIdempotencyContractTest extends TestCase
{
    use RefreshDatabase;

    private const UNIT_PRICE = '1.25';

    private Vendor $vendor;

    private Item $resin;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['production.traceability_enabled' => true]);

        $this->vendor = Vendor::create(['code' => 'VND-A', 'name' => 'Vendor Alpha', 'tally_ledger_name' => 'Vendor Alpha']);
        $this->resin = Item::create(['sku' => 'ITEM_A', 'name' => 'ITEM_A', 'uom' => 'Kgs', 'is_active' => true, 'tally_stock_item_guid' => 'guid-item-a']);
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
     * A Sent order for 1000 kg of ITEM_A in two delivery windows (400 due
     * first, 600 later), so a receipt has schedules to allocate against.
     *
     * @return array{0: PurchaseOrder, 1: int} [order, line id]
     */
    private function sentOrder(): array
    {
        $order = $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'order_date' => '2026-08-01',
            'lines' => [[
                'item_id' => $this->resin->id,
                'quantity' => '1000',
                'unit_price' => self::UNIT_PRICE,
                'schedules' => [
                    ['due_date' => '2026-08-05', 'quantity' => '400'],
                    ['due_date' => '2026-08-15', 'quantity' => '600'],
                ],
            ]],
        ])->assertSuccessful()->json('data');

        $this->postJson("/api/v1/procurement/purchase-orders/{$order['id']}/send")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PurchaseOrderStatus::Sent->value);

        return [PurchaseOrder::query()->findOrFail($order['id']), $order['lines'][0]['id']];
    }

    /** @return array<string, mixed> */
    private function receipt(PurchaseOrder $order, int $lineId, string $quantity, string $key): array
    {
        return [
            'receipt_key' => $key,
            'purchase_order_id' => $order->id,
            'warehouse_id' => $this->store->id,
            'reference' => 'REF-'.$key,
            'received_date' => '2026-08-02 10:00:00',
            'notes' => 'idempotency contract',
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'quantity' => $quantity,
                'lots' => [[
                    'supplier_lot_no' => 'LOT-'.$key,
                    'bag_count' => (int) ((float) $quantity / 25),
                    'bag_weight_kg' => '25',
                ]],
            ]],
        ];
    }

    /** Every count a second write would disturb. @return array<string, int|string> */
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

    // ---- the same receipt twice: one of everything --------------------------------

    public function test_the_same_receipt_twice_is_one_grn_one_movement_set_one_lot_set_one_allocation_set_and_one_receipt_note(): void
    {
        [$order, $lineId] = $this->sentOrder();
        $payload = $this->receipt($order, $lineId, '400', 'idem-1');

        $first = $this->postJson('/api/v1/procurement/goods-receipts', $payload)->assertSuccessful()->json('data');
        $after = $this->snapshot();

        $second = $this->postJson('/api/v1/procurement/goods-receipts', $payload)->assertSuccessful()->json('data');

        // The same receipt, byte for byte — not merely the same id.
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first, $second, 'A replay returns the original receipt in full');
        $this->assertSame($after, $this->snapshot(), 'A replay writes nothing');

        // One of everything.
        $this->assertSame(1, GoodsReceiptNote::query()->count());
        $this->assertSame(1, GoodsReceiptNoteLine::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame('400.0000', (string) StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $this->assertSame(1, MaterialLot::query()->count());
        $this->assertSame(16, MaterialBag::query()->count());

        // One allocation set: the 400 fills the first window once, the
        // second window is untouched, the line is incremented once.
        $allocation = GrnScheduleAllocation::query()->sole();
        $this->assertSame('400.0000', (string) $allocation->quantity);
        $this->assertSame(['400.0000', '0.0000'], PurchaseOrderSchedule::query()->orderBy('due_date')->pluck('quantity_received')->map(fn ($q) => (string) $q)->all());
        $this->assertSame('400.0000', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);

        // ONE Receipt Note, ONE enqueued event, through the live listener.
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Receipt Note', $entry->tally_voucher_type);
        $this->assertSame($first['id'], (int) $entry->syncable_id);
        $this->assertSame((new GoodsReceiptNote)->getMorphClass(), $entry->syncable_type);
        $this->assertSame($first['receipt_note_reference'], $entry->payload['voucher_number']);
        $this->assertSame(1, TallySyncEvent::query()->count());
    }

    // ---- replay after the order Closed --------------------------------------------

    public function test_a_replay_after_the_order_has_closed_still_returns_the_original_receipt(): void
    {
        [$order, $lineId] = $this->sentOrder();
        $partial = $this->receipt($order, $lineId, '400', 'closed-1');

        $firstId = $this->postJson('/api/v1/procurement/goods-receipts', $partial)->assertSuccessful()->json('data.id');
        $this->postJson('/api/v1/procurement/goods-receipts', $this->receipt($order, $lineId, '600', 'closed-2'))->assertSuccessful();
        $this->assertSame(PurchaseOrderStatus::Closed, $order->fresh()->status);
        $before = $this->snapshot();

        // A Closed order refuses NEW receipts (PurchaseChainContractTest); a
        // REPLAY of one it already has is not new — the key is answered
        // before the state is even looked at.
        $this->postJson('/api/v1/procurement/goods-receipts', $partial)
            ->assertSuccessful()
            ->assertJsonPath('data.id', $firstId)
            ->assertJsonPath('data.receipt_key', 'closed-1');

        $this->assertSame($before, $this->snapshot(), 'The replay wrote nothing');
        $this->assertSame(2, GoodsReceiptNote::query()->count());
        $this->assertSame(2, TallySyncEntry::query()->count(), 'Two receipts, two Receipt Notes, none for the replay');
        $this->assertSame(PurchaseOrderStatus::Closed, $order->fresh()->status);
    }

    // ---- the key is the identity, not the content -------------------------------

    public function test_the_receipt_key_not_the_content_is_the_identity(): void
    {
        [$order, $lineId] = $this->sentOrder();

        // The same arrival data under two keys is two arrivals: two lorries
        // can carry the same load on the same day. Each is a receipt, a
        // movement, a lot, an allocation and a Receipt Note of its own.
        $one = $this->receipt($order, $lineId, '400', 'lorry-1');
        $two = ['receipt_key' => 'lorry-2', ...array_diff_key($one, ['receipt_key' => null])];
        $this->assertSame(array_diff_key($one, ['receipt_key' => null]), array_diff_key($two, ['receipt_key' => null]), 'Identical content, only the key differs');

        $firstId = $this->postJson('/api/v1/procurement/goods-receipts', $one)->assertSuccessful()->json('data.id');
        $secondId = $this->postJson('/api/v1/procurement/goods-receipts', $two)->assertSuccessful()->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, GoodsReceiptNote::query()->count());
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame('800.0000', (string) StockBalance::query()->where('item_id', $this->resin->id)->where('warehouse_id', $this->store->id)->sole()->quantity);
        $this->assertSame(2, MaterialLot::query()->count());
        $this->assertSame(32, MaterialBag::query()->count());
        $this->assertSame('800.0000', (string) PurchaseOrderLine::query()->findOrFail($lineId)->quantity_received);
        // 400 fills the first window, the next 400 goes into the second.
        $this->assertSame(['400.0000', '400.0000'], PurchaseOrderSchedule::query()->orderBy('due_date')->pluck('quantity_received')->map(fn ($q) => (string) $q)->all());
        $this->assertSame(2, GrnScheduleAllocation::query()->count());
        $this->assertSame([$firstId, $secondId], TallySyncEntry::query()->orderBy('id')->pluck('syncable_id')->map(fn ($id) => (int) $id)->all(), 'One Receipt Note per receipt');
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
    }

    // ---- key order is not content ---------------------------------------------------

    /**
     * A client that re-sends the same receipt with its keys in another order
     * (a rebuilt form, a re-serialised retry) is replaying, not conflicting:
     * the payload hash is over the CANONICALISED data (GoodsReceiptService::
     * canonicalize sorts every associative level), so the order of keys is
     * not part of a receipt's identity.
     */
    public function test_a_replay_whose_keys_arrive_in_another_order_is_a_replay_not_a_conflict(): void
    {
        [$order, $lineId] = $this->sentOrder();
        $payload = $this->receipt($order, $lineId, '400', 'order-1');

        $firstId = $this->postJson('/api/v1/procurement/goods-receipts', $payload)->assertSuccessful()->json('data.id');
        $before = $this->snapshot();

        $reordered = array_reverse($payload, true);
        $reordered['lines'] = [array_reverse($payload['lines'][0], true)];
        $reordered['lines'][0]['lots'] = [array_reverse($payload['lines'][0]['lots'][0], true)];
        $this->assertNotSame(array_keys($payload), array_keys($reordered), 'The keys really are in another order');

        $this->postJson('/api/v1/procurement/goods-receipts', $reordered)
            ->assertSuccessful()
            ->assertJsonPath('data.id', $firstId);

        $this->assertSame($before, $this->snapshot(), 'A reordered replay writes nothing');
        $this->assertSame(1, TallySyncEntry::query()->count());
    }
}
