<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\WarehouseResource;
use App\Modules\Production\Http\Resources\FactoryDayBinMaterialResource;
use App\Modules\Production\Services\FactoryDayBinService;
use Illuminate\Http\JsonResponse;

/**
 * The factory day bin as a place: which warehouse it is, and what is in it
 * right now — always readable without picking a machine.
 *
 * Deliberately OUTSIDE the traceability-gated route group: the central bin is
 * the plain path (a warehouse and its stock balances) and must exist whether
 * or not the barcode/bag surfaces do.
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
}
