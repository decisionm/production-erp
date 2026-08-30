<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListProductionReturnableRequest;
use App\Modules\Inventory\Http\Requests\StoreProductionReturnRequest;
use App\Modules\Inventory\Http\Resources\ProductionReturnableResource;
use App\Modules\Inventory\Services\ProductionReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The daily way home from the production area.
 *
 * Thin, like every controller here: the split, the bounds and every refusal
 * belong to ProductionReturnService, which is the only place that holds the
 * balance under a lock while it decides.
 */
class ProductionReturnController extends Controller
{
    public function __construct(private readonly ProductionReturnService $returns) {}

    /** What is standing in production, and how much of it may come back which way. */
    public function returnable(ListProductionReturnableRequest $request): AnonymousResourceCollection
    {
        return ProductionReturnableResource::collection(
            $this->returns->returnable($request->validated()['q'] ?? null),
        );
    }

    public function store(StoreProductionReturnRequest $request): JsonResponse
    {
        $data = $request->validated();

        $moved = $this->returns->record(
            lines: array_map(
                fn (array $line) => [
                    'item_id' => isset($line['item_id']) ? (int) $line['item_id'] : null,
                    'store_issue_line_id' => isset($line['store_issue_line_id'])
                        ? (int) $line['store_issue_line_id']
                        : null,
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
