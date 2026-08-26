<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CancelProductionRequestRequest;
use App\Modules\Production\Http\Requests\ReorderProductionRequestsRequest;
use App\Modules\Production\Http\Resources\ProductionRequestResource;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Services\ProductionRequestService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * THE FLOOR'S WORKLIST — what the store could not cover out of finished
 * goods, in the order the factory should make it.
 *
 * Thin by the module pattern: no validation and no Eloquent here, the rules
 * live in ProductionRequestService and the shape in the Resource.
 *
 * A TWO-SIDED DOCUMENT (P3), gated exactly like the material request it is
 * modelled on: the queue is READ by both desks (`module:production,inventory`
 * — the store raised these and needs to see what the floor is doing with
 * them), the queue's ORDER and picking a job up are the FLOOR's alone
 * (`module:production`), and CANCELLING is either side's. The wall is in
 * routes/api.php, where a route group can express it once for every route
 * inside it, rather than re-asserted here.
 *
 * NO BATCH IS CREATED, STARTED OR CANCELLED BY ANY ACTION HERE (invariant
 * 2). `start` records that a person picked the job up. People start batches.
 *
 * Append-only: every lifecycle step is a POST, there is no PUT and no
 * DELETE. A cancelled request keeps its row and its reason.
 */
class ProductionRequestController extends Controller
{
    public function __construct(private readonly ProductionRequestService $requests) {}

    /**
     * The queue in priority order — everything still owed.
     *
     * Deliberately NOT paginated: it is a worklist a person reorders by
     * dragging rows against each other, and reorder() renumbers the WHOLE
     * queue in one call. A page of it would let somebody reorder a queue
     * they cannot see all of.
     */
    public function index(): AnonymousResourceCollection
    {
        return ProductionRequestResource::collection($this->requests->queue());
    }

    /** The whole queue's new order — the floor's call (production.manage). */
    public function reorder(ReorderProductionRequestsRequest $request): AnonymousResourceCollection
    {
        return ProductionRequestResource::collection(
            $this->requests->reorder($request->validated()['ordered_ids']),
        );
    }

    /** Somebody on the floor picked the job up — the floor's call (production.manage). */
    public function start(ProductionRequest $productionRequest): ProductionRequestResource
    {
        return ProductionRequestResource::make($this->requests->start($productionRequest));
    }

    /** Withdrawn, with a reason — EITHER side may do it (the OR-gate group). */
    public function cancel(
        CancelProductionRequestRequest $request,
        ProductionRequest $productionRequest,
    ): ProductionRequestResource {
        return ProductionRequestResource::make(
            $this->requests->cancel($productionRequest, $request->validated()['reason']),
        );
    }
}
