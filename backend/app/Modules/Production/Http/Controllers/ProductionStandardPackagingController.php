<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\SaveProductionStandardPackagingRequest;
use App\Modules\Production\Http\Requests\SetProductionStandardPackagingIdentityRequest;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Services\ProductionStandardPackagingService;
use Illuminate\Http\JsonResponse;

/**
 * The packaging variants of one product standard. Thin by the module rule:
 * the twin refusal, the one-default rule, the derived box count and the
 * identity provenance all live in ProductionStandardPackagingService.
 */
class ProductionStandardPackagingController extends Controller
{
    public function __construct(private readonly ProductionStandardPackagingService $packagings) {}

    public function store(SaveProductionStandardPackagingRequest $request, ProductionStandard $standard): JsonResponse
    {
        $packaging = $this->packagings->store($standard, $request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->packagings->describe($packaging)], 201);
    }

    public function update(
        SaveProductionStandardPackagingRequest $request,
        ProductionStandard $standard,
        ProductionStandardPackaging $packaging,
    ): JsonResponse {
        $packaging = $this->packagings->update($standard, $packaging, $request->validated(), $request->user()?->id);

        return response()->json(['data' => $this->packagings->describe($packaging)]);
    }

    /**
     * Identity only — item_id and its provenance, never a count (P1-a).
     * The review panel's Link comes through here.
     */
    public function identity(
        SetProductionStandardPackagingIdentityRequest $request,
        ProductionStandard $standard,
        ProductionStandardPackaging $packaging,
    ): JsonResponse {
        $itemId = $request->validated('item_id');

        $packaging = $this->packagings->setIdentity(
            $standard,
            $packaging,
            $itemId === null ? null : (int) $itemId,
            $request->user()?->id,
        );

        return response()->json(['data' => $this->packagings->describe($packaging)]);
    }
}
