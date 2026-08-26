<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\StoreStockIssueRequest;
use App\Modules\Inventory\Http\Requests\StoreStockReceiptRequest;
use App\Modules\Inventory\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Services\StockMovementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockMovementController extends Controller
{
    public function __construct(private readonly StockMovementService $stock) {}

    /**
     * THE FIFTH INVENTORY LIST, reading its filters the same way the other
     * four do. It hand-rolled all three and had both defects those readers
     * exist to close: `(int) ['5']` is 1, so `?item_id[]=5` answered with
     * ITEM 1's movements and said nothing, and `(int) ['50']` is 1 too, so
     * `?per_page[]=50` served ONE ROW PER PAGE — the "list looks empty"
     * shape `perPage()` was written for.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StockMovementResource::collection($this->stock->paginateMovements(
            itemId: $this->filterId($request, 'item_id'),
            warehouseId: $this->filterId($request, 'warehouse_id'),
            perPage: $this->perPage($request),
        ));
    }

    public function receipt(StoreStockReceiptRequest $request): StockMovementResource
    {
        $data = $request->validated();

        $movement = $this->stock->recordReceipt(
            itemId: $data['item_id'],
            warehouseId: $data['warehouse_id'],
            quantity: (string) $data['quantity'],
            unitCost: (string) $data['unit_cost'],
            reference: $data['reference'] ?? null,
            movementDate: $data['movement_date'] ?? null,
            notes: $data['notes'] ?? null,
            createdBy: $request->user()?->id,
            batchId: $data['batch_id'] ?? null,
            serialNumberId: $data['serial_number_id'] ?? null,
        );

        return StockMovementResource::make($movement->load(['item', 'warehouse', 'batch', 'serialNumber']));
    }

    public function issue(StoreStockIssueRequest $request): StockMovementResource
    {
        $data = $request->validated();

        $movement = $this->stock->recordIssue(
            itemId: $data['item_id'],
            warehouseId: $data['warehouse_id'],
            quantity: (string) $data['quantity'],
            reference: $data['reference'] ?? null,
            movementDate: $data['movement_date'] ?? null,
            notes: $data['notes'] ?? null,
            createdBy: $request->user()?->id,
            batchId: $data['batch_id'] ?? null,
            serialNumberId: $data['serial_number_id'] ?? null,
        );

        return StockMovementResource::make($movement->load(['item', 'warehouse', 'batch', 'serialNumber']));
    }

    public function transfer(StoreStockTransferRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        [$out, $in] = $this->stock->recordTransfer(
            itemId: $data['item_id'],
            fromWarehouseId: $data['from_warehouse_id'],
            toWarehouseId: $data['to_warehouse_id'],
            quantity: (string) $data['quantity'],
            reference: $data['reference'] ?? null,
            movementDate: $data['movement_date'] ?? null,
            notes: $data['notes'] ?? null,
            createdBy: $request->user()?->id,
            batchId: $data['batch_id'] ?? null,
            serialNumberId: $data['serial_number_id'] ?? null,
        );

        return StockMovementResource::collection(collect([$out, $in])->each->load(['item', 'warehouse', 'batch', 'serialNumber']));
    }
}
