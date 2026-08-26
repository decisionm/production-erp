<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreBatchRequest;
use App\Modules\Inventory\Http\Resources\BatchResource;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BatchController extends Controller
{
    public function __construct(private readonly BatchService $batches) {}

    /**
     * `item_id` is what the per-item picker reads and is unchanged; `per_page`
     * and `search` are new, because ignoring them capped this list at twenty
     * with no way to ask for the rest.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return BatchResource::collection($this->batches->paginate(
            itemId: $request->integer('item_id') ?: null,
            perPage: $this->perPage($request),
            search: $this->searchTerm($request),
        ));
    }

    public function store(StoreBatchRequest $request): BatchResource
    {
        return BatchResource::make($this->batches->create($request->validated()));
    }

    public function ledger(Batch $batch): JsonResponse
    {
        $ledger = $this->batches->ledger($batch);

        return response()->json([
            'data' => [
                'batch' => BatchResource::make($ledger['batch']),
                'on_hand' => $ledger['on_hand'],
                'movements' => StockMovementResource::collection($ledger['movements']),
            ],
        ]);
    }
}
