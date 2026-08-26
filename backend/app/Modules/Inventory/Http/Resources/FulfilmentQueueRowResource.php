<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the STORE FULFILMENT QUEUE — a sales order line, everything
 * known about how it will be met, and what the store may do about it.
 *
 *   ordered / delivered / reserved / shortfall   the LINE's figures
 *   free / over_reserved                         the ITEM's figures in the
 *                                                finished-goods store, which
 *                                                is why this line is where
 *                                                it is (S8: an over-promise
 *                                                is printed, never hidden)
 *   fulfilment_state   untouched | partially_allocated | awaiting_production
 *                      | over_reserved | fully_allocated — computed by
 *                      FulfilmentQueueService, never by a screen
 *   holds              "held for {customer} since {date}", oldest first
 *   request            the open production request on this line, or null
 *   can                {reserve, release, repoint, send_to_production} — the
 *                      SAME predicates StockReservationService and
 *                      ProductionRequestService refuse on
 *
 * The row arrives already shaped (the service computes state and abilities
 * in one place so the write and the screen cannot disagree); this class is
 * the wire contract, pinning key order and the fact that every quantity
 * leaves as a 4dp decimal STRING like every other quantity on this API.
 *
 * FC-06: no rate, no cost, no amount. The sales order line carries a
 * unit_price and it is deliberately not read — this is the store's screen.
 */
class FulfilmentQueueRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'line_id' => $row['line_id'],
            'sales_order_id' => $row['sales_order_id'],
            'customer' => $row['customer'],
            'item' => $row['item'],

            'ordered' => $row['ordered'],
            'delivered' => $row['delivered'],
            'reserved' => $row['reserved'],
            'shortfall' => $row['shortfall'],
            'free' => $row['free'],
            'over_reserved' => $row['over_reserved'],

            'fulfilment_state' => $row['fulfilment_state'],
            'holds' => $row['holds'],
            'request' => $row['request'],
            'can' => $row['can'],
        ];
    }
}
