<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListBatchesRequest;
use App\Modules\Inventory\Http\Requests\StoreBatchRequest;
use App\Modules\Inventory\Http\Resources\BatchResource;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BatchController extends Controller
{
    public function __construct(private readonly BatchService $batches) {}

    /**
     * `item_id` is what the per-item picker reads and is unchanged; `per_page`,
     * `search` and `code` are new, because ignoring them capped this list at
     * twenty with no way to ask for the rest.
     *
     * `code` IS WHAT A SCANNER ASKS, and it is not `search` with fewer rows:
     * `search` is a substring match served a page at a time, so a batch number
     * that is a substring of enough newer ones is not on the page the scanner
     * reads and its own barcode comes back unknown. `code` matches the whole
     * number, which is an answer the page size cannot truncate.
     *
     * It resolves an identifier the factory already prints — it does not
     * decide what a barcode should CONTAIN or at what granularity one is
     * issued. That question is open in PENDING-OWNER-QUESTIONS and is not
     * answered here.
     */
    public function index(ListBatchesRequest $request): AnonymousResourceCollection
    {
        return BatchResource::collection($this->batches->paginate(
            itemId: $this->filterId($request, 'item_id'),
            perPage: $this->perPage($request),
            search: $this->searchTerm($request),
            code: $this->searchTerm($request, 'code'),
            sort: $request->sort(),
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
