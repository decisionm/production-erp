<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\AmendBatchRequest;
use App\Modules\Production\Http\Requests\CancelShiftProductionEntryRequest;
use App\Modules\Production\Http\Requests\CompleteBatchRequest;
use App\Modules\Production\Http\Requests\HandoverRequest;
use App\Modules\Production\Http\Requests\IngestShiftPageRequest;
use App\Modules\Production\Http\Requests\ListShiftProductionEntriesRequest;
use App\Modules\Production\Http\Requests\RejectShiftProductionEntryRequest;
use App\Modules\Production\Http\Requests\StartBatchRequest;
use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftPageEntryService;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShiftProductionEntryController extends Controller
{
    public function __construct(private readonly ShiftProductionEntryService $entries) {}

    /**
     * The one list, filtered — Completed Today, the approval queue and the
     * dashboard are all this endpoint with different query strings
     * (ListShiftProductionEntriesRequest names them). Every filter is
     * optional; bare, it answers exactly as it always has.
     */
    public function index(ListShiftProductionEntriesRequest $request): AnonymousResourceCollection
    {
        return ShiftProductionEntryResource::collection($this->entries->paginate(
            perPage: $request->perPage(),
            status: $request->status(),
            productionDate: $request->dayFilter('production_date'),
            dateFrom: $request->dayFilter('date_from'),
            dateTo: $request->dayFilter('date_to'),
            workCenterId: $request->idFilter('work_center_id'),
            shiftId: $request->idFilter('shift_id'),
            batchStatus: $request->batchStatus(),
        ));
    }

    /**
     * Every machine's currently-running batch, across ALL shifts and dates
     * and never paginated — the Shift Floor's machine state must match the
     * backend's one-in_progress-per-machine guard, which is global. A batch
     * left running from a past shift/date would otherwise show the machine
     * idle while Start Batch is (correctly) refused.
     */
    public function active(): AnonymousResourceCollection
    {
        return ShiftProductionEntryResource::collection($this->entries->activeBatches());
    }

    /**
     * Record a whole page of the factory's production report in one submit.
     *
     * The owner's priority (05-Aug): "the daily production entry, each page needs
     * to enter in our app." Ten to twelve machine rows, entered together, instead
     * of two dialogs each.
     *
     * ALWAYS 200, even when rows fail, and that is the contract rather than
     * laziness about status codes. A page is not one thing that either worked or
     * did not: eleven shifts genuinely happened and the twelfth genuinely could
     * not be recorded, and a 422 would throw away the eleven along with the
     * report of what went wrong with the twelfth. The body says per row which is
     * which; the caller renders it.
     */
    public function ingestPage(IngestShiftPageRequest $request, ShiftPageEntryService $pages): JsonResponse
    {
        return response()->json([
            'data' => $pages->ingest($request->validated(), $request->user()?->id),
        ]);
    }

    public function store(StartBatchRequest $request): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->startBatch($request->validated(), $request->user()?->id),
        );
    }

    public function complete(CompleteBatchRequest $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->completeBatch($shiftProductionEntry, $request->validated(), $request->user()?->id),
        );
    }

    /**
     * Correcting a completed batch that is still waiting for quality — the
     * floor's own fix to its own count. Same permission as completing it
     * (this group's module:production ⇒ production.manage), because it is the
     * same act by the same people; WHEN it is allowed to happen is a
     * transition rule and lives in the service with the rest of them.
     */
    public function amend(AmendBatchRequest $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->amendCompletion($shiftProductionEntry, $request->validated(), $request->user()?->id),
        );
    }

    /**
     * The three approval gates. Gated by ROLE (not just module permission):
     * each stage belongs to a specific desk, and Administrator can act at any
     * stage — the MD/Director accounts hold Administrator, and it also keeps
     * a small team unblocked when someone is away.
     */
    public function pmApprove(Request $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        abort_unless($request->user()->hasAnyRole(['Plant Manager', 'Administrator']), 403, 'Plant Manager approval requires the Plant Manager role.');

        return ShiftProductionEntryResource::make(
            $this->entries->pmApprove($shiftProductionEntry, $request->user()->id),
        );
    }

    public function accountantApprove(Request $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        abort_unless($request->user()->hasAnyRole(['Accounts', 'Administrator']), 403, 'Accountant approval requires the Accounts role.');

        return ShiftProductionEntryResource::make(
            $this->entries->accountantApprove($shiftProductionEntry, $request->user()->id),
        );
    }

    public function reject(RejectShiftProductionEntryRequest $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->reject($shiftProductionEntry, $request->user()->id, $request->validated('reason')),
        );
    }

    /**
     * Cancel a batch started by mistake, freeing its machine. Refuses outright
     * if the batch has produced anything at all — see the service.
     */
    public function cancel(CancelShiftProductionEntryRequest $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->cancelTestBatch($shiftProductionEntry, $request->user()?->id, $request->validated('reason')),
        );
    }

    /**
     * Shift handover (Phase 6, traceability-gated route): completes the
     * outgoing segment and returns the freshly opened child segment.
     */
    public function handover(HandoverRequest $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->handover($shiftProductionEntry, $request->validated(), $request->user()?->id),
        );
    }
}
