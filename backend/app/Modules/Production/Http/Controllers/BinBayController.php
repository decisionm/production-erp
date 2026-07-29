<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\BinBayLoadRequest;
use App\Modules\Production\Http\Resources\DayBinMovementResource;
use App\Modules\Production\Services\BinBayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The central bin bay. Material is loaded into a machine's day bin HERE,
 * once, by the bay — every batch screen then reads the bin instead of
 * asking the supervisor to declare material again.
 *
 * A load is an inventory location movement (store → machine day bin): not
 * consumption, and never a Tally post. See BinBayService's docblock.
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

    /** Who loaded what into this bay, when, off which bag — newest first. */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_center_id' => ['required', 'integer', 'exists:work_centers,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json(['data' => $this->binBay->loadHistoryFor(
            (int) $validated['work_center_id'],
            isset($validated['item_id']) ? (int) $validated['item_id'] : null,
            (int) ($validated['limit'] ?? 50),
        )]);
    }

    /**
     * Scan a bag into the bay. Delegates to the one loader in the system
     * (Inventory's TraceabilityService, via BinBayService) — bag balance,
     * FIFO policy and ledger row in a single transaction.
     */
    public function load(BinBayLoadRequest $request): DayBinMovementResource
    {
        $movement = $this->binBay->load($request->movementData(), $request->attributedUserId());

        // recordedBy is eager-loaded on purpose: DayBinMovementResource only
        // emits `recorded_by` whenLoaded, and the bay screen's whole point is
        // showing who fed the machine.
        return DayBinMovementResource::make(
            $movement->load(['item', 'materialBag.lot', 'recordedBy', 'workCenter']),
        );
    }
}
