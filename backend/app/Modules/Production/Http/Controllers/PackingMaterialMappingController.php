<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\PackingMaterialMappingQueryRequest;
use App\Modules\Production\Http\Requests\StorePackingMaterialMappingRequest;
use App\Modules\Production\Http\Resources\PackingMaterialMappingResource;
use App\Modules\Production\Models\PackingMaterialMapping;
use App\Modules\Production\Services\PackingMaterialMappingService;
use Illuminate\Http\JsonResponse;

/**
 * The packing-material master: which Tally item each workbook spec means.
 *
 * The write endpoint is the whole point. The deploy-time seed resolves what
 * the catalogue can prove and deliberately leaves the rest empty — which
 * cartons seal with Green tape, which of two identically-named trays a shift
 * consumes, what film a "750*610" is. Those answers come from a person in the
 * app, not from a deploy.
 *
 * Guarded exactly like its production neighbours: the route group's
 * `module:production` middleware passes a GET on production.view OR .manage,
 * and requires production.manage for the POST and the DELETE.
 */
class PackingMaterialMappingController extends Controller
{
    public function __construct(private readonly PackingMaterialMappingService $mappings) {}

    /**
     * What is mapped. Narrowed by `spec_kind` and/or `spec_value`.
     *
     * An empty list is a real answer, not a failure: it means nothing is
     * mapped for that spec, so the floor prefills nothing and the line stays
     * blank. Never a zero row.
     */
    public function index(PackingMaterialMappingQueryRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => PackingMaterialMappingResource::list($this->mappings->all(
                $data['spec_kind'] ?? null,
                $data['spec_value'] ?? null,
            )),
        ]);
    }

    /**
     * Enter or correct the mapping for one (kind, spec) pair. Upsert: a
     * correction replaces the answer in force rather than stacking a second
     * row behind it.
     */
    /**
     * The lists the completion screen's dropdowns are built from — every item
     * that could be a carton, a tray, a film or a roll of tape.
     *
     * The screen used to print a sentence when it could not resolve a material:
     * "…has no packing-material mapping yet, so nothing is prefilled. Set one on
     * the packing-materials master…". The owner's answer to that (05-Aug) was
     * "why so many English notes, will they really read them" — and he is right,
     * because the supervisor reading it has neither the access nor the time to
     * administer master data mid-shift.
     *
     * A line that cannot be resolved needs a PICKER, not a paragraph. This is
     * what fills it. It also serves the case the sentence never addressed at
     * all: the 100 ml cartons ran out, so today this product goes in a 90 ml
     * box. That is not a data error to be corrected later, it is Tuesday.
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->mappings->optionsByKind()]);
    }

    public function store(StorePackingMaterialMappingRequest $request): JsonResponse
    {
        $mapping = $this->mappings->upsert($request->validated(), $request->user()?->id);

        return response()->json([
            'data' => PackingMaterialMappingResource::make($mapping)->resolve(),
        ]);
    }

    /**
     * Withdraw a mapping — back to "no prefill". Soft-deleted, so shifts that
     * were prefilled from it while it applied remain explainable.
     */
    public function destroy(PackingMaterialMapping $packingMaterialMapping): JsonResponse
    {
        $this->mappings->withdraw($packingMaterialMapping);

        return response()->json(['data' => ['id' => (int) $packingMaterialMapping->id, 'withdrawn' => true]]);
    }
}
