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

    public function index(Request $request): AnonymousResourceCollection
    {
        return BatchResource::collection($this->batches->paginate($request->integer('item_id') ?: null));
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
