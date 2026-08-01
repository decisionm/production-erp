<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\MaterialBagResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Http\Requests\LoadFactoryDayBinBagRequest;
use App\Modules\Production\Http\Resources\FactoryDayBinLoadResource;
use App\Modules\Production\Http\Resources\FactoryDayBinMaterialResource;
use App\Modules\Production\Http\Resources\FactoryDayBinReconciliationResource;
use App\Modules\Production\Http\Resources\FactoryDayBinSummaryResource;
use App\Modules\Production\Http\Resources\RawMaterialPickerResource;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The factory day bin as a place: which warehouse it is, and what is in it
 * right now — always readable without picking a machine.
 *
 * The read (show) is deliberately OUTSIDE the traceability-gated route
 * group: the central bin is the plain path (a warehouse and its stock
 * balances) and must exist whether or not the barcode/bag surfaces do.
 * loadBag is the exception — it resolves a barcode to a MaterialBag, which
 * only exists with traceability on, so its route sits INSIDE the gate with
 * its day-bin neighbours.
 *
 * `warehouse: null` means nobody has named the bin yet. That is a normal
 * answer, not an error — the screen prompts for a setting and everything else
 * keeps working exactly as it did before.
 */
class FactoryDayBinController extends Controller
{
    public function __construct(private readonly FactoryDayBinService $dayBin) {}

    public function show(): JsonResponse
    {
        $snapshot = $this->dayBin->snapshot();

        return response()->json(['data' => [
            'warehouse' => $snapshot['warehouse'] !== null
                ? WarehouseResource::make($snapshot['warehouse'])
                : null,
            'materials' => FactoryDayBinMaterialResource::collection($snapshot['materials']),
            // The owner's one-look block: per raw material bin vs store vs
            // bags, plus every load into the bin today. Both empty until a
            // bin is configured — same "normal state" rule as warehouse:null.
            'summary' => FactoryDayBinSummaryResource::collection($snapshot['summary']),
            'todays_loads' => FactoryDayBinLoadResource::collection($snapshot['todays_loads']),
        ]]);
    }

    /**
     * The day-bin reconciliation for one date (?date=YYYY-MM-DD, today when
     * absent): per raw material, opening + loaded − consumed = expected
     * closing. Past dates are first-class — yesterday's figures are the
     * accountant's morning question.
     *
     * This is the CENTRAL replacement for the per-batch "unaccounted kg"
     * figure, which was ~0 by construction (a batch's resin consumption is
     * derived from its output, never separately weighed) and only confused
     * the floor. The endpoint returns the EXPECTED side only — the genuine
     * check is the physical count a person takes, which the frontend
     * collects and compares client-side. Nothing here is a verdict.
     *
     * A read, so production.view is enough; the date is validated in the
     * service (ValidationException → 422), which is where loadBag's input
     * rules already live.
     */
    public function reconciliation(Request $request): JsonResponse
    {
        $date = $request->query('date');

        $result = $this->dayBin->reconciliation(is_string($date) ? $date : null);

        return response()->json(['data' => [
            'date' => $result['date'],
            'warehouse' => $result['warehouse'] !== null
                ? WarehouseResource::make($result['warehouse'])
                : null,
            'materials' => FactoryDayBinReconciliationResource::collection($result['materials']),
        ]]);
    }

    /**
     * The Day Bin page's picker: active kg-uom items (the raw materials —
     * resin and masterbatch count in kg; bottles and caps count in Nos and
     * never appear) with their current store kg.
     */
    public function rawMaterials(): JsonResponse
    {
        return response()->json([
            'data' => RawMaterialPickerResource::collection($this->dayBin->rawMaterials()),
        ]);
    }

    /**
     * The Shift Floor's centralized scan: one bag barcode → its kg moves
     * store → factory day-bin warehouse, for all machines at once. The
     * audit identity is ALWAYS the authenticated user; supervisor_id is
     * only recorded as a note of who was acting supervisor.
     */
    public function loadBag(LoadFactoryDayBinBagRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->dayBin->loadBag(
            (string) $validated['barcode'],
            isset($validated['quantity_kg']) ? (string) $validated['quantity_kg'] : null,
            (int) $request->user()->id,
            isset($validated['supervisor_id']) ? (int) $validated['supervisor_id'] : null,
        );

        return response()->json(['data' => [
            'bag' => MaterialBagResource::make($result['bag']),
            'day_bin' => FactoryDayBinMaterialResource::make($result['balance']),
        ]]);
    }
}
