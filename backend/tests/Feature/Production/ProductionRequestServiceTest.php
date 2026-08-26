<?php

namespace Tests\Feature\Production;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Exceptions\ProductionRequestException;
use App\Modules\Production\Models\Enums\ProductionRequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE FLOOR'S WORKLIST — the shortfall cap, the one-open-request rule, the
 * queue's order, and above all S1: whether a line is COVERED is judged on
 * the ORDER LINE and never on the request.
 *
 * Nothing in this file creates, starts or cancels a batch, and nothing here
 * writes a shift entry — a production request is paperwork (invariant 2).
 */
class ProductionRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Warehouse $fg;

    private Customer $customer;

    private User $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = User::factory()->create(['name' => 'Storekeeper', 'is_active' => true]);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
    }

    // ---- raising ----------------------------------------------------------

    public function test_a_line_may_only_have_one_open_request(): void
    {
        $line = $this->line('100');

        $first = $this->service()->createFromShortfall($line, '100', $this->store->id);

        try {
            $this->service()->createFromShortfall($line, '50', $this->store->id);
            $this->fail('A second open production request was raised for the same line.');
        } catch (ProductionRequestException $e) {
            $this->assertStringContainsString($first->documentNumber(), $e->getMessage());
        }

        $this->assertSame(1, ProductionRequest::query()->count());
    }

    /**
     * A CANCELLED REQUEST DOES NOT BLOCK A NEW ONE — a line whose first run
     * was scrapped has to be able to ask again.
     */
    public function test_a_cancelled_request_leaves_the_line_free_to_ask_again(): void
    {
        $line = $this->line('100');

        $first = $this->service()->createFromShortfall($line, '100', $this->store->id);
        $this->service()->cancel($first, 'mould broke');

        $second = $this->service()->createFromShortfall($line, '100', $this->store->id);

        $this->assertSame(ProductionRequestStatus::Queued, $second->status);
        $this->assertSame(2, ProductionRequest::query()->count());
    }

    /**
     * S14, THE SHORTFALL CAP. The store types a round 500 for a line that is
     * genuinely short of 40; the floor is asked for 40. Capping rather than
     * refusing is deliberate — a refusal over 460 pieces nobody is waiting
     * for would simply be retyped.
     */
    public function test_the_request_is_capped_at_what_the_line_is_really_short_of(): void
    {
        $this->seedStock('60');
        $line = $this->line('100');
        $line->update(['quantity_delivered' => '0']);
        app(StockReservationService::class)->reserve($line, '60', $this->store->id);

        $request = $this->service()->createFromShortfall($line->fresh(), '500', $this->store->id);

        $this->assertSame('40.0000', $request->quantity);
    }

    public function test_a_covered_line_has_nothing_for_the_floor_to_make(): void
    {
        $this->seedStock('100');
        $line = $this->line('100');
        app(StockReservationService::class)->reserve($line, '100', $this->store->id);

        $this->expectException(ProductionRequestException::class);
        $this->expectExceptionMessageMatches('/nothing .* to make/');

        $this->service()->createFromShortfall($line->fresh(), '10', $this->store->id);
    }

    public function test_a_draft_order_cannot_ask_the_floor_for_anything(): void
    {
        $line = $this->line('100', SalesOrderStatus::Draft);

        $this->expectException(ProductionRequestException::class);
        $this->expectExceptionMessageMatches('/confirmed or partially delivered/');

        $this->service()->createFromShortfall($line, '100', $this->store->id);
    }

    // ---- the queue --------------------------------------------------------

    public function test_new_requests_join_the_end_of_the_queue(): void
    {
        $first = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);
        $second = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);

        $this->assertSame(1, $first->priority);
        $this->assertSame(2, $second->priority);
    }

    public function test_reordering_renumbers_the_whole_queue(): void
    {
        $first = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);
        $second = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);
        $third = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);

        $this->service()->reorder([$third->id, $first->id, $second->id]);

        $this->assertSame(1, $third->fresh()->priority);
        $this->assertSame(2, $first->fresh()->priority);
        $this->assertSame(3, $second->fresh()->priority);
    }

    public function test_a_partial_reorder_is_refused(): void
    {
        $first = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);
        $second = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);

        try {
            $this->service()->reorder([$second->id]);
            $this->fail('A partial reorder renumbered part of the queue.');
        } catch (ProductionRequestException $e) {
            $this->assertStringContainsString('every one of them', $e->getMessage());
        }

        // Untouched: a partial list would leave $first carrying a stale
        // priority against the row that was renumbered.
        $this->assertSame(1, $first->fresh()->priority);
        $this->assertSame(2, $second->fresh()->priority);
    }

    public function test_only_a_queued_request_can_be_started(): void
    {
        $request = $this->service()->createFromShortfall($this->line('100'), '100', $this->store->id);

        $started = $this->service()->start($request);
        $this->assertSame(ProductionRequestStatus::InProgress, $started->status);

        $this->expectException(ProductionRequestException::class);
        $this->service()->start($started);
    }

    public function test_cancelling_an_order_withdraws_its_open_requests(): void
    {
        $line = $this->line('100');
        $request = $this->service()->createFromShortfall($line, '100', $this->store->id);

        $cancelled = $this->service()->cancelForOrder($line->salesOrder, 'so_cancelled');

        $this->assertSame(1, $cancelled);
        $this->assertSame(ProductionRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('so_cancelled', $request->fresh()->cancelled_reason);
    }

    // ---- S1: coverage is judged on the LINE -------------------------------

    /**
     * THE S1 COUNTER-EXAMPLE, and the whole reason coverage is judged on the
     * line rather than on the request.
     *
     * A 100-piece line. The store finds 90 free pieces and holds them, then
     * sends the remaining 10 to the floor. Ninety pieces have "appeared"
     * against a request for ten — and the request must NOT be produced,
     * because the LINE is covered 90 of 100 and the customer is still ten
     * bottles short.
     */
    public function test_holding_more_than_the_request_does_not_make_it_produced(): void
    {
        $this->seedStock('90');
        $line = $this->line('100');

        app(StockReservationService::class)->reserve($line, '90', $this->store->id);
        $request = $this->service()->createFromShortfall($line->fresh(), '10', $this->store->id);

        $marked = $this->service()->markProducedIfCovered($line->fresh());

        $this->assertSame(0, $marked);
        $this->assertSame(ProductionRequestStatus::Queued, $request->fresh()->status);
    }

    public function test_a_line_covered_by_holds_marks_its_request_produced(): void
    {
        $this->seedStock('100');
        $line = $this->line('100');

        app(StockReservationService::class)->reserve($line, '90', $this->store->id);
        $request = $this->service()->createFromShortfall($line->fresh(), '10', $this->store->id);

        // The floor made the last ten and the store held them — and the
        // reserve ITSELF retires the request (its transaction tail runs the
        // coverage test), so nothing waits for a delivery to notice.
        app(StockReservationService::class)->reserve($line->fresh(), '10', $this->store->id);

        $this->assertSame(ProductionRequestStatus::Produced, $request->fresh()->status);
        // The explicit call then finds nothing left open.
        $this->assertSame(0, $this->service()->markProducedIfCovered($line->fresh()));
    }

    /**
     * THE SEND-THEN-RESERVE GHOST. Stock arrives after a line was sent to
     * production; the store reserves the lot. Without the coverage test at
     * reserve's own tail, the request stayed queued and the floor was left
     * a standing target for pieces nobody needs — the sequential half of
     * the double-serve race (C2).
     */
    public function test_stock_reserved_after_a_send_to_production_retires_the_request(): void
    {
        $line = $this->line('100');
        $request = $this->service()->createFromShortfall($line->fresh(), '100', $this->store->id);

        // Production happened: 100 pieces land in the FG store.
        $this->seedStock('100');
        app(StockReservationService::class)->reserve($line->fresh(), '100', $this->store->id);

        $this->assertSame(ProductionRequestStatus::Produced, $request->fresh()->status);
    }

    /** The lock the C1 fix rides on, pinned on the builder (SQLite drops FOR UPDATE). */
    public function test_the_order_read_carries_a_real_lock(): void
    {
        $this->assertTrue(ProductionRequestService::openOrderQuery(1)->toBase()->lock);
    }

    /**
     * DELIVERED PIECES ARE NOT COUNTED TWICE. A hold that has been partly
     * consumed by a delivery has already put those pieces into
     * quantity_delivered; adding the hold's full quantity on top would mark
     * a line produced with real work still outstanding.
     */
    public function test_a_partly_delivered_hold_is_not_counted_twice(): void
    {
        $this->seedStock('80');
        $line = $this->line('100');

        app(StockReservationService::class)->reserve($line, '80', $this->store->id);
        $request = $this->service()->createFromShortfall($line->fresh(), '20', $this->store->id);

        // 60 of the 80 held pieces ship. Coverage is still 80 of 100 — 60
        // delivered plus 20 still held — not 140.
        $line->update(['quantity_delivered' => '60']);
        app(StockReservationService::class)->consumeForDelivery($line->fresh(), '60', $this->fg->id);

        $this->assertSame(ProductionRequestStatus::Queued, $request->fresh()->status);
    }

    // ---- fixtures ---------------------------------------------------------

    private function service(): ProductionRequestService
    {
        return app(ProductionRequestService::class);
    }

    private function seedStock(string $quantity): void
    {
        app(StockMovementService::class)->recordReceipt(
            itemId: $this->bottle->id,
            warehouseId: $this->fg->id,
            quantity: $quantity,
            unitCost: '2.50',
            reference: 'seed',
        );
    }

    private function line(string $quantity, SalesOrderStatus $status = SalesOrderStatus::Confirmed): SalesOrderLine
    {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => $status,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => $this->bottle->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => '0',
        ]);
    }
}
