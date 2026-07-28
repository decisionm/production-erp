<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Http\Requests\CountDayBinRequest;
use App\Modules\Production\Http\Requests\LoadDayBinRequest;
use App\Modules\Production\Http\Requests\ReturnDayBinRequest;
use App\Modules\Production\Http\Resources\DayBinMovementResource;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The machine day-bin surface (Phase 6). Bag-touching actions (load,
 * return) go through Inventory's TraceabilityService — bag state is that
 * module's; ledger-only reads and counts use this module's
 * DayBinLedgerService directly.
 */
class DayBinController extends Controller
{
    public function __construct(
        private readonly TraceabilityService $traceability,
        private readonly DayBinLedgerService $ledger,
    ) {}

    public function movements(Request $request): AnonymousResourceCollection
    {
        return DayBinMovementResource::collection($this->ledger->paginate(
            $request->query('work_center_id') ? (int) $request->query('work_center_id') : null,
            $request->query('item_id') ? (int) $request->query('item_id') : null,
        ));
    }

    public function load(LoadDayBinRequest $request): DayBinMovementResource
    {
        return DayBinMovementResource::make(
            $this->traceability->loadBagToDayBin($request->validated(), $request->user()?->id)
                ->load(['item', 'materialBag.lot']),
        );
    }

    public function returnMaterial(ReturnDayBinRequest $request): DayBinMovementResource
    {
        return DayBinMovementResource::make(
            $this->traceability->returnFromDayBin($request->validated(), $request->user()?->id)
                ->load(['item', 'materialBag.lot']),
        );
    }

    public function count(CountDayBinRequest $request): DayBinMovementResource
    {
        return DayBinMovementResource::make(
            $this->traceability->recordCount($request->validated(), $request->user()?->id),
        );
    }

    /**
     * The computed segment consumption (opening + loaded − closing −
     * returned) — the figure that pre-fills the Resin/MB consumption rows
     * in Complete Batch.
     */
    public function consumption(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_production_entry_id' => ['required', 'integer', 'exists:shift_production_entries,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
        ]);

        return response()->json(['data' => $this->ledger->consumptionForEntryId(
            (int) $validated['shift_production_entry_id'],
            (int) $validated['item_id'],
        )]);
    }

    /**
     * Live day-bin state of one machine: per-material balance plus the
     * bags physically at the machine — what the Shift Floor drawer and
     * the handover screen render. Bag detail comes via Inventory's
     * service (bags are that module's), balances from this module's
     * ledger — composed in TraceabilityService, the one entry point.
     */
    public function workCenterState(WorkCenter $workCenter): JsonResponse
    {
        return response()->json(['data' => $this->traceability->dayBinStateFor($workCenter->id)]);
    }

    /**
     * Per-segment day-bin summary — one row per material with the full
     * formula working (opening/loaded/returned/closing/consumption) that
     * pre-fills Complete Batch's Resin/MB rows.
     */
    public function entryState(ShiftProductionEntry $shiftProductionEntry): JsonResponse
    {
        return response()->json(['data' => $this->ledger->entrySummaryFor($shiftProductionEntry)]);
    }
}
