<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Quality\Http\Requests\StoreBatchQualityCheckRequest;

/**
 * The quality queue's one write: the check a completed batch must pass before
 * the plant manager can approve it.
 *
 * IT LIVES IN QUALITY, AND IT WRITES THROUGH PRODUCTION. The endpoint belongs
 * to the quality desk — its permission is quality.manage, and a QC checker
 * who has no business in the production module must still be able to call it
 * — but the thing it changes is a production entry, along with that entry's
 * stock movements and its place in the approval chain. So the boundary is
 * kept the way this codebase keeps every other one: Quality owns the route,
 * the request and the validation; the mutation goes through Production's own
 * service, never through its models. The response is Production's resource,
 * because what the caller gets back IS the batch — the approval screen reads
 * the same shape whichever stage wrote it last.
 */
class BatchQualityCheckController extends Controller
{
    public function __construct(private readonly ShiftProductionEntryService $entries) {}

    public function __invoke(
        StoreBatchQualityCheckRequest $request,
        ShiftProductionEntry $shiftProductionEntry,
    ): ShiftProductionEntryResource {
        return ShiftProductionEntryResource::make(
            $this->entries->recordQualityCheck(
                $shiftProductionEntry,
                $request->validated(),
                $request->user()?->id,
            ),
        );
    }
}
