<?php

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE ITEM'S FOUR FIGURES, as the sales desk's per-line availability chip
 * reads them:
 *
 *   on_hand        what the finished-goods balance says is physically there
 *   reserved       what is still held for somebody's order line
 *   free           what may still be promised — max(0, on_hand − reserved)
 *   over_reserved  by how much the item is promised twice (S8), printed
 *                  rather than hidden: a clamped `free` alone would leave a
 *                  desk wondering why a full shelf promises nothing
 *
 * FOUR KEYS AND NO FIFTH (FC-06, S13). stock_balances carries an
 * average_cost and AvailabilityService deliberately builds its rows key by
 * key so a future column on that table cannot ride out here; this resource
 * is the second wall. A salesperson checking stock is not being shown what
 * the factory paid for it.
 */
class ItemAvailabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{item_id: int, on_hand: string, reserved: string, free: string, over_reserved: string} $row */
        $row = $this->resource;

        return [
            'item_id' => $row['item_id'],
            // 4dp decimal strings, like every other quantity on this API.
            'on_hand' => $row['on_hand'],
            'reserved' => $row['reserved'],
            'free' => $row['free'],
            'over_reserved' => $row['over_reserved'],
        ];
    }
}
