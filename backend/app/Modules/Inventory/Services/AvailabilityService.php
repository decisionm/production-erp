<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;

/**
 * WHAT THE SALES DESK AND THE STORE ARE ALLOWED TO PROMISE — per item, in
 * the finished-goods store, right now.
 *
 * Four figures and nothing else:
 *   on_hand       what the balance says is physically there
 *   reserved      what is still held for somebody's order line
 *   free          max(0, on_hand − reserved) — what may still be promised
 *   over_reserved max(0, reserved − on_hand) — S8
 *
 * OVER-RESERVATION IS SHOWN, NEVER HIDDEN (S8). Holds CAN exceed the
 * balance without anybody doing anything wrong: QC can net stock away after
 * it was held, and a delivery may legally consume against a line whose hold
 * sits elsewhere. Clamping `free` at zero and saying nothing would leave a
 * store wondering why a full shelf reserves nothing; printing the excess as
 * its own figure tells them exactly how many pieces are promised twice.
 *
 * NO COST FIELD, EVER (FC-06, S13). stock_balances carries `average_cost`
 * and this read deliberately builds its rows key by key rather than handing
 * the model out, so a future column on that table cannot leak a purchase
 * figure onto a screen the storekeeper is allowed to see.
 *
 * NOTHING HERE WRITES. No locks either — this is the read behind a screen,
 * and every figure that a WRITE depends on is recomputed inside
 * StockReservationService's transaction under the balance lock.
 */
class AvailabilityService
{
    public function __construct(
        // The FG warehouse and the held-quantity arithmetic both live in the
        // reservation service; this class only arranges them per item.
        private readonly StockReservationService $reservations,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @return list<array{item_id: int, on_hand: string, reserved: string, free: string, over_reserved: string}>
     */
    public function forItems(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return [];
        }

        $warehouse = $this->reservations->finishedGoodsWarehouse();

        // EXPLICIT COLUMNS, not the model: average_cost is on this table and
        // must not be within reach of the payload (FC-06).
        $onHand = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('item_id', $itemIds)
            ->pluck('quantity', 'item_id');

        // Folded in PHP with bcadd rather than SUM() in SQL: sqlite and
        // MySQL disagree about the type SUM() returns over a decimal, and
        // the suite runs both (DatabaseDriverParityTest).
        $reserved = [];
        StockReservation::query()
            ->active()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->each(function (StockReservation $hold) use (&$reserved) {
                $reserved[$hold->item_id] = bcadd(
                    $reserved[$hold->item_id] ?? '0.0000',
                    $hold->outstandingQuantity(),
                    4,
                );
            });

        $rows = [];

        foreach ($itemIds as $itemId) {
            // An ABSENT balance row is zero. Nothing is created to answer a
            // question.
            $held = $reserved[$itemId] ?? '0.0000';
            $stock = isset($onHand[$itemId]) ? bcadd((string) $onHand[$itemId], '0', 4) : '0.0000';
            $difference = bcsub($stock, $held, 4);

            $rows[] = [
                'item_id' => $itemId,
                'on_hand' => $stock,
                'reserved' => $held,
                // Clamped: a negative "free" is not a promise anybody can
                // make, and the size of the hole is reported separately.
                'free' => bccomp($difference, '0', 4) === 1 ? $difference : '0.0000',
                'over_reserved' => bccomp($difference, '0', 4) === -1
                    ? bcsub('0', $difference, 4)
                    : '0.0000',
            ];
        }

        return $rows;
    }

    /**
     * One item's four figures — the same row forItems() builds, for the
     * per-line reads on the fulfilment queue.
     *
     * @return array{item_id: int, on_hand: string, reserved: string, free: string, over_reserved: string}
     */
    public function forItem(int $itemId): array
    {
        return $this->forItems([$itemId])[0];
    }
}
