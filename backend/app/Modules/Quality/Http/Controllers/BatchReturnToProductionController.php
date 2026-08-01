<?php

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftProductionEntryService;
use App\Modules\Quality\Http\Requests\ReturnBatchToProductionRequest;

/**
 * The quality queue's second write, and the counterpart of the first: send a
 * completed batch back to the floor with the reason it cannot be certified.
 *
 * SAME BOUNDARY AS THE CHECK, for the same reasons — see
 * BatchQualityCheckController. The route and its permission (quality.manage)
 * belong to the quality desk, because the desk performs the action and a QC
 * checker holds no production permission; the mutation goes through
 * Production's own service, because what changes is a production entry, its
 * stock movements and its place in the chain. The response is Production's
 * resource, so the screen reads the same shape whichever stage wrote it last.
 */
class BatchReturnToProductionController extends Controller
{
    public function __construct(private readonly ShiftProductionEntryService $entries) {}

    public function __invoke(
        ReturnBatchToProductionRequest $request,
        ShiftProductionEntry $shiftProductionEntry,
    ): ShiftProductionEntryResource {
        return ShiftProductionEntryResource::make(
            $this->entries->returnToProduction(
                $shiftProductionEntry,
                trim((string) $request->validated('reason')),
                $request->user()?->id,
            ),
        );
    }
}
