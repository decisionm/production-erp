<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Services\BinBayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * READ ONLY: the machine-scoped ledger view the Start Batch dialog prices a
 * run against — what the ledger holds of a material (with its source-lot
 * layers) and the recipe's expected-vs-available per component.
 *
 * The Bin Bay LOADING surface that used to live beside this read — the
 * per-machine page, bin-bay/load and bin-bay/history — is gone
 * (DEC-20260807-006): the floor's only load flow is the common resin
 * input's bag scan (FactoryDayBinController::loadBag), which names no
 * machine. Historical machine-stamped rows remain in the ledger this
 * read serves, as the audit record of the previous understanding.
 */
class BinBayController extends Controller
{
    public function __construct(private readonly BinBayService $binBay) {}

    /**
     * What the bay holds and what the run needs.
     *
     * `item_id` names the MATERIAL whose bin stock is being inspected.
     * `product_item_id` + `expected_pieces` are a separate, optional pair
     * naming the PRODUCT about to run — they add the recipe requirement
     * block. Two meanings on one parameter is how a screen ends up quoting
     * a resin balance as a finished-good figure, so they stay distinct.
     */
    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'product_item_id' => ['nullable', 'required_with:expected_pieces', 'integer', 'exists:items,id'],
            'expected_pieces' => ['nullable', 'required_with:product_item_id', 'integer', 'min:0'],
        ]);

        $workCenterId = (int) $validated['work_center_id'];

        return response()->json(['data' => [
            'bin' => isset($validated['item_id'])
                ? $this->binBay->availabilityFor($workCenterId, (int) $validated['item_id'])
                : null,
            'requirement' => isset($validated['product_item_id'])
                ? $this->binBay->expectedVsAvailable(
                    (int) $validated['product_item_id'],
                    $workCenterId,
                    (int) $validated['expected_pieces'],
                )
                : null,
        ]]);
    }
}
