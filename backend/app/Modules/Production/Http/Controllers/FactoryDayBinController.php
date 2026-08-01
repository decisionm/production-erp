<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\MaterialBagResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Http\Requests\LoadFactoryDayBinBagRequest;
use App\Modules\Production\Http\Requests\MachineResinQueryRequest;
use App\Modules\Production\Http\Resources\DayBinMovementResource;
use App\Modules\Production\Http\Resources\FactoryDayBinLoadResource;
use App\Modules\Production\Http\Resources\FactoryDayBinMaterialResource;
use App\Modules\Production\Http\Resources\FactoryDayBinSummaryResource;
use App\Modules\Production\Http\Resources\MachineResinEstimateResource;
use App\Modules\Production\Http\Resources\RawMaterialPickerResource;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Http\JsonResponse;

/**
 * The factory's internal WIP location as a place (which warehouse it is and
 * what is in it), and the per-machine estimated resin remaining built on top
 * of it.
 *
 * The reads (show, machineResin) are deliberately OUTSIDE the
 * traceability-gated route group: they are the plain path (warehouses,
 * balances, ledger rows and consumption rows) and must exist whether or not
 * the barcode/bag surfaces do. loadBag is the exception — it resolves a
 * barcode to a MaterialBag, which only exists with traceability on, so its
 * route sits INSIDE the gate with its day-bin neighbours.
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
     * ESTIMATED RESIN REMAINING PER MACHINE — loads into that machine minus
     * the calculated consumption of its batches, per material, all time.
     *
     * This REPLACED the day-bin reconciliation read, which compared a derived
     * expected closing against a physical bin weight. The owner (31-Jul):
     * "Our factory does not use a Day Bin warehouse or an evening physical
     * bin weight. Replace that idea with estimated resin remaining for each
     * machine." A read that waits for a count nobody takes is worse than no
     * read, so it is gone rather than left on the screen looking answerable.
     *
     * Optional ?work_center_id= narrows it to one machine. A read, so
     * production.view is enough.
     */
    public function machineResin(MachineResinQueryRequest $request): JsonResponse
    {
        $workCenterId = $request->validated()['work_center_id'] ?? null;

        return response()->json([
            'data' => MachineResinEstimateResource::collection(
                $this->dayBin->machineResinEstimate(
                    $workCenterId !== null ? (int) $workCenterId : null,
                ),
            ),
        ]);
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
     * The Shift Floor's scan: one bag barcode → its kg moves store → the
     * internal WIP warehouse, AND is attributed to the machine it was
     * emptied into. In the books the stock simply changed location (Tally
     * still sees one godown); the machine is operational metadata, and it is
     * what the per-machine estimate above is built from.
     *
     * The audit identity is ALWAYS the authenticated user; supervisor_id is
     * only recorded as a note of who was acting supervisor.
     */
    public function loadBag(LoadFactoryDayBinBagRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->dayBin->loadBag(
            (string) $validated['barcode'],
            isset($validated['quantity_kg']) ? (string) $validated['quantity_kg'] : null,
            (int) $validated['work_center_id'],
            (int) $request->user()->id,
            isset($validated['supervisor_id']) ? (int) $validated['supervisor_id'] : null,
            isset($validated['balance_ack_reason']) ? (string) $validated['balance_ack_reason'] : null,
            isset($validated['balance_ack_note']) ? (string) $validated['balance_ack_note'] : null,
        );

        return response()->json(['data' => [
            'bag' => MaterialBagResource::make($result['bag']),
            'day_bin' => FactoryDayBinMaterialResource::make($result['balance']),
            // The machine attribution the scan just wrote, echoed back so the
            // floor screen can confirm WHICH machine it credited without a
            // second read.
            'movement' => DayBinMovementResource::make($result['movement']),
        ]]);
    }
}
