<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\MaterialBagResource;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Http\Requests\LoadFactoryDayBinBagRequest;
use App\Modules\Production\Http\Resources\FactoryDayBinMaterialResource;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Http\JsonResponse;

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
        ]]);
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
