<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Quality\Http\Requests\ListBatchQualityQueueRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The quality queue's READ: the completed batches still waiting for their
 * check, oldest first, one page at a time.
 *
 * UNTIL NOW THERE WAS NO SUCH ENDPOINT. The Production QC screen walked
 * every page of the production list's `status=pending`, kept the rows whose
 * `quality` block said unchecked-and-gated and whose `correction` block
 * said not-sent-back, and re-sorted them oldest first in the browser — so
 * a queue of two hundred batches was two hundred rows in memory with no
 * pager and no search. This asks the database the same three questions
 * (ShiftProductionEntryService::paginate, `awaitingQualityCheck`) and cuts
 * the page after them, so the page and its total are the queue's.
 *
 * SAME BOUNDARY AS THE TWO WRITES beside it (BatchQualityCheckController,
 * BatchReturnToProductionController): Quality owns the route and the
 * request, the query runs in Production's own service, and the rows are
 * Production's resource, because what the desk reads IS the batch.
 *
 * Two facts ride in `meta` beside the page because the screen has to say
 * them and no row can: whether the stage is switched on at all, and — only
 * while it is off — how many pending batches are going straight to the
 * Plant Manager, so "nothing to check" and "this screen is not in use" do
 * not look the same. `pending_count`'s own paginate() call deliberately does
 * NOT receive `returned` — it answers a different question (how many are
 * going straight to the PM while the stage is off), not the queue's own
 * membership.
 *
 * `returned` (03-Sep-2026, Task 2 of "Returned by Quality") narrows the same
 * queue to batches that carry a quality return — see
 * ListBatchQualityQueueRequest for what that can and cannot surface.
 */
class BatchQualityQueueController extends Controller
{
    public function __construct(private readonly ShiftProductionEntryService $entries) {}

    public function __invoke(ListBatchQualityQueueRequest $request): AnonymousResourceCollection
    {
        $stageEnabled = (bool) config('production.approvals.quality_stage_enabled', true);

        $page = $this->entries->paginate(
            perPage: $request->perPage(),
            status: ShiftProductionEntryStatus::Pending,
            awaitingQualityCheck: true,
            q: $request->term(),
            oldestFirst: true,
            sort: $request->sort(),
            returned: $request->returnedOnly(),
        );

        return ShiftProductionEntryResource::collection($page)->additional(['meta' => [
            'stage_enabled' => $stageEnabled,
            'pending_count' => $stageEnabled
                ? null
                : $this->entries->paginate(perPage: 1, status: ShiftProductionEntryStatus::Pending)->total(),
        ]]);
    }
}
