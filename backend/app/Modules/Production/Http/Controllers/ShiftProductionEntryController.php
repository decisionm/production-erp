<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\CompleteBatchRequest;
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

    public function approve(Request $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->approve($shiftProductionEntry, $request->user()->id),
        );
    }

    public function reject(RejectShiftProductionEntryRequest $request, ShiftProductionEntry $shiftProductionEntry): ShiftProductionEntryResource
    {
        return ShiftProductionEntryResource::make(
            $this->entries->reject($shiftProductionEntry, $request->user()->id, $request->validated('reason')),
        );
    }
}
