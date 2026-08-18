<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\SaveProductionStandardPackagingRequest;
use App\Modules\Production\Http\Requests\SetProductionStandardPackagingIdentityRequest;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Services\ProductionStandardPackagingService;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The packaging variants of one product standard. Thin by the module rule:
 * the twin refusal, the one-default rule, the derived box count and the
 * identity provenance all live in ProductionStandardPackagingService.
 */
class ProductionStandardPackagingController extends Controller
{
    use ManagesConfigurationRecords;

    protected function configurationWritePermission(): string
    {
        return 'production.manage';
    }

    protected function configurationNoun(): string
    {
        return 'packing variant';
    }

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

    /** One variant, archived rows included, with the authoritative `can`. */
    public function show(ProductionStandard $standard, int $packaging): JsonResponse
    {
        $found = $this->packagings->resolveFor($standard, $packaging);
        $this->withAbilities(request(), $this->packagings, $found);

        return response()->json(['data' => $this->packagings->describe($found)]);
    }

    /**
     * Withdraw a packing variant. It stays readable — a shift that prefilled
     * 840/box from it has to stay explainable — and keeps its slot in the
     * variant uniqueness rule, so nobody may add a second copy of it
     * (DEC-20260817-002 §2).
     */
    public function archive(ConfigurationReasonRequest $request, ProductionStandard $standard, int $packaging): JsonResponse
    {
        $this->archiveRecord($this->packagings, $this->packagings->resolveFor($standard, $packaging), $request->reason());

        return $this->show($standard, $packaging);
    }

    public function activate(ConfigurationReasonRequest $request, ProductionStandard $standard, int $packaging): JsonResponse
    {
        $this->activateRecord($this->packagings, $this->packagings->resolveFor($standard, $packaging), $request->reason());

        return $this->show($standard, $packaging);
    }

    /**
     * Hard delete — Super Admin / Owner only, and only for a variant no
     * shift entry and no packing line has ever named, carrying no Tally
     * identity of its own.
     */
    public function destroy(Request $request, ProductionStandard $standard, int $packaging): Response
    {
        $this->packagings->delete(
            $this->packagings->resolveFor($standard, $packaging),
            $request->user(),
        );

        return response()->noContent();
    }
}
