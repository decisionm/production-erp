<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\TraceabilityService;
use App\Modules\Production\Http\Resources\DayBinMovementResource;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Models\WorkCenter;
use App\Modules\Production\Services\DayBinLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The machine day-bin surface (Phase 6), now READ ONLY (Phase 7.5, WS-C).
 *
 * The three write actions this controller used to expose — load, return and
 * count — are retired. Each had zero UI callers: `day-bin/load` was the
 * machine-stamped load path DEC-20260807-006 retired (the floor's one load
 * flow is the common input's bag scan, which names no machine), and
 * DEC-20260807-007 records that the bin is never weighed, so no count will
 * ever be taken. DEC-20260817-001 then removed the Day Bin from the
 * factory's logical inventory locations entirely.
 *
 * NOTHING under the doors was deleted. `day_bin_movements` keeps every row,
 * including the historical machine-stamped ones DEC-20260807-006 requires be
 * preserved untouched; DayBinLedgerService and the writers behind the retired
 * doors (TraceabilityService::loadBagToDayBin / returnFromDayBin /
 * recordCount) all remain, and the reads below still serve that history.
 * Closing counts are still written on completion and handover — that path is
 * ShiftProductionEntryService::recordClosingDayBin, which calls the ledger
 * directly and never went through these routes.
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
