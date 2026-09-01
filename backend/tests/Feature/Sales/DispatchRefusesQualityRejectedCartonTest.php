<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\FinishedCarton;
use App\Modules\Production\Models\Shift;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Sales\Exceptions\OverDeliveryException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\DeliveryService;
use App\Modules\TallySync\Models\Enums\TallySyncStatus;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\SeedsSalesTallyMasterData;
use Tests\TestCase;

/**
 * DEC-20260807-013 END TO END: the sticker on a carton is permanent, so the
 * dispatch SCAN is where the batch's quality truth speaks — and the whole
 * chain behind that scan is walked here, from the guard in
 * DeliveryService::linesFromCartons() (:149-158) to the Delivery Note the
 * good path queues for Tally.
 *
 *   - a carton of a QUALITY-REJECTED batch is refused by name at the
 *     dispatch scan; nothing moves (carton, order line, stock, queue) — and
 *     the lookup for the same carton announces QUALITY REJECTED;
 *   - a carton of a batch NOT YET through QC/approval is not blocked (open
 *     owner question Q27 — the guard is deliberately not that tight) but the
 *     scan shows the pending state;
 *   - a good carton dispatches: the delivery's lines are derived from the
 *     boxes that left, FG stock is decremented by exactly those pieces, and
 *     ONE Delivery Note is queued for Tally with those pieces on it;
 *   - a scan that mixes one rejected carton with good ones refuses the WHOLE
 *     dispatch — the good cartons are back in stock, not half-shipped;
 *   - OverDeliveryException (audit §4.6, untested until now) refuses more
 *     than the order's remaining quantity, on the typed path and the scan
 *     path alike, and leaves nothing behind.
 *
 * TWO PRECONDITIONS THIS FILE ASSUMES RATHER THAN TESTS, both seeded in
 * confirmedOrder(): Quality's sign-off on the order line (DEC-20260831-006 —
 * DispatchQualityGateTest is that gate's subject), and the Tally master data a
 * Delivery Note is staged against (DEC-20260831-007). Neither is this file's
 * subject; the carton's own quality truth is.
 */
class DispatchRefusesQualityRejectedCartonTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSalesTallyMasterData;

    private Item $bottle;

    private Warehouse $fg;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        // The STORE dispatches (DEC-20260901-005, resolving Q78), so the
        // dispatching login holds `inventory.manage` and no Sales permission
        // at all — it needs production's for the carton scan surface only.
        $this->dispatcher = User::factory()->create(['name' => 'Ravi Dispatch', 'is_active' => true]);
        foreach (['production.view', 'production.manage', 'inventory.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->dispatcher->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->dispatcher);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
    }

    /** A completed, counted batch in the given approval state, with its cartons labelled (3 × 600 + 360). */
    private function labelledBatch(ShiftProductionEntryStatus $status, string $batchNumber = '20260802-M01-001'): ShiftProductionEntry
    {
        $shift = Shift::firstOrCreate(['name' => 'Morning'], ['start_time' => '06:00', 'end_time' => '14:00', 'is_active' => true]);
        $machine = WorkCenter::firstOrCreate(['code' => 'MC-01'], ['name' => 'Machine 1', 'is_active' => true]);

        $entry = ShiftProductionEntry::create([
            'shift_id' => $shift->id,
            'work_center_id' => $machine->id,
            'item_id' => $this->bottle->id,
            'warehouse_id' => $this->fg->id,
            'production_date' => '2026-08-02',
            'batch_number' => $batchNumber,
            'status' => ShiftProductionEntryStatus::Pending,
            'batch_status' => BatchStatus::Completed,
            'quantity_produced' => '2160',
            'nos_per_box' => '600',
        ]);

        // Labelled while pending — cartons are physical boxes packed before
        // any gate speaks — then the batch reaches its state.
        $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/cartons")->assertSuccessful();
        $entry->update(['status' => $status]);

        return $entry;
    }

    /** FG stock to dispatch from, and a confirmed order for the bottle. */
    private function confirmedOrder(string $ordered = '2000', string $stock = '2160'): SalesOrder
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id, warehouseId: $this->fg->id,
            quantity: $stock, unitCost: '2.50', reference: 'seed',
        );

        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
        $order = SalesOrder::create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::Confirmed,
            'order_date' => '2026-08-02',
        ]);
        $order->lines()->create([
            'item_id' => $this->bottle->id, 'quantity' => $ordered,
            'unit_price' => '4.50', 'quantity_delivered' => 0,
        ]);

        // DEC-20260831-006: dispatch is gated on Quality's sign-off, so every
        // order this file dispatches from is signed for its WHOLE ordered
        // quantity. That caps nothing any test here leans on — the
        // over-delivery guard is judged first and against the same figure, and
        // a quality-REJECTED carton is refused earlier still, in
        // linesFromCartons — so the sign-off is a precondition, not a subject.
        $this->approveQualityForOrder($order->id);

        // DEC-20260831-007: the Delivery Note is fail-closed and stages nothing
        // unless the customer carries a tally_ledger_name. Called HERE, after
        // the customer row exists, because the trait completes database rows.
        // It adds no second Tally-linked warehouse — setUp() already made one,
        // and two would leave the godown ambiguous.
        $this->seedSalesTallyMasterData();

        return $order;
    }

    /** @param  list<string>  $cartons */
    private function dispatch(SalesOrder $order, array $cartons)
    {
        return $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'delivered_date' => '2026-08-10',
            'carton_codes' => $cartons,
        ]);
    }

    private function fgBalance(): string
    {
        return (string) StockBalance::query()
            ->where('item_id', $this->bottle->id)
            ->where('warehouse_id', $this->fg->id)
            ->value('quantity');
    }

    private function assertNothingMoved(SalesOrder $order, string $stockBefore, string ...$cartons): void
    {
        foreach ($cartons as $carton) {
            $this->assertSame(FinishedCarton::STATUS_IN_STOCK, FinishedCarton::query()->where('carton_no', $carton)->value('status'), "{$carton} must still be in stock");
            $this->assertNull(FinishedCarton::query()->where('carton_no', $carton)->value('delivery_id'));
        }
        $this->assertSame('0.0000', (string) $order->lines()->first()->fresh()->quantity_delivered);
        $this->assertSame(SalesOrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(0, Delivery::query()->count(), 'A refused dispatch leaves no Delivery row');
        $this->assertSame(0, StockMovement::query()->where('type', 'issue')->count(), 'A refused dispatch issues no stock');
        $this->assertSame($stockBefore, $this->fgBalance());
        $this->assertSame(0, TallySyncEntry::query()->count(), 'A refused dispatch queues nothing for Tally');
    }

    // ---- the refusal -----------------------------------------------------

    public function test_a_quality_rejected_batchs_carton_is_refused_at_the_dispatch_scan_and_nothing_moves(): void
    {
        $this->labelledBatch(ShiftProductionEntryStatus::Rejected);
        $order = $this->confirmedOrder();
        $stockBefore = $this->fgBalance();

        $response = $this->dispatch($order, ['20260802-M01-001-C01'])->assertStatus(422);

        // The guard's own sentence (DeliveryService:154-158) — it names the
        // exact carton, because the person hearing it is holding the box,
        // and says QUALITY REJECTED in so many words.
        $this->assertSame(
            'Carton 20260802-M01-001-C01 is QUALITY REJECTED — its batch failed quality/approval and its boxes must not ship.',
            $response->json('errors.carton_codes.0'),
        );
        $this->assertStringContainsString('QUALITY REJECTED', $response->json('message'));

        $this->assertNothingMoved($order, $stockBefore, '20260802-M01-001-C01');
    }

    public function test_the_lookup_for_that_carton_announces_quality_rejected(): void
    {
        $this->labelledBatch(ShiftProductionEntryStatus::Rejected);

        $quality = $this->getJson('/api/v1/production/cartons/20260802-M01-001-C01')
            ->assertOk()
            ->json('data.quality');

        // The one word the scanner renders loud, and the raw state behind it.
        $this->assertSame('quality_rejected', $quality['verdict']);
        $this->assertSame('rejected', $quality['entry_status']);
    }

    public function test_a_scan_mixing_one_rejected_carton_with_good_ones_refuses_the_whole_dispatch(): void
    {
        // Two batches on the floor: one approved, one quality-rejected. The
        // dispatcher scans two good boxes and, third, a rejected one.
        $this->labelledBatch(ShiftProductionEntryStatus::Approved, '20260802-M01-001');
        $this->labelledBatch(ShiftProductionEntryStatus::Rejected, '20260802-M02-002');
        $order = $this->confirmedOrder('4000', '4320');
        $stockBefore = $this->fgBalance();

        $this->dispatch($order, ['20260802-M01-001-C01', '20260802-M01-001-C02', '20260802-M02-002-C01'])
            ->assertStatus(422)
            ->assertJsonPath('errors.carton_codes.0', 'Carton 20260802-M02-002-C01 is QUALITY REJECTED — its batch failed quality/approval and its boxes must not ship.');

        // The two good cartons had already been stamped dispatched inside the
        // transaction when the third was refused — and are back in stock,
        // because the dispatch is one transaction (DeliveryService::create).
        $this->assertNothingMoved($order, $stockBefore, '20260802-M01-001-C01', '20260802-M01-001-C02', '20260802-M02-002-C01');
    }

    // ---- not blocked: pending ---------------------------------------------

    public function test_a_carton_of_a_batch_not_yet_through_qc_is_not_blocked_and_the_scan_shows_pending(): void
    {
        $this->labelledBatch(ShiftProductionEntryStatus::Pending);
        $order = $this->confirmedOrder();

        // The lookup says exactly where the batch is: pending, QC not yet
        // counted (the QC figures honestly null, not zero).
        $quality = $this->getJson('/api/v1/production/cartons/20260802-M01-001-C01')->assertOk()->json('data.quality');
        $this->assertSame('pending', $quality['verdict']);
        $this->assertSame('pending', $quality['entry_status']);
        $this->assertNull($quality['qc_approved_nos']);
        $this->assertNull($quality['qc_rejected_nos']);

        // …and dispatch does NOT block it — tightening this is open owner
        // question Q27, not this guard's call (DeliveryService:149-153).
        $this->dispatch($order, ['20260802-M01-001-C01'])->assertSuccessful();

        $this->assertSame('600.0000', (string) $order->lines()->first()->fresh()->quantity_delivered);
        $this->assertSame(FinishedCarton::STATUS_DISPATCHED, FinishedCarton::query()->where('carton_no', '20260802-M01-001-C01')->value('status'));
    }

    // ---- the good path, all the way to the Tally queue --------------------

    public function test_a_good_carton_dispatches_and_one_delivery_note_is_queued_for_tally(): void
    {
        $this->labelledBatch(ShiftProductionEntryStatus::Approved);
        $order = $this->confirmedOrder();

        $delivery = $this->dispatch($order, ['20260802-M01-001-C01', '20260802-M01-001-C02'])
            ->assertSuccessful()
            ->json('data');

        // The delivery's line is DERIVED from the boxes that left: 2 × 600.
        $this->assertSame('1200.0000', (string) $order->lines()->first()->fresh()->quantity_delivered);
        $this->assertSame(SalesOrderStatus::PartiallyDelivered, $order->fresh()->status);
        $dispatched = FinishedCarton::query()->where('status', FinishedCarton::STATUS_DISPATCHED)->get();
        $this->assertSame(['20260802-M01-001-C01', '20260802-M01-001-C02'], $dispatched->pluck('carton_no')->sort()->values()->all());
        $this->assertTrue($dispatched->every(fn (FinishedCarton $carton) => $carton->delivery_id === $delivery['id']));

        // FG stock went down by exactly those pieces (audit §4.6: the
        // delivery stock decrement) — 2160 seeded − 1200 shipped.
        $this->assertSame('960.0000', $this->fgBalance());
        $issue = StockMovement::query()->where('type', 'issue')->sole();
        $this->assertSame('1200.0000', (string) $issue->quantity);
        $this->assertSame($this->fg->id, $issue->warehouse_id);

        // ONE Delivery Note in the Tally queue, carrying the same pieces, the
        // customer as party, the FG godown, and no price at all — a Delivery
        // Note is a stock movement, not a bill (TallySyncService::enqueueDelivery).
        $entry = TallySyncEntry::query()->sole();
        $this->assertSame('Delivery Note', $entry->tally_voucher_type);
        $this->assertSame(TallySyncStatus::Pending, $entry->status);
        $this->assertSame((string) $delivery['id'], (string) $entry->syncable_id);
        $this->assertSame("DN-{$delivery['id']}", $entry->payload['voucher_number']);
        $this->assertSame('2026-08-10', $entry->payload['voucher_date']);
        $this->assertSame('Aqua Traders', $entry->payload['party_ledger']);
        $this->assertSame('33AAACA1111A1Z5', $entry->payload['party_gstin']);
        $this->assertSame('FG Store', $entry->payload['godown']);
        // The Delivery Note line carries its UNIT now (DEC-20260831-007): the
        // voucher prints "1200.0000 Nos.", and a quantity without its unit is
        // the shape of the tape defect FC-03 records — 229 metres filed as 229
        // Nos is a different number about a different thing.
        // ORDER-INDEPENDENT, DELIBERATELY. MySQL's native JSON column type
        // NORMALISES object key order (shortest key first), while SQLite stores
        // the text verbatim — so the decoded payload reads
        // ['uom','item','quantity'] on CI and ['item','quantity','uom'] locally,
        // and an order-sensitive assertSame passes on one engine and fails on
        // the other. Sorting both sides keeps the WHOLE-ARRAY match that forbids
        // a rate or an amount creeping in beside them, without pinning an order
        // neither engine promises.
        $line = $entry->payload['lines'][0];
        ksort($line);
        $this->assertCount(1, $entry->payload['lines']);
        $this->assertSame(['item' => '500ml PET Bottle', 'quantity' => '1200.0000', 'uom' => 'Nos'], $line);
        $this->assertArrayNotHasKey('total_amount', $entry->payload);

        // And the same box cannot leave twice.
        $this->dispatch($order, ['20260802-M01-001-C01'])
            ->assertStatus(422)
            ->assertJsonPath('errors.carton_codes.0', 'Carton 20260802-M01-001-C01 was already dispatched — it cannot leave twice.');
        $this->assertSame(1, TallySyncEntry::query()->count(), 'A refused re-dispatch queues no second Delivery Note');
    }

    // ---- OverDeliveryException (audit §4.6) --------------------------------

    public function test_a_typed_delivery_over_the_orders_remaining_quantity_is_refused_whole(): void
    {
        $order = $this->confirmedOrder('2000', '5000');
        $line = $order->lines()->first();
        $stockBefore = $this->fgBalance();

        $this->postJson('/api/v1/sales/deliveries', [
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '2500']],
        ])
            ->assertStatus(422)
            // OverDeliveryException::forLine — the remaining and the asked-for
            // figures, so the dispatcher can see by how much.
            ->assertJsonPath('message', "Cannot deliver more than the remaining ordered quantity for sales order line #{$line->id}: remaining 2000.0000, requested 2500.");

        $this->assertNothingMoved($order, $stockBefore);
    }

    public function test_a_scan_whose_cartons_exceed_the_orders_remaining_quantity_is_refused_whole(): void
    {
        // Order 1000; two full 600-piece cartons scanned = 1200 derived.
        $this->labelledBatch(ShiftProductionEntryStatus::Approved);
        $order = $this->confirmedOrder('1000', '2160');
        $line = $order->lines()->first();
        $stockBefore = $this->fgBalance();

        $this->dispatch($order, ['20260802-M01-001-C01', '20260802-M01-001-C02'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Cannot deliver more than the remaining ordered quantity for sales order line #{$line->id}: remaining 1000.0000, requested 1200.0000.");

        // The cartons were stamped inside the transaction and rolled back
        // with it — the same atomicity the mixed-scan case relies on.
        $this->assertNothingMoved($order, $stockBefore, '20260802-M01-001-C01', '20260802-M01-001-C02');
    }

    public function test_the_service_throws_over_delivery_exception_itself(): void
    {
        // The exception class is the contract other callers catch on — pinned
        // directly, beneath the 422 the API renders it as.
        $order = $this->confirmedOrder('2000', '5000');
        $line = $order->lines()->first();

        $this->expectException(OverDeliveryException::class);

        app(DeliveryService::class)->create([
            'sales_order_id' => $order->id,
            'warehouse_id' => $this->fg->id,
            'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '2000.0001']],
        ], $this->dispatcher->id);
    }
}
