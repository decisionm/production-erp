<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\MasterbatchDosingQueryRequest;
use App\Modules\Production\Http\Requests\StoreMasterbatchDosingRequest;
use App\Modules\Production\Http\Resources\MasterbatchDosingResource;
use App\Modules\Production\Models\MasterbatchDosing;
use App\Modules\Production\Services\MasterbatchDosingService;
use Illuminate\Http\JsonResponse;

/**
 * Masterbatch dosing: grams per bottle, as editable master data.
 *
 * The whole point of the write endpoint is that the next figure the factory
 * gives (white, red/brown, or a correction to amber) is entered by a person in
 * the app — not shipped in a deploy. Guarded exactly like its production
 * neighbours: the route group's `module:production` middleware passes a GET on
 * production.view OR .manage, and requires production.manage for the POST and
 * the DELETE.
 */
class MasterbatchDosingController extends Controller
{
    public function __construct(private readonly MasterbatchDosingService $dosings) {}

    /**
     * What dosing applies. With `item_id` (the product) the list is narrowed
     * to the dosings that could apply to that bottle, one per masterbatch,
     * product-specific beating factory-wide. With `quantity_produced` each
     * one also carries the kg for that many bottles.
     *
     * An empty list is a real answer, not a failure: it means no dosing is
     * set, so the floor prefills nothing and the field stays blank. Never a
     * zero row.
     */
    public function index(MasterbatchDosingQueryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $bottles = isset($data['quantity_produced']) ? (int) $data['quantity_produced'] : null;
        $masterbatchItemId = isset($data['masterbatch_item_id']) ? (int) $data['masterbatch_item_id'] : null;
        $productItemId = isset($data['item_id']) ? (int) $data['item_id'] : null;

        // Naming a masterbatch asks the narrow question ("what applies to
        // THIS material for this bottle?") and gets one row or none.
        $rows = $masterbatchItemId !== null
            ? collect([$this->dosings->resolve($masterbatchItemId, $productItemId)])->filter()
            : $this->dosings->candidatesFor($productItemId);

        return response()->json([
            'data' => MasterbatchDosingResource::listForBottles($rows, $bottles),
        ]);
    }

    /**
     * Enter or change the figure for one (masterbatch, product) pair. Upsert:
     * a correction replaces the figure in force rather than stacking a second
     * row behind it.
     */
    public function store(StoreMasterbatchDosingRequest $request): JsonResponse
    {
        $dosing = $this->dosings->upsert($request->validated(), $request->user()?->id);

        return response()->json([
            'data' => MasterbatchDosingResource::make($dosing)->resolve(),
        ]);
    }

    /**
     * Withdraw a figure — back to "no prefill". Soft-deleted, so shifts that
     * were prefilled from it while it applied remain explainable.
     */
    public function destroy(MasterbatchDosing $masterbatchDosing): JsonResponse
    {
        $this->dosings->withdraw($masterbatchDosing);

        return response()->json(['data' => ['id' => (int) $masterbatchDosing->id, 'withdrawn' => true]]);
    }
}
