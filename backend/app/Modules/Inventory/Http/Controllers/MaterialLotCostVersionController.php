<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreMaterialCostVersionRequest;
use App\Modules\Inventory\Http\Resources\MaterialCostVersionResource;
use App\Modules\Inventory\Models\MaterialLot;
use App\Modules\Inventory\Services\MaterialCostVersionService;
use Illuminate\Http\JsonResponse;

/**
 * Read and append a material lot's cost history.
 *
 * Gated on module:finance, NOT module:inventory, even though the lot is an
 * Inventory record. Purchase rates are Owner/Accounts territory: the store
 * team scans bags and counts kilos, they are not the people who decide what
 * a kilo cost, and a supplier's rate is not something a shift supervisor
 * should be able to read off a lot register. The URL sits under
 * /inventory/material-lots/{lot} because that is what the resource IS; the
 * middleware says who may see this part of it.
 *
 * There is no update and no destroy, by design — see MaterialCostVersion.
 *
 * Both responses carry a top-level 'note' spelling out that a cost version
 * changes no stock and re-prices no batch. The shape is built explicitly
 * rather than with ->additional() so it cannot collide with the version's
 * OWN 'note' (the recorder's words) inside data.
 */
class MaterialLotCostVersionController extends Controller
{
    public function __construct(private readonly MaterialCostVersionService $versions) {}

    public function index(MaterialLot $lot): JsonResponse
    {
        return response()->json([
            'data' => MaterialCostVersionResource::collection($this->versions->forLot($lot)),
            'note' => MaterialCostVersionService::EFFECT_NOTE,
        ]);
    }

    public function store(StoreMaterialCostVersionRequest $request, MaterialLot $lot): JsonResponse
    {
        $version = $this->versions->append($lot, $request->validated(), $request->user()?->id);

        return response()->json([
            'data' => MaterialCostVersionResource::make($version),
            'note' => MaterialCostVersionService::EFFECT_NOTE,
        ], 201);
    }
}
