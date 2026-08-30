<?php

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the SALES FULFILMENT CONTROL VIEW — a sales order line and
 * everything every team needs to know about it, shaped once so Sales, Store,
 * Production, Quality and Accounts all read the SAME state.
 *
 * The row arrives already computed (FulfilmentControlService decides state and
 * blocker in one place); this class is the wire contract, pinning key order and
 * the fact that every quantity leaves as a 4dp decimal STRING like the rest of
 * this API.
 *
 * FOUR FIELDS CAN CARRY THE STRING 'not_recorded' INSTEAD OF A NUMBER —
 * `store.rejected`, `production.planned`, `production.completed`, and the
 * `state` of both `quality` and `customer_approval`. That is deliberate and is
 * the honest half of this view: those things have no source in this build, and
 * each carries a `_detail` sentence saying so. A client MUST render the words,
 * never coerce them to 0.
 *
 * FC-06: no rate, no cost, no amount. The line's unit_price is deliberately not
 * read — this is an operations screen, not a bill.
 */
class FulfilmentControlRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return [
            'line_id' => $row['line_id'],
            'sales_order_id' => $row['sales_order_id'],
            'order_status' => $row['order_status'],
            'customer' => $row['customer'],
            'item' => $row['item'],

            'ordered' => $row['ordered'],
            'delivered' => $row['delivered'],
            'invoiced' => $row['invoiced'],
            'available_stock' => $row['available_stock'],
            'held' => $row['held'],
            'over_reserved' => $row['over_reserved'],
            'shortfall' => $row['shortfall'],
            'dispatch_ready' => $row['dispatch_ready'],

            'store' => $row['store'],
            'production' => $row['production'],
            'quality' => $row['quality'],
            'customer_approval' => $row['customer_approval'],

            'expected_date' => $row['expected_date'],
            'blocker' => $row['blocker'],
        ];
    }
}
