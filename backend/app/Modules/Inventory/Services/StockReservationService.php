<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\StockReservationException;
use App\Modules\Inventory\Models\Enums\StockReservationStatus;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HOLDING FINISHED GOODS FOR A CUSTOMER'S ORDER LINE — and giving the hold
 * up, moving it to another line, and spending it on a delivery.
 *
 * NOTHING HERE MOVES STOCK (invariant 1). Not one method writes a
 * stock_movement, decrements a stock_balance or posts a Tally voucher. The
 * balance is READ, under a lock, and put back exactly as it was found. Only
 * a Delivery moves stock, and when it does, this class is the thing that
 * SPENDS the hold the delivery was made against — never the thing that
 * caused the movement.
 *
 * THE GLOBAL LOCK ORDER, and it is global on purpose — every path in this
 * build that takes more than one lock takes them in this sequence, so two
 * paths can never hold each other's next lock:
 *
 *     sales_orders / sales_order_lines
 *       → material_bags
 *         → stock_balances
 *           → stock_reservations
 *             → production_requests
 *
 * (The brief pins the middle four; the sales rows are at the head because
 * every writer that creates demand-side rows ACQUIRES them there: reserve(),
 * repoint() and createFromShortfall() each lock the parent sales_orders row
 * first and re-check its status under that lock, then the sales_order_lines
 * row, before any balance is touched. SalesOrderService::cancel holds the
 * same order-row lock while it releases holds and withdraws requests — so a
 * hold can never be created in the same instant its order is cancelled and
 * then survive as an orphan nothing can ever release, and reserve and
 * send-to-production can never both serve the same line from stale reads.
 * Nothing in this build ever wants a sales row AFTER a balance.)
 *
 * THE BALANCE LOCK IS READ-ONLY AND IS TAKEN BY HAND, never through
 * StockMovementService::lockBalance(): that method CREATES the row when it
 * is missing, and a reservation path that creates stock_balances rows would
 * quietly manufacture "the factory holds this item here" out of somebody
 * asking whether it does. An ABSENT ROW IS ZERO and refuses, which is the
 * same answer a zero row gives. StockMovementService is not edited by this
 * build at all (PR #31).
 *
 * FC-06: no rate, no cost, no supplier is read or returned anywhere here.
 */
class StockReservationService
{
    public function __construct(
        // WHERE FINISHED GOODS ARE (P1). Cross-module injection of another
        // module's service, exactly as SalesCostInsightService does it —
        // never a reach into Production's tables.
        private readonly FactoryWarehouseResolver $warehouses,
    ) {}

    // ---- reads ------------------------------------------------------------

    /**
     * WHAT IS STILL HELD of an item in a warehouse — the availability
     * figure, summed over ACTIVE holds only.
     *
     * outstandingQuantity(), not "quantity − consumed": a partially
     * re-pointed hold stays active carrying a released_quantity, and
     * counting its full quantity would count the re-pointed pieces twice
     * (once on the source row, once on the target row) and invent an
     * over-reservation out of a move that changed nothing.
     *
     * FOLDED IN PHP WITH bcadd, NOT SUM() IN SQL. The suite runs on sqlite
     * and on MySQL (DatabaseDriverParityTest) and the two disagree about
     * what SUM() over a decimal returns — a string on one, a float on the
     * other. Every quantity in this ERP is decimal arithmetic end to end.
     */
    public function reservedForItem(int $itemId, int $warehouseId): string
    {
        return $this->foldOutstanding(
            StockReservation::query()
                ->active()
                ->where('item_id', $itemId)
                ->where('warehouse_id', $warehouseId)
                ->get()
        );
    }

    /**
     * WHAT THIS LINE IS STILL HOLDING — the figure the demand cap (S5) and
     * the coverage test (S1) are both judged against.
     */
    public function heldOnLine(int $salesOrderLineId): string
    {
        return $this->foldOutstanding(
            StockReservation::query()
                ->active()
                ->where('sales_order_line_id', $salesOrderLineId)
                ->get()
        );
    }

    /**
     * The line's live holds, oldest first — what the fulfilment queue row
     * prints as "held for {customer} since {date}".
     *
     * @return Collection<int, StockReservation>
     */
    public function activeForLine(int $salesOrderLineId): Collection
    {
        return StockReservation::query()
            ->active()
            ->where('sales_order_line_id', $salesOrderLineId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The live holds of a WHOLE PAGE of lines, in one query — keyed by
     * sales_order_line_id, oldest hold first inside each line.
     *
     * The same answer activeForLine() gives, for the fulfilment queue, which
     * prints a line's holds on every row and would otherwise take a query
     * per row. Lines holding nothing are absent from the map rather than
     * present with an empty list — an absent key is zero, exactly as an
     * absent balance row is.
     *
     * @param  list<int>  $salesOrderLineIds
     * @return array<int, Collection<int, StockReservation>>
     */
    public function activeForLines(array $salesOrderLineIds): array
    {
        if ($salesOrderLineIds === []) {
            return [];
        }

        return StockReservation::query()
            ->active()
            ->whereIn('sales_order_line_id', $salesOrderLineIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (StockReservation $hold) => (int) $hold->sales_order_line_id)
            ->all();
    }

    /** The finished-goods warehouse every hold in this build is taken against. */
    public function finishedGoodsWarehouse(): Warehouse
    {
        // OrFail, not the nullable read: FactoryWarehouseResolver's own rule
        // is that null is never papered over. An unresolved FG warehouse
        // answered as "nothing free" would read as an empty store rather
        // than as an unconfigured one, and the store would send perfectly
        // available stock to production.
        return $this->warehouses->finishedGoodsOrFail('warehouse_id');
    }

    // ---- writes -----------------------------------------------------------

    /**
     * HOLD $quantity of the line's item for that line.
     *
     * Every figure it refuses on is recomputed INSIDE the transaction under
     * the balance lock — the screen the storekeeper is looking at was true a
     * moment ago, and somebody else may have taken the stock since.
     */
    public function reserve(SalesOrderLine $line, string $quantity, ?int $userId): StockReservation
    {
        $this->guardPositive($quantity);

        return DB::transaction(function () use ($line, $quantity, $userId) {
            // HEAD OF THE GLOBAL LOCK ORDER: the parent order row, then the
            // line row. The order lock serialises this against cancel() —
            // a status read taken without it can be true when read and
            // cancelled by the time this commits, leaving a hold no screen
            // ever shows again. The line lock serialises the demand cap
            // against send-to-production, which recomputes its shortfall
            // under the same lock.
            $order = $this->lockOpenOrder((int) $line->sales_order_id);
            $line = $this->lockedLine($line->getKey(), $order);

            $warehouse = $this->finishedGoodsWarehouse();

            // LOCK ORDER: stock_balances before stock_reservations.
            $balance = $this->lockBalanceReadOnly($line->item_id, $warehouse->id);

            $hold = $this->hold($line, $quantity, $warehouse, $balance, $userId);

            // A line the store just covered has nothing left for the floor
            // to make: an open request flips to produced HERE, not only at
            // delivery — otherwise stock arriving after a send-to-production
            // leaves a ghost request on the floor's worklist. LAST lock in
            // the order (production_requests), same as consumeForDelivery.
            app(ProductionRequestService::class)->markProducedIfCovered($line);

            return $hold;
        });
    }

    /**
     * GIVE A HOLD UP. The stock stays exactly where it is; it simply stops
     * being spoken for.
     *
     * A FULLY CONSUMED HOLD REFUSES — the pieces left the building against
     * it, and pretending otherwise would claim the delivery never happened.
     * A PARTIALLY consumed hold releases its remainder, which is the
     * ordinary end of a delivered line.
     */
    public function release(StockReservation $reservation, string $reason, ?int $userId): StockReservation
    {
        return DB::transaction(function () use ($reservation, $reason, $userId) {
            $locked = $this->lockReservation($reservation->getKey());

            if (! $locked->status->isActive()) {
                throw StockReservationException::cannotRelease($locked);
            }

            $this->giveUp($locked, $locked->outstandingQuantity(), $reason, $userId);

            return $locked;
        });
    }

    /**
     * MOVE A HOLD (or part of one) TO ANOTHER LINE — S4.
     *
     * ONE TRANSACTION UNDER ONE BALANCE LOCK, and the release is written
     * BEFORE the new hold is judged. That ordering is the whole point: the
     * pieces being moved are already counted as reserved, so a target-side
     * free-stock check run before the source was given up would refuse every
     * re-point in a fully-held store — the exact case re-pointing exists
     * for. Releasing first inside the same lock means the recomputed `free`
     * genuinely includes the pieces in flight, and nobody else can see the
     * moment in between.
     */
    public function repoint(
        StockReservation $reservation,
        int $targetLineId,
        string $quantity,
        string $reason,
        ?int $userId,
    ): StockReservation {
        $this->guardPositive($quantity);

        return DB::transaction(function () use ($reservation, $targetLineId, $quantity, $reason, $userId) {
            // HEAD OF THE GLOBAL LOCK ORDER, exactly as reserve():
            // the TARGET's order row, then the target line row.
            $peek = SalesOrderLine::query()->whereKey($targetLineId)->firstOrFail();
            $order = $this->lockOpenOrder((int) $peek->sales_order_id);
            $target = $this->lockedLine($targetLineId, $order);

            // THE HOLD MOVES BETWEEN LINES, NEVER BETWEEN WAREHOUSES. The
            // stock never moved, so the new hold keeps the SOURCE hold's own
            // warehouse — re-resolving finished-goods here would let an
            // admin's later FG re-point silently re-home a hold onto pieces
            // that were never there, and S3's warehouse match would then
            // refuse to spend it forever. warehouse_id is immutable on a
            // reservation row, so the unlocked peek cannot go stale.
            $sourceWarehouseId = (int) StockReservation::query()
                ->whereKey($reservation->getKey())
                ->value('warehouse_id');
            $warehouse = Warehouse::query()->findOrFail($sourceWarehouseId);

            // LOCK ORDER: the balance first, then both reservation rows.
            $balance = $this->lockBalanceReadOnly($target->item_id, $warehouse->id);

            $source = $this->lockReservation($reservation->getKey());

            if (! $source->status->isActive()) {
                throw StockReservationException::cannotRelease($source);
            }

            if ((int) $source->sales_order_line_id === (int) $target->id) {
                throw StockReservationException::cannotRepointToSameLine($source);
            }

            if ((int) $source->item_id !== (int) $target->item_id) {
                throw StockReservationException::repointItemMismatch(
                    (string) ($source->item?->name ?? "item #{$source->item_id}"),
                    (string) ($target->item?->name ?? "item #{$target->item_id}"),
                );
            }

            $outstanding = $source->outstandingQuantity();

            if (bccomp($quantity, $outstanding, 4) === 1) {
                throw StockReservationException::repointExceedsHold($outstanding, $quantity);
            }

            $this->giveUp($source, $quantity, $reason, $userId);

            $hold = $this->hold($target, $quantity, $warehouse, $balance, $userId);

            // Same tail as reserve(): a target line this move just covered
            // stops asking the floor for pieces. One-way on purpose — the
            // SOURCE line losing its hold never un-produces anything.
            app(ProductionRequestService::class)->markProducedIfCovered($target);

            return $hold;
        });
    }

    /**
     * SPEND HOLDS AGAINST A DELIVERY — called from INSIDE DeliveryService's
     * existing transaction, never on its own.
     *
     * CALL IT AFTER the delivery has incremented the line's
     * quantity_delivered: the "is this line finished?" test below reads the
     * line back fresh and judges on the stored figure, so calling it earlier
     * would leave the line's leftover holds standing.
     *
     * WAREHOUSE-MATCHED, AND A MISMATCH IS A SILENT NO-OP (S3). A delivery
     * dispatched from a warehouse this line holds nothing in has simply not
     * spent any hold — it is a legal thing to do (the holds are in the FG
     * store, the van loaded from somewhere else) and refusing it would block
     * a real dispatch over paperwork. Oldest hold first, so the queue's
     * "held since" ages out in the order a person would expect.
     *
     * NO STOCK MOVES HERE. The delivery already moved it, through
     * StockMovementService, before this was called.
     */
    public function consumeForDelivery(SalesOrderLine $line, string $quantity, int $warehouseId): void
    {
        // LOCK ORDER: stock_reservations, then production_requests (the
        // markProducedIfCovered call at the very end). The balance is NOT
        // locked here — the delivery that called us already holds it, and
        // taking it again from further down the order is how a deadlock is
        // built.
        $holds = StockReservation::query()
            ->active()
            ->where('sales_order_line_id', $line->getKey())
            ->where('warehouse_id', $warehouseId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = bcadd($quantity, '0', 4);

        foreach ($holds as $hold) {
            if (bccomp($remaining, '0', 4) !== 1) {
                break;
            }

            $outstanding = $hold->outstandingQuantity();
            $take = bccomp($outstanding, $remaining, 4) === 1 ? $remaining : $outstanding;

            $hold->consumed_quantity = bcadd((string) $hold->consumed_quantity, $take, 4);
            $this->applyStatus($hold);
            $hold->save();

            $remaining = bcsub($remaining, $take, 4);
        }

        // Anything left over is a delivery bigger than the holds behind it —
        // legal, and not this class's business. Nothing is invented to
        // absorb it.

        $fresh = SalesOrderLine::query()->findOrFail($line->getKey());

        if (bccomp((string) $fresh->quantity_delivered, (string) $fresh->quantity, 4) >= 0) {
            // The line is finished. Whatever it still holds is holding stock
            // away from orders that can still use it.
            foreach ($this->activeForLine((int) $fresh->id) as $leftover) {
                $this->giveUp($leftover, $leftover->outstandingQuantity(), 'line_fulfilled', null);
            }
        }

        // S9: the LAST lock this path takes, and the only reason
        // production_requests sits at the bottom of the global order.
        // Resolved lazily rather than injected because the two services
        // legitimately need each other (coverage is a reservation figure,
        // the produced flip is a request one) — the same escape
        // TallySyncService documents for its own cycle.
        app(ProductionRequestService::class)->markProducedIfCovered($fresh);
    }

    /**
     * EVERY HOLD ON A CANCELLED ORDER, GIVEN UP — S6. Called from inside
     * SalesOrderService::cancel's existing lockForUpdate transaction, so the
     * order row is already locked (head of the global order) and this only
     * adds the reservation locks.
     *
     * @return int how many holds were released
     */
    public function releaseForOrder(SalesOrder $order, string $reason, ?int $userId): int
    {
        $holds = StockReservation::query()
            ->active()
            ->whereIn(
                'sales_order_line_id',
                SalesOrderLine::query()->where('sales_order_id', $order->getKey())->select('id')
            )
            ->lockForUpdate()
            ->get();

        foreach ($holds as $hold) {
            $this->giveUp($hold, $hold->outstandingQuantity(), $reason, $userId);
        }

        return $holds->count();
    }

    // ---- internals --------------------------------------------------------

    /**
     * The hold itself, with every refusal recomputed under the caller's
     * balance lock. Shared by reserve() and the second half of repoint() so
     * a re-pointed hold can never be judged by a laxer rule than a fresh
     * one.
     */
    private function hold(
        SalesOrderLine $line,
        string $quantity,
        Warehouse $warehouse,
        ?StockBalance $balance,
        ?int $userId,
    ): StockReservation {
        // An ABSENT balance row is zero, not an error and not a row to
        // create.
        $onHand = $balance !== null ? (string) $balance->quantity : '0.0000';
        $reserved = $this->reservedForItem((int) $line->item_id, (int) $warehouse->id);
        $free = bcsub($onHand, $reserved, 4);

        if (bccomp($free, '0', 4) !== 1) {
            throw StockReservationException::nothingFree(
                (string) ($line->item?->name ?? "item #{$line->item_id}"),
                (string) $warehouse->name,
                bccomp($free, '0', 4) === -1 ? '0.0000' : $free,
            );
        }

        if (bccomp($quantity, $free, 4) === 1) {
            throw StockReservationException::notEnoughFree(
                (string) ($line->item?->name ?? "item #{$line->item_id}"),
                $free,
                $quantity,
            );
        }

        // S5, THE DEMAND CAP: a line may never hold more than it still owes
        // the customer.
        $remainingDemand = bcsub(
            bcsub((string) $line->quantity, (string) $line->quantity_delivered, 4),
            $this->heldOnLine((int) $line->id),
            4,
        );

        if (bccomp($quantity, $remainingDemand, 4) === 1) {
            throw StockReservationException::exceedsRemainingDemand(
                $this->lineLabel($line),
                bccomp($remainingDemand, '0', 4) === -1 ? '0.0000' : $remainingDemand,
                $quantity,
            );
        }

        return StockReservation::create([
            'item_id' => $line->item_id,
            'warehouse_id' => $warehouse->id,
            'sales_order_line_id' => $line->id,
            'quantity' => $quantity,
            'consumed_quantity' => '0.0000',
            'released_quantity' => '0.0000',
            'status' => StockReservationStatus::Active,
            'created_by' => $userId,
        ]);
    }

    /** Give up part (or all) of a hold, and maintain its status from the three quantities. */
    private function giveUp(StockReservation $reservation, string $quantity, string $reason, ?int $userId): void
    {
        if (bccomp($quantity, '0', 4) !== 1) {
            return;
        }

        $reservation->released_quantity = bcadd((string) $reservation->released_quantity, $quantity, 4);
        $reservation->released_reason = $reason;
        $reservation->released_by = $userId;
        $this->applyStatus($reservation);
        $reservation->save();
    }

    /**
     * THE STATUS IS MAINTAINED, NEVER CHOSEN — active while consumed +
     * released is short of the quantity, else consumed when anything at all
     * was consumed, else released. One implementation, called on every
     * write.
     */
    private function applyStatus(StockReservation $reservation): void
    {
        $settled = bcadd((string) $reservation->consumed_quantity, (string) $reservation->released_quantity, 4);

        $reservation->status = bccomp($settled, (string) $reservation->quantity, 4) === -1
            ? StockReservationStatus::Active
            : (bccomp((string) $reservation->consumed_quantity, '0', 4) === 1
                ? StockReservationStatus::Consumed
                : StockReservationStatus::Released);
    }

    /**
     * The balance row, LOCKED FOR READING ONLY, or null when the factory
     * holds none of this item here. Deliberately NOT
     * StockMovementService::lockBalance() — see the class docblock.
     */
    private function lockBalanceReadOnly(int $itemId, int $warehouseId): ?StockBalance
    {
        return StockBalance::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();
    }

    private function lockReservation(int $id): StockReservation
    {
        return StockReservation::query()
            ->whereKey($id)
            ->with('item:id,name,sku')
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The order row, LOCKED, its status judged UNDER the lock — the C1 fix.
     * In their own methods so the lock each carries is a thing a test can
     * pin: SQLite drops FOR UPDATE, so a lock that only ever exists
     * mid-statement cannot be asserted any other way (the
     * conflictingPackagingQuery precedent).
     *
     * @return Builder<SalesOrder>
     */
    public static function openOrderQuery(int $salesOrderId)
    {
        return SalesOrder::query()->whereKey($salesOrderId)->lockForUpdate();
    }

    /** @return Builder<SalesOrderLine> */
    public static function lineQuery(int $salesOrderLineId)
    {
        return SalesOrderLine::query()->whereKey($salesOrderLineId)->lockForUpdate();
    }

    /** Stock is only held for an order that is actually live — judged on the LOCKED row. */
    private function lockOpenOrder(int $salesOrderId): SalesOrder
    {
        $order = self::openOrderQuery($salesOrderId)->first();

        if ($order === null || ! in_array($order->status, [
            SalesOrderStatus::Confirmed,
            SalesOrderStatus::PartiallyDelivered,
        ], true)) {
            throw StockReservationException::orderNotOpen(
                $order?->documentNumber() ?? "order #{$salesOrderId}",
                $order?->status->value ?? 'missing',
            );
        }

        return $order;
    }

    /** The line row, LOCKED, with the display relations loaded beside the lock. */
    private function lockedLine(int $id, SalesOrder $order): SalesOrderLine
    {
        $line = self::lineQuery($id)->firstOrFail();
        $line->load('item:id,name,sku');
        $line->setRelation('salesOrder', $order);

        return $line;
    }

    private function guardPositive(string $quantity): void
    {
        if (bccomp($quantity, '0', 4) !== 1) {
            throw StockReservationException::quantityNotPositive($quantity);
        }
    }

    /** "SO-12 / 500ml PET Bottle" — what the storekeeper sees on the row. */
    private function lineLabel(SalesOrderLine $line): string
    {
        $order = $line->salesOrder?->documentNumber() ?? "line #{$line->id}";

        return $line->item?->name !== null ? "{$order} / {$line->item->name}" : $order;
    }

    /**
     * @param  Collection<int, StockReservation>  $reservations
     */
    private function foldOutstanding(Collection $reservations): string
    {
        $total = '0.0000';

        foreach ($reservations as $reservation) {
            $total = bcadd($total, $reservation->outstandingQuantity(), 4);
        }

        return $total;
    }
}
