<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\MaterialBagResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Http\Requests\LoadFactoryDayBinBagRequest;
use App\Modules\Production\Http\Requests\MachineResinQueryRequest;
use App\Modules\Production\Http\Resources\CommonResinMaterialResource;
use App\Modules\Production\Http\Resources\DayBinMovementResource;
use App\Modules\Production\Http\Resources\FactoryDayBinLoadResource;
use App\Modules\Production\Http\Resources\FactoryDayBinMaterialResource;
use App\Modules\Production\Http\Resources\FactoryDayBinSummaryResource;
use App\Modules\Production\Http\Resources\RawMaterialPickerResource;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Http\JsonResponse;

/**
 * The factory's internal WIP location as a place (which warehouse it is and
 * what is in it), and the COMMON RESIN INPUT's estimated remaining built on
 * top of it — one figure per material for the whole factory, never per
 * machine (owner's correction, 2-Aug).
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
     * ESTIMATED RESIN REMAINING IN THE COMMON INPUT — every load of a
     * material minus the calculated consumption of that material across ALL
     * machines, one row per material.
     *
     * THE MACHINE DIMENSION IS GONE, and that is the owner's correction
     * (2-Aug): the factory has one common resin input point, a bag is never
     * assigned or scanned to a machine, and a per-machine balance was a
     * number with no physical referent. The route PATH is unchanged so the
     * deploy is one-sided; the PAYLOAD is a flat list of materials.
     *
     * ?work_center_id= is still validated and DELIBERATELY IGNORED for the
     * length of the deploy window — a tablet running the previous build will
     * keep sending it, and a 422 on a read it can no longer parse anyway
     * would replace a stale number with an error. It narrows nothing, because
     * there is nothing left to narrow to.
     *
     * A read, so production.view is enough.
     */
    public function machineResin(MachineResinQueryRequest $request): JsonResponse
    {
        $request->validated();

        return response()->json([
            'data' => CommonResinMaterialResource::collection(
                $this->dayBin->commonResinEstimate(),
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
     * The Shift Floor's scan: one bag barcode → its kg move store → the
     * internal WIP warehouse and enter the COMMON RESIN INPUT. In the books
     * the stock simply changed location (Tally still sees one godown); no
     * machine is named, because the factory has one loading point and a bag
     * is never assigned to a machine.
     *
     * A work_center_id posted by a floor tablet still running the previous
     * build is accepted and ignored — see LoadFactoryDayBinBagRequest.
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
            (int) $request->user()->id,
            isset($validated['supervisor_id']) ? (int) $validated['supervisor_id'] : null,
            isset($validated['balance_ack_reason']) ? (string) $validated['balance_ack_reason'] : null,
            isset($validated['balance_ack_note']) ? (string) $validated['balance_ack_note'] : null,
        );

        return response()->json(['data' => [
            'bag' => MaterialBagResource::make($result['bag']),
            // The material's stock where it is held. Null when no balance row
            // exists yet — the scan is still a complete record without one,
            // and a load no longer moves stock, so there may be nothing here
            // to report.
            'day_bin' => $result['balance'] !== null
                ? FactoryDayBinMaterialResource::make($result['balance'])
                : null,
            // The load row the scan just wrote, echoed back so the floor
            // screen can confirm the kg it credited without a second read.
            // Its work_center is null and stays null.
            'movement' => DayBinMovementResource::make($result['movement']),
        ]]);
    }
}
