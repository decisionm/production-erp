<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CompleteBatchRequest;
use App\Modules\Production\Http\Requests\HandoverRequest;
use App\Modules\Production\Http\Requests\RejectShiftProductionEntryRequest;
use App\Modules\Production\Http\Requests\StartBatchRequest;
use App\Modules\Production\Http\Resources\ShiftProductionEntryResource;
use App\Modules\Production\Models\Enums\ShiftProductionEntryStatus;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShiftProductionEntryController extends Controller
{
    public function __construct(private readonly ShiftProductionEntryService $entries) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->query('status')
            ? ShiftProductionEntryStatus::from($request->query('status'))
            : null;

        return ShiftProductionEntryResource::collection($this->entries->paginate(status: $status));
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

    public function mdApprove(Request $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        abort_unless($request->user()->hasAnyRole(['Administrator']), 403, 'Final approval requires an Administrator (MD/Director) account.');

        return ShiftProductionEntryResource::make(
            $this->entries->mdApprove($shiftProductionEntry, $request->user()->id),
        );
    }

    public function reject(RejectShiftProductionEntryRequest $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->reject($shiftProductionEntry, $request->user()->id, $request->validated('reason')),
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
