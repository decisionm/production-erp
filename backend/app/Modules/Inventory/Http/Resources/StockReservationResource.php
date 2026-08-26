<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One HOLD on finished goods, as reserve, release and re-point return it.
 *
 *   status              active | released | consumed — MAINTAINED by
 *                       StockReservationService from the three quantities,
 *                       never chosen by a caller and never inferred by a
 *                       screen.
 *   outstanding         what it is still holding away from other orders
 *                       (quantity − consumed − released). The figure the
 *                       availability read sums, and deliberately not the
 *                       same as `quantity`.
 *   released_reason     why the LAST give-up happened. A row that is still
 *                       active and carries one is normal: part of it was
 *                       re-pointed or handed back.
 *
 * A hold MOVES NO STOCK (invariant 1) — this shape therefore carries no
 * movement, no balance and no valuation, and FC-06 keeps a rate or a cost
 * off it for good measure.
 */
class StockReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StockReservation $reservation */
        $reservation = $this->resource;

        return [
            'id' => $reservation->id,
            'item_id' => (int) $reservation->item_id,
            'warehouse_id' => (int) $reservation->warehouse_id,
            'sales_order_line_id' => (int) $reservation->sales_order_line_id,

            // 4dp decimal strings, like every other quantity on this API.
            'quantity' => (string) $reservation->quantity,
            'consumed_quantity' => (string) $reservation->consumed_quantity,
            'released_quantity' => (string) $reservation->released_quantity,
            'outstanding_quantity' => $reservation->outstandingQuantity(),

            'status' => $reservation->status->value,
            'released_reason' => $reservation->released_reason,
            'created_by' => $reservation->created_by,
            'released_by' => $reservation->released_by,
            'created_at' => $reservation->created_at?->toIso8601String(),
        ];
    }
}
