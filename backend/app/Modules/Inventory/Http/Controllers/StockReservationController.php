<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ReleaseReservationRequest;
use App\Modules\Inventory\Http\Requests\RepointReservationRequest;
use App\Modules\Inventory\Http\Resources\StockReservationResource;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\StockReservationService;

/**
 * THE HOLD ITSELF — giving one up, and moving one to another order line.
 *
 * A sibling of FulfilmentController rather than more methods on it, because
 * these two act on a RESERVATION by its own id (the store clicks a hold on
 * the row), while reserving and sending to production act on an ORDER LINE.
 * Two nouns, two URL shapes, two controllers.
 *
 * NEITHER ACTION MOVES STOCK (invariant 1). A released hold leaves the
 * pieces exactly where they were; a re-pointed one changes whose name is on
 * them. Only a Delivery moves stock, and when it does it SPENDS the hold.
 *
 * Append-only in spirit: a hold is never deleted and never edited. It is
 * given up, with a reason, and keeps its row.
 */
class StockReservationController extends Controller
{
    public function __construct(private readonly StockReservationService $reservations) {}

    /** Give the hold up — the stock stays put and stops being spoken for. */
    public function release(ReleaseReservationRequest $request, StockReservation $reservation): StockReservationResource
    {
        return StockReservationResource::make($this->reservations->release(
            $reservation,
            $request->validated()['reason'],
            $request->user()?->id,
        ));
    }

    /**
     * Move the hold (or part of it) to another line — ONE transaction under
     * ONE balance lock, so the pieces in flight are never invisible and
     * never double-counted (S4).
     *
     * Returns the NEW hold on the target line: that is the row the store
     * needs an id for, and the source hold's remainder is on the queue read
     * the screen refreshes anyway.
     */
    public function repoint(RepointReservationRequest $request, StockReservation $reservation): StockReservationResource
    {
        $data = $request->validated();

        return StockReservationResource::make($this->reservations->repoint(
            $reservation,
            (int) $data['sales_order_line_id'],
            (string) $data['quantity'],
            $data['reason'],
            $request->user()?->id,
        ));
    }
}
