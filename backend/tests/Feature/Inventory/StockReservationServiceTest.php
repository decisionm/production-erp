<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Core\Services\AppSettingService;
use App\Modules\Inventory\Exceptions\StockReservationException;
use App\Modules\Inventory\Models\Enums\StockReservationStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\AvailabilityService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOLDING FINISHED GOODS — the refusals, the demand cap, the atomic
 * re-point, and what a delivery does to a hold.
 *
 * The one thing every case here also asserts, directly or by construction:
 * NOTHING IN THIS SERVICE MOVES STOCK (invariant 1). The balance the store
 * reserves against is the same figure before and after.
 */
class StockReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Item $bottle;

    private Item $jar;

    private Warehouse $fg;

    private Customer $customer;

    private User $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = User::factory()->create(['name' => 'Storekeeper', 'is_active' => true]);

        $this->bottle = Item::create(['sku' => 'BTL-500', 'name' => '500ml PET Bottle', 'uom' => 'Nos']);
        $this->jar = Item::create(['sku' => 'JAR-1L', 'name' => '1L PET Jar', 'uom' => 'Nos']);
        // The ONE Tally-linked active warehouse, so FactoryWarehouseResolver
        // resolves finished goods without a setting — this factory's reality.
        $this->fg = Warehouse::create(['code' => 'FG', 'name' => 'FG Store', 'tally_guid' => 'gd-fg']);
        $this->customer = Customer::create(['code' => 'CUST-1', 'name' => 'Aqua Traders', 'gstin' => '33AAACA1111A1Z5']);
    }

    // ---- refusals ---------------------------------------------------------

    public function test_a_draft_order_cannot_hold_stock(): void
    {
        $this->seedStock('500');
        $line = $this->line('100', SalesOrderStatus::Draft);

        $this->expectException(StockReservationException::class);
        $this->expectExceptionMessageMatches('/confirmed or partially delivered/');

        $this->service()->reserve($line, '10', $this->store->id);
    }

    /**
     * THE ABSENT BALANCE ROW. No stock_balances row for this item in the FG
     * store at all — the refusal must read exactly like a zero balance, and
     * it must NOT create the row on the way past (StockMovementService's
     * lockBalance() would have).
     */
    public function test_an_absent_balance_row_refuses_and_is_never_created(): void
    {
        $line = $this->line('100');

        $this->assertSame(0, StockBalance::query()->count());

        try {
            $this->service()->reserve($line, '10', $this->store->id);
            $this->fail('A hold was taken against stock the factory does not have.');
        } catch (StockReservationException $e) {
            $this->assertStringContainsString('no free', $e->getMessage());
        }

        // The reservation path must never manufacture "the factory holds
        // this item here" out of somebody asking whether it does.
        $this->assertSame(0, StockBalance::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
    }

    public function test_more_than_the_free_stock_is_refused(): void
    {
        $this->seedStock('40');
        $line = $this->line('100');

        $this->expectException(StockReservationException::class);
        $this->expectExceptionMessageMatches('/Only 40\.0000 .* is free/');

        $this->service()->reserve($line, '41', $this->store->id);
    }

    /**
     * S5, THE DEMAND CAP. A line for 100 with 60 already held may not hold
     * another 50 even with 500 pieces free — the surplus would starve other
     * orders for pieces nobody will ever ship.
     */
    public function test_a_line_cannot_hold_more_than_the_customer_ordered(): void
    {
        $this->seedStock('500');
        $line = $this->line('100');

        $this->service()->reserve($line, '60', $this->store->id);

        try {
            $this->service()->reserve($line, '50', $this->store->id);
            $this->fail('A line held more than the customer ordered.');
        } catch (StockReservationException $e) {
            $this->assertStringContainsString('still needs 40.0000', $e->getMessage());
        }

        // The cap is the REMAINING demand, so exactly 40 is allowed.
        $this->service()->reserve($line, '40', $this->store->id);
        $this->assertSame('100.0000', $this->service()->heldOnLine($line->id));
    }

    public function test_delivered_pieces_count_against_the_demand_cap(): void
    {
        $this->seedStock('500');
        $line = $this->line('100');
        $line->update(['quantity_delivered' => '70']);

        $this->service()->reserve($line->fresh(), '30', $this->store->id);

        $this->expectException(StockReservationException::class);
        $this->service()->reserve($line->fresh(), '1', $this->store->id);
    }

    public function test_a_hold_has_to_be_for_more_than_nothing(): void
    {
        $this->seedStock('500');
        $line = $this->line('100');

        $this->expectException(StockReservationException::class);
        $this->service()->reserve($line, '0', $this->store->id);
    }

    public function test_holding_stock_never_moves_it(): void
    {
        $this->seedStock('500');
        $line = $this->line('100');
        $movementsBefore = StockMovement::query()->count();

        $this->service()->reserve($line, '100', $this->store->id);

        $balance = StockBalance::query()->where('item_id', $this->bottle->id)->firstOrFail();
        $this->assertSame('500.0000', $balance->quantity);
        // Not one ledger row: a hold is paperwork (invariant 1).
        $this->assertSame($movementsBefore, StockMovement::query()->count());
    }

    // ---- re-point ---------------------------------------------------------

    /**
     * S4, ATOMIC RE-POINT. Moving 30 of a 100-piece hold to another line
     * leaves the two rows holding 100 pieces between them — not 130.
     *
     * That is the invariant the availability read depends on: the sum over
     * ACTIVE holds must equal exactly the pieces being kept away from other
     * orders, whatever route they took to get there.
     */
    public function test_a_partial_repoint_moves_the_hold_without_inventing_one(): void
    {
        $this->seedStock('100');
        $urgent = $this->line('100');
        $other = $this->line('100');

        $hold = $this->service()->reserve($urgent, '100', $this->store->id);

        // Nothing is free at all now — the exact case a re-point exists for,
        // and the one a naive free-stock check on the target side would
        // refuse.
        $this->assertSame('0.0000', app(AvailabilityService::class)->forItem($this->bottle->id)['free']);

        $moved = $this->service()->repoint($hold, $other->id, '30', 'repointed', $this->store->id);

        $source = $hold->fresh();
        $this->assertSame(StockReservationStatus::Active, $source->status);
        $this->assertSame('70.0000', $source->outstandingQuantity());
        // A row that is still active WITH a reason on it is normal: the
        // reason describes the most recent give-up, not the whole row.
        $this->assertSame('30.0000', $source->released_quantity);
        $this->assertSame('repointed', $source->released_reason);
        $this->assertSame('30.0000', $moved->outstandingQuantity());
        $this->assertSame((int) $other->id, (int) $moved->sales_order_line_id);

        $availability = app(AvailabilityService::class)->forItem($this->bottle->id);
        $this->assertSame('100.0000', $availability['reserved']);
        $this->assertSame('0.0000', $availability['free']);
        $this->assertSame('0.0000', $availability['over_reserved']);
    }

    public function test_a_whole_hold_repointed_leaves_the_source_released(): void
    {
        $this->seedStock('100');
        $urgent = $this->line('100');
        $other = $this->line('100');

        $hold = $this->service()->reserve($urgent, '100', $this->store->id);
        $this->service()->repoint($hold, $other->id, '100', 'repointed', $this->store->id);

        $this->assertSame(StockReservationStatus::Released, $hold->fresh()->status);
        $this->assertSame('100.0000', app(AvailabilityService::class)->forItem($this->bottle->id)['reserved']);
    }

    public function test_a_hold_cannot_be_repointed_to_a_line_for_another_item(): void
    {
        $this->seedStock('100');
        $bottles = $this->line('100');
        $jars = $this->line('100', SalesOrderStatus::Confirmed, $this->jar);

        $hold = $this->service()->reserve($bottles, '100', $this->store->id);

        $this->expectException(StockReservationException::class);
        $this->expectExceptionMessageMatches('/same item/');

        $this->service()->repoint($hold, $jars->id, '10', 'repointed', $this->store->id);
    }

    public function test_the_target_line_still_obeys_the_demand_cap(): void
    {
        $this->seedStock('200');
        $big = $this->line('200');
        $small = $this->line('10');

        $hold = $this->service()->reserve($big, '200', $this->store->id);

        $this->expectException(StockReservationException::class);
        $this->expectExceptionMessageMatches('/still needs 10\.0000/');

        $this->service()->repoint($hold, $small->id, '50', 'repointed', $this->store->id);
    }

    // ---- release ----------------------------------------------------------

    public function test_a_fully_consumed_hold_refuses_to_be_released(): void
    {
        $this->seedStock('100');
        $line = $this->line('100');
        $hold = $this->service()->reserve($line, '100', $this->store->id);

        $line->update(['quantity_delivered' => '100']);
        $this->service()->consumeForDelivery($line->fresh(), '100', $this->fg->id);

        $this->assertSame(StockReservationStatus::Consumed, $hold->fresh()->status);

        $this->expectException(StockReservationException::class);
        $this->expectExceptionMessageMatches('/already spent/');

        $this->service()->release($hold->fresh(), 'changed my mind', $this->store->id);
    }

    // ---- consumption ------------------------------------------------------

    /**
     * S3, THE WAREHOUSE MISMATCH. A delivery dispatched from a warehouse
     * this line holds nothing in is a legal dispatch — the holds are in the
     * FG store, the van loaded from somewhere else. It spends no hold, and
     * it is NOT an error.
     */
    public function test_a_delivery_from_another_warehouse_spends_no_hold(): void
    {
        $this->seedStock('100');
        $elsewhere = Warehouse::create(['code' => 'DEPOT', 'name' => 'Depot']);
        $line = $this->line('100');
        $hold = $this->service()->reserve($line, '100', $this->store->id);

        $line->update(['quantity_delivered' => '40']);
        $this->service()->consumeForDelivery($line->fresh(), '40', $elsewhere->id);

        $this->assertSame('0.0000', $hold->fresh()->consumed_quantity);
        $this->assertSame(StockReservationStatus::Active, $hold->fresh()->status);
    }

    public function test_a_delivery_spends_the_oldest_matching_hold_first(): void
    {
        $this->seedStock('100');
        $line = $this->line('100');

        $first = $this->service()->reserve($line, '40', $this->store->id);
        $second = $this->service()->reserve($line, '60', $this->store->id);

        $line->update(['quantity_delivered' => '50']);
        $this->service()->consumeForDelivery($line->fresh(), '50', $this->fg->id);

        $this->assertSame('40.0000', $first->fresh()->consumed_quantity);
        $this->assertSame(StockReservationStatus::Consumed, $first->fresh()->status);
        $this->assertSame('10.0000', $second->fresh()->consumed_quantity);
        $this->assertSame(StockReservationStatus::Active, $second->fresh()->status);
    }

    /**
     * A FINISHED LINE GIVES ITS LEFTOVERS BACK, with the reason spelled out
     * — stock held for an order that has already shipped is stock held away
     * from an order that could still use it.
     */
    public function test_a_fully_delivered_line_releases_what_it_still_holds(): void
    {
        $this->seedStock('200');
        $line = $this->line('100');

        $spent = $this->service()->reserve($line, '60', $this->store->id);
        $leftover = $this->service()->reserve($line, '40', $this->store->id);

        // Delivered in full, but only 60 of it against a hold (the rest came
        // off a pallet nobody had reserved).
        $line->update(['quantity_delivered' => '100']);
        $this->service()->consumeForDelivery($line->fresh(), '60', $this->fg->id);

        $this->assertSame(StockReservationStatus::Consumed, $spent->fresh()->status);
        $this->assertSame(StockReservationStatus::Released, $leftover->fresh()->status);
        $this->assertSame('line_fulfilled', $leftover->fresh()->released_reason);
        $this->assertSame('0.0000', $this->service()->heldOnLine($line->id));
    }

    /**
     * THE CALL-ORDER CONTRACT, pinned for the lane that wires this into
     * DeliveryService: the leftover release is judged on the line's STORED
     * quantity_delivered, so consumeForDelivery must be called AFTER the
     * delivery has incremented it. Called before, the hold is still spent
     * correctly — but the line does not yet look finished, and its
     * leftovers stay held.
     */
    public function test_the_leftover_release_reads_the_stored_delivered_figure(): void
    {
        $this->seedStock('200');
        $line = $this->line('100');

        $spent = $this->service()->reserve($line, '60', $this->store->id);
        $leftover = $this->service()->reserve($line, '40', $this->store->id);

        // quantity_delivered still 0 — the delivery has not written it yet.
        $this->service()->consumeForDelivery($line->fresh(), '60', $this->fg->id);

        $this->assertSame(StockReservationStatus::Consumed, $spent->fresh()->status);
        $this->assertSame(StockReservationStatus::Active, $leftover->fresh()->status);
    }

    public function test_cancelling_an_order_gives_every_hold_back(): void
    {
        $this->seedStock('500');
        $line = $this->line('100');
        $order = $line->salesOrder;
        $hold = $this->service()->reserve($line, '100', $this->store->id);

        $released = $this->service()->releaseForOrder($order, 'so_cancelled', $this->store->id);

        $this->assertSame(1, $released);
        $this->assertSame(StockReservationStatus::Released, $hold->fresh()->status);
        $this->assertSame('so_cancelled', $hold->fresh()->released_reason);
        $this->assertSame('500.0000', app(AvailabilityService::class)->forItem($this->bottle->id)['free']);
    }

    /**
     * THE LOCKS THAT KILL THE TWO RACES ARE PINNED ON THE BUILDERS. SQLite
     * drops FOR UPDATE, so a lock that only exists mid-statement can only be
     * asserted on the query itself (the conflictingPackagingQuery
     * precedent). These two are what serialise reserve/repoint against
     * cancel() and against send-to-production — were either to silently
     * lose its lock, both races would return with every other test green.
     */
    public function test_the_order_and_line_reads_carry_real_locks(): void
    {
        $this->assertTrue(StockReservationService::openOrderQuery(1)->toBase()->lock);
        $this->assertTrue(StockReservationService::lineQuery(1)->toBase()->lock);
    }

    public function test_a_cancelled_order_cannot_hold_stock(): void
    {
        $this->seedStock('100');
        $line = $this->line('50', SalesOrderStatus::Cancelled);

        $this->expectException(StockReservationException::class);

        $this->service()->reserve($line, '10', $this->store->id);
    }

    /**
     * A RE-POINTED HOLD NEVER CHANGES WAREHOUSE. The stock did not move, so
     * the hold must not: re-resolving the finished-goods setting at re-point
     * time would let an admin's later FG re-point silently re-home a hold
     * onto pieces that were never there — and S3's warehouse match would
     * then refuse to spend it against every real dispatch, forever.
     */
    public function test_a_repointed_hold_keeps_the_warehouse_the_stock_is_actually_in(): void
    {
        $this->seedStock('100');
        $source = $this->line('100');
        $target = $this->line('100');
        $hold = $this->service()->reserve($source, '40', $this->store->id);

        // The FG setting moves to a brand-new warehouse AFTER the hold was
        // taken — the documented admin operation the resolver exists for.
        $elsewhere = Warehouse::create(['code' => 'FG2', 'name' => 'New FG Store', 'tally_guid' => 'gd-fg2']);
        app(AppSettingService::class)->set(FactoryWarehouseResolver::SETTING_FINISHED_GOODS, $elsewhere->id);

        $moved = $this->service()->repoint($hold, $target->id, '40', 'store_call', $this->store->id);

        $this->assertSame($this->fg->id, (int) $moved->warehouse_id);
        $this->assertSame($this->fg->id, (int) $hold->fresh()->warehouse_id);
    }

    // ---- fixtures ---------------------------------------------------------

    private function service(): StockReservationService
    {
        return app(StockReservationService::class);
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

    private function line(
        string $quantity,
        SalesOrderStatus $status = SalesOrderStatus::Confirmed,
        ?Item $item = null,
    ): SalesOrderLine {
        $order = SalesOrder::create([
            'customer_id' => $this->customer->id,
            'status' => $status,
            'order_date' => '2026-08-20',
        ]);

        return $order->lines()->create([
            'item_id' => ($item ?? $this->bottle)->id,
            'quantity' => $quantity,
            'unit_price' => '4.50',
            'quantity_delivered' => '0',
        ]);
    }
}
