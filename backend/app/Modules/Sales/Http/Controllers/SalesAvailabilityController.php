<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\AvailabilityService;
use App\Modules\Sales\Http\Requests\AvailabilityQueryRequest;
use App\Modules\Sales\Http\Resources\ItemAvailabilityResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /sales/availability — WHAT THE SALES DESK MAY PROMISE, per item, as
 * the order is being typed.
 *
 * On the SALES surface (`module:sales`) although every figure is Inventory's,
 * and that is the point: the desk holds no inventory permission and must not
 * need one to see whether it can promise a delivery. The figures are read
 * through Inventory's own AvailabilityService — cross-module injection, never
 * a reach into its tables (the same seam SalesCostInsightService uses).
 *
 * READ-ONLY, AND IT PROMISES NOTHING. Seeing free stock does not hold it; a
 * hold is the store's act, on the fulfilment queue. Two desks reading the
 * same figure a second apart can both be told 500 are free, and the first one
 * to reserve gets them — StockReservationService recomputes under a lock and
 * refuses the second with the real number.
 *
 * FC-06: four quantity keys, no cost of any kind.
 */
class SalesAvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(AvailabilityQueryRequest $request): AnonymousResourceCollection
    {
        return ItemAvailabilityResource::collection(
            $this->availability->forItems($request->validated()['item_ids']),
        );
    }
}
