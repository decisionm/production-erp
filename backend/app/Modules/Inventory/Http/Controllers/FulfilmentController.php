<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListFulfilmentQueueRequest;
use App\Modules\Inventory\Http\Requests\ReserveStockRequest;
use App\Modules\Inventory\Http\Requests\SendToProductionRequest;
use App\Modules\Inventory\Http\Resources\FulfilmentQueueRowResource;
use App\Modules\Inventory\Http\Resources\StockReservationResource;
use App\Modules\Inventory\Services\FulfilmentQueueService;
use App\Modules\Inventory\Services\StockReservationService;
use App\Modules\Production\Http\Resources\ProductionRequestResource;
use App\Modules\Production\Services\FulfilmentPlanningService;
use App\Modules\Production\Services\ProductionRequestService;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * THE STORE'S FULFILMENT DESK — the queue of order lines waiting on stock,
 * the two things the store can do about a line (hold what is there, ask the
 * floor for what is not), and the planning read behind the ETA dashboard.
 *
 * Thin by the module pattern: the validation is in the FormRequests, every
 * rule and every refusal is in the services, the shape is in the Resources.
 * Nothing here queries a model or decides anything.
 *
 * NOTHING ON THIS CONTROLLER MOVES STOCK (invariant 1). Reserving, releasing
 * and re-pointing change who stock is spoken for and nothing else; only a
 * Delivery moves it. And nothing here creates, starts or cancels a batch
 * (invariant 2) — sending a line to production writes a piece of paper.
 *
 * The line-scoped actions take a {sales_order_line} through implicit
 * binding, so a line that does not exist is a 404 before any service runs.
 */
class FulfilmentController extends Controller
{
    public function __construct(
        private readonly FulfilmentQueueService $queue,
        private readonly StockReservationService $reservations,
        // Cross-module, through Production's own services — never its
        // tables. The same seam SalesCostInsightService uses.
        private readonly ProductionRequestService $productionRequests,
    ) {}

    /**
     * THE QUEUE. Over-reserved lines first, fully allocated ones hidden
     * unless asked for by name (S8, S16 — see the service).
     */
    public function queue(ListFulfilmentQueueRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return FulfilmentQueueRowResource::collection($this->queue->queue(
            $filters,
            (int) ($filters['per_page'] ?? FulfilmentQueueService::PER_PAGE_DEFAULT),
            (int) ($filters['page'] ?? 1),
        ));
    }

    /**
     * WHEN THE FACTORY COULD HAVE IT — every open production request with
     * its ETA or its refusal, the basis those numbers were computed on, and
     * what the floor should be working on today.
     *
     * A bare object rather than a resource collection, like
     * /sales/tally-mirror and /inventory/production-floor-stock: the payload
     * is three things at once (rows, a basis, today's targets), not a list
     * of one kind of row, and the service already shapes it whole.
     *
     * NO ETA IS STORED ANYWHERE (S11) — this read computes them and they are
     * gone again, because a saved date is wrong the moment somebody reorders
     * the queue.
     */
    public function planning(FulfilmentPlanningService $planning): JsonResponse
    {
        return response()->json($planning->plan());
    }

    /** HOLD free finished goods for this line. Moves no stock. */
    public function reserve(ReserveStockRequest $request, SalesOrderLine $salesOrderLine): JsonResponse
    {
        $reservation = $this->reservations->reserve(
            $salesOrderLine,
            (string) $request->validated()['quantity'],
            $request->user()?->id,
        );

        return StockReservationResource::make($reservation)->response()->setStatusCode(201);
    }

    /**
     * ASK THE FLOOR for what the store cannot cover. Creates a piece of
     * paper, never a batch (invariant 2), and the quantity is capped at the
     * line's real shortfall inside the service (S14).
     */
    public function sendToProduction(SendToProductionRequest $request, SalesOrderLine $salesOrderLine): JsonResponse
    {
        $created = $this->productionRequests->createFromShortfall(
            $salesOrderLine,
            (string) $request->validated()['quantity'],
            $request->user()?->id,
        );

        return ProductionRequestResource::make($created)->response()->setStatusCode(201);
    }
}
