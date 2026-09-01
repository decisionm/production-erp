<?php

namespace Tests\Feature\TallySync\PerType;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\TallySync\Models\TallySyncEntry;

/**
 * Delivery Note: finished goods dispatched against a sales order (POST
 * /sales/deliveries) → DeliveryDispatched → Tally 'Delivery Note'. Beyond
 * the shared lifecycle, this type's own facts:
 *
 *   - DUPLICATE-REFUSED lives in the ORDER first, and since Phase 3.5 on the
 *     queue too: TallySyncService::enqueue() returns the live entry for a
 *     re-fired DeliveryDispatched (GenericEnqueueReplayTest) — but what stops
 *     the same dispatch booking twice at the source is that the second POST
 *     finds the order fully delivered (Completed) and is refused before any
 *     line, stock issue or event; on the scan path the carton itself refuses
 *     to leave twice (DispatchRefusesQualityRejectedCartonTest);
 *   - the payload carries NO price for anyone, agent included: a Delivery
 *     Note is a stock movement, not a bill (TallySyncService::enqueueDelivery).
 *
 * The DEC-20260807-013 carton-scan path into this same voucher is walked in
 * tests/Feature/Sales/DispatchRefusesQualityRejectedCartonTest.php.
 */
class DeliveryNoteLifecycleTest extends PerTypeLifecycleTestCase
{
    private SalesOrder $order;

    private SalesOrderLine $line;

    private Warehouse $fgStore;

    protected function setUp(): void
    {
        parent::setUp();

        $bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos', 'tally_stock_item_guid' => 'itm-bottle']);
        $this->fgStore = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        app(StockMovementService::class)->recordReceipt(
            itemId: $bottle->id, warehouseId: $this->fgStore->id, quantity: '5000', unitCost: '2.50', reference: 'seed',
        );

        $customer = Customer::create(['code' => 'CUST-1', 'name' => 'Sri Aurobindo Beverages', 'gstin' => '34AABCA1122G1Z4']);
        $this->order = SalesOrder::create(['customer_id' => $customer->id, 'status' => SalesOrderStatus::Confirmed, 'order_date' => '2026-08-09']);
        $this->line = $this->order->lines()->create(['item_id' => $bottle->id, 'quantity' => '2000', 'unit_price' => '4.50', 'quantity_delivered' => 0]);
    }

    private function postDelivery()
    {
        // The Store dispatches (DEC-20260901-005), so the dispatch desk holds
        // `inventory.manage`; it keeps sales.view to read back what it sent.
        return $this->asUser($this->staff('Dispatch Desk', ['sales.view', 'inventory.manage']))
            ->postJson('/api/v1/sales/deliveries', [
                'sales_order_id' => $this->order->id,
                'warehouse_id' => $this->fgStore->id,
                'delivered_date' => '2026-08-10',
                'notes' => 'Truck A',
                'lines' => [['sales_order_line_id' => $this->line->id, 'quantity' => '2000']],
            ]);
    }

    protected function enqueueViaDomain(): TallySyncEntry
    {
        $this->postDelivery()->assertSuccessful();

        return TallySyncEntry::query()->sole();
    }

    protected function attemptDuplicateEnqueue(TallySyncEntry $entry): void
    {
        // The order is Completed after the first full dispatch — the same
        // POST again is refused at the status guard (DeliveryService:56-58),
        // before a second Delivery, stock issue or event exists.
        $this->postDelivery()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot transition sales order from "completed" to "delivered".');

        $this->assertSame(1, Delivery::query()->count());
        $this->assertSame('2000.0000', (string) $this->line->fresh()->quantity_delivered);
    }

    protected function expectedCategoryKey(): string
    {
        return 'delivery_note';
    }

    protected function expectedVoucherType(): string
    {
        return 'Delivery Note';
    }

    protected function expectedDocumentNumber(TallySyncEntry $entry): string
    {
        return "DN-{$entry->syncable_id}";
    }

    protected function tallyRejection(): string
    {
        return "Godown 'FG Store' does not exist!";
    }

    protected function expectedFixPath(): ?string
    {
        return '/production/configuration?tab=settings';
    }

    public function test_the_payload_is_a_stock_movement_with_no_price_for_anyone(): void
    {
        // The two preconditions the dispatch now carries, before the voucher is
        // staged. The masters, because enqueueDelivery() is fail-closed
        // (DEC-20260831-007): with no tally_ledger_name on the customer it
        // stages NOTHING and there is no payload to read. Then Quality's
        // dispatch sign-off (DEC-20260831-006), or the POST is refused 422 and
        // nothing leaves the store. The base's own four tests seed themselves;
        // this one is this class's, so it seeds itself too.
        $this->seedSalesTallyMasterData();
        $this->approveQualityOnEveryFixtureLine();

        $entry = $this->enqueueViaDomain();

        $this->assertSame('Sri Aurobindo Beverages', $entry->payload['party_ledger']);
        $this->assertSame('34AABCA1122G1Z4', $entry->payload['party_gstin']);
        $this->assertSame('FG Store', $entry->payload['godown']);
        $this->assertSame('2026-08-10', $entry->payload['voucher_date']);
        // Item, quantity and the stock item's own UOM — the three a stock line
        // names, and STILL nothing else: the whole-array match is what forbids
        // a rate or an amount creeping in beside them. The uom is the shape
        // DEC-20260831-007's rewrite of enqueueDelivery() now stages.
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
        $this->assertSame(['item' => '500ml PET Bottle', 'quantity' => '2000.0000', 'uom' => 'Nos'], $line);
        $this->assertArrayNotHasKey('total_amount', $entry->payload);

        // Nothing to gate: the viewer and the agent see the same lines.
        $viewerRow = $this->listedRow($entry->id);
        $this->assertSame($entry->payload['lines'], $viewerRow['payload']['lines']);
        $this->assertSame('Sri Aurobindo Beverages', $viewerRow['party']);
        $agentRow = collect($this->asAgent()->getJson('/api/v1/tally-sync/pending')->assertOk()->json('data'))->firstWhere('id', $entry->id);
        $this->assertSame($entry->payload['lines'], $agentRow['payload']['lines']);
    }
}
