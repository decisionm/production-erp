<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\ProductionQueueResource;
use App\Modules\Production\Services\ProductionQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE PRODUCTION QUEUE — the floor's worklist with the demand behind it and
 * the date in front of it, in one read.
 *
 * Thin by the module pattern: no validation (it takes none — the queue is
 * deliberately unpaginated and unfiltered, like `/production/requests` it
 * sits beside) and no Eloquent. The join is ProductionQueueService's, the
 * rules are ProductionRequestService's and the dates are
 * FulfilmentPlanningService's.
 *
 * A BARE OBJECT rather than a returned resource collection, exactly as
 * `/inventory/fulfilment/planning`, `/sales/tally-mirror` and
 * `/inventory/production-floor-stock` do it: the payload is two things at
 * once (the rows, and the basis those dates stand on), not a list of one
 * kind of row. The ROWS still go through a resource —
 * ProductionQueueResource, resolved here rather than returned, so no
 * paginator envelope wraps the list and `data`/`basis` stay siblings.
 *
 * READ-ONLY, and there is no second verb on this controller. Nothing here
 * creates a request, creates or starts a batch (invariant 2) or moves stock
 * (invariant 1) — the acts that change the queue stay on
 * ProductionRequestController, where their permissions already are.
 *
 * OR-GATED `module:production,inventory` in routes/api.php with the rest of
 * the request queue (P3): the STORE raised these and the FLOOR runs them,
 * and neither desk can be asked to hold the other's permission to read the
 * one piece of paper they share.
 */
class ProductionQueueController extends Controller
{
    public function __construct(private readonly ProductionQueueService $queue) {}

    public function index(Request $request): JsonResponse
    {
        $queue = $this->queue->queue();

        return response()->json([
            'data' => ProductionQueueResource::collection($queue['data'])->toArray($request),
            'basis' => $queue['basis'],
        ]);
    }
}
