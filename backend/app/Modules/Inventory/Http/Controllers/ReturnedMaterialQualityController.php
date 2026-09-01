<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ConfirmReturnedMaterialDamageRequest;
use App\Modules\Inventory\Http\Requests\ReleaseReturnedMaterialRequest;
use App\Modules\Inventory\Services\ReturnedMaterialQualityService;
use Illuminate\Http\JsonResponse;

/**
 * QUALITY'S END OF THE DAMAGED RETURN (DEC-20260901-003).
 *
 * Thin, like every controller here: what may be scrapped, what is standing,
 * and every refusal belong to ReturnedMaterialQualityService, which is the
 * only place that holds the balances under a lock while it decides.
 *
 * THE CLASS LIVES IN INVENTORY AND THE ROUTES ARE GATED ON QUALITY, and that
 * pairing is deliberate rather than untidy: the thing being moved is stock,
 * which is Inventory's to write and nobody else's, while the person entitled
 * to decide is the quality desk. Putting the service in Quality would have it
 * reach into Inventory's models directly, which the module rule forbids.
 */
class ReturnedMaterialQualityController extends Controller
{
    public function __construct(private readonly ReturnedMaterialQualityService $returns) {}

    /** What came back damaged and is waiting to be looked at. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->returns->standing()->all()]);
    }

    public function confirmDamage(ConfirmReturnedMaterialDamageRequest $request): JsonResponse
    {
        $data = $request->validated();

        $moved = $this->returns->confirmDamage(
            lines: array_map(
                fn (array $line) => [
                    'item_id' => (int) $line['item_id'],
                    'quantity' => (string) $line['quantity'],
                ],
                $data['lines'],
            ),
            recordedBy: (int) $request->user()->id,
            notes: $data['notes'] ?? null,
        );

        return response()->json(['data' => $moved->values()->all()], 201);
    }

    public function release(ReleaseReturnedMaterialRequest $request): JsonResponse
    {
        $data = $request->validated();

        $moved = $this->returns->release(
            lines: array_map(
                fn (array $line) => [
                    'item_id' => (int) $line['item_id'],
                    'quantity' => (string) $line['quantity'],
                ],
                $data['lines'],
            ),
            toWarehouseId: (int) $data['to_warehouse_id'],
            recordedBy: (int) $request->user()->id,
            notes: $data['notes'] ?? null,
        );

        return response()->json(['data' => $moved->values()->all()], 201);
    }
}
