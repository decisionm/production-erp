<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\MaterialBagStatus;
use App\Modules\Inventory\Models\MaterialBag;
use App\Modules\Inventory\Models\StockReservation;

/**
 * WHAT A STOREKEEPER MAY ACTUALLY HAND OVER — the four figures behind one
 * stock row, read for a whole page at a time.
 *
 *   on_hand        what the balance says is physically there
 *   qa_hold        kilograms standing in incoming-QC hold (bags waiting_qc)
 *   reserved       what is still held for a customer's order line
 *   free_to_issue  max(0, on_hand − qa_hold − reserved)
 *
 * FREE_TO_ISSUE IS DELIBERATELY STRICTER THAN THE WRITE PATH, and this is the
 * one place where a screen and the engine disagree on purpose.
 * StockMovementService::decrementBalance consults the QC hold and NOT
 * reservations — so the system will let a storekeeper issue stock that is
 * promised to a customer. The owner's ruling (31-Aug-2026) is that the
 * screen's headline must subtract both: a storekeeper who means to break a
 * reservation can still do it, but they will not do it by accident on a busy
 * morning. Under-reporting is the safe direction; the components are printed
 * beside it so nothing is hidden.
 *
 * NOTHING HERE LOCKS, AND NOTHING HERE WRITES. This is the read behind a
 * screen. Every figure a WRITE depends on is recomputed inside the writer's
 * own transaction under the balance lock — IncomingQcHold::lockAndSum for the
 * hold, StockReservationService for the holds. Those remain the authority;
 * this class must never become one.
 *
 * WHY THE HOLD IS FOLDED IN PHP RATHER THAN GROUPED IN SQL. A bag with no
 * store recorded against it counts against EVERY store, because nothing says
 * which one it is in (IncomingQcHold's own rule, and its fail-closed reason).
 * A `GROUP BY current_warehouse_id` would drop those bags into a null bucket
 * and return a SMALLER hold than the write path for the same pair — which
 * fails OPEN, in exactly the direction the hold exists to prevent. So the
 * bags are fetched once and folded per pair here, with a store-less bag added
 * to every warehouse on the page for its item.
 *
 * bcmath throughout, never a SQL SUM(): sqlite and MySQL disagree about the
 * type SUM() returns over a decimal and the suite runs both, and a float has
 * no business near a quantity that decides whether material may move.
 *
 * NO COST FIELD, EVER (FC-06). The rows are built key by key from quantities
 * only, so a future column on stock_balances cannot leak a purchase figure
 * onto a screen the storekeeper is allowed to see.
 */
class StockStateReader
{
    /**
     * The four figures for every (item, warehouse) pair on a page.
     *
     * @param  list<array{item_id: int, warehouse_id: int, quantity: string}>  $rows
     * @return array<string, array{on_hand: string, qa_hold: string, reserved: string, free_to_issue: string}>
     *                                                                                                         keyed "itemId|warehouseId"
     */
    public function forRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $itemIds = array_values(array_unique(array_map(fn ($row) => (int) $row['item_id'], $rows)));
        $warehouseIds = array_values(array_unique(array_map(fn ($row) => (int) $row['warehouse_id'], $rows)));

        $held = $this->heldForDisplayNoLock($itemIds, $warehouseIds);
        $reserved = $this->reservedForPairs($itemIds, $warehouseIds);

        $out = [];

        foreach ($rows as $row) {
            $key = ((int) $row['item_id']).'|'.((int) $row['warehouse_id']);
            $onHand = bcadd((string) $row['quantity'], '0', 4);
            $qaHold = $held[$key] ?? '0.0000';
            $reservedQty = $reserved[$key] ?? '0.0000';

            $free = bcsub(bcsub($onHand, $qaHold, 4), $reservedQty, 4);

            $out[$key] = [
                'on_hand' => $onHand,
                'qa_hold' => $qaHold,
                'reserved' => $reservedQty,
                // Never below zero. A hold larger than the balance means
                // kilograms already left that should not have, and the honest
                // answer to "how much may go" is then none — the same
                // fail-closed clamp IncomingQcHold::available applies.
                'free_to_issue' => bccomp($free, '0', 4) === -1 ? '0.0000' : $free,
            ];
        }

        return $out;
    }

    /**
     * Incoming-QC hold per pair, WITHOUT a lock — the display twin of
     * IncomingQcHold::lockAndSum, named so it can never be mistaken for it on
     * a write path.
     *
     * The predicate is deliberately identical to the locking version: bags
     * whose lot is one of these items, status waiting_qc, in one of these
     * warehouses OR with no warehouse recorded at all.
     *
     * @param  list<int>  $itemIds
     * @param  list<int>  $warehouseIds
     * @return array<string, string>
     */
    private function heldForDisplayNoLock(array $itemIds, array $warehouseIds): array
    {
        $bags = MaterialBag::query()
            ->join('material_lots', 'material_bags.material_lot_id', '=', 'material_lots.id')
            ->whereIn('material_lots.item_id', $itemIds)
            ->where('material_bags.status', MaterialBagStatus::WaitingQc->value)
            ->where(fn ($query) => $query
                ->whereIn('material_bags.current_warehouse_id', $warehouseIds)
                ->orWhereNull('material_bags.current_warehouse_id'))
            ->get([
                'material_bags.remaining_kg',
                'material_bags.current_warehouse_id',
                'material_lots.item_id',
            ]);

        $held = [];

        foreach ($bags as $bag) {
            $itemId = (int) $bag->item_id;
            $kg = (string) $bag->remaining_kg;

            // A bag with no store recorded is held against EVERY store on the
            // page for its item — the fail-closed reading, and the reason this
            // fold is not a GROUP BY.
            $targets = $bag->current_warehouse_id === null
                ? $warehouseIds
                : [(int) $bag->current_warehouse_id];

            foreach ($targets as $warehouseId) {
                $key = $itemId.'|'.$warehouseId;
                $held[$key] = bcadd($held[$key] ?? '0.0000', $kg, 4);
            }
        }

        return $held;
    }

    /**
     * Outstanding customer holds per pair — quantity less what a delivery has
     * consumed and less what has been released, the same arithmetic
     * StockReservation::outstandingQuantity applies per row.
     *
     * @param  list<int>  $itemIds
     * @param  list<int>  $warehouseIds
     * @return array<string, string>
     */
    private function reservedForPairs(array $itemIds, array $warehouseIds): array
    {
        $reserved = [];

        StockReservation::query()
            ->active()
            ->whereIn('item_id', $itemIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->each(function (StockReservation $hold) use (&$reserved) {
                $key = ((int) $hold->item_id).'|'.((int) $hold->warehouse_id);
                $reserved[$key] = bcadd($reserved[$key] ?? '0.0000', $hold->outstandingQuantity(), 4);
            });

        return $reserved;
    }
}
