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

    public function index(Request $request): AnonymousResourceCollection
    {
        return StockMovementResource::collection($this->stock->paginateMovements(
            itemId: $request->integer('item_id') ?: null,
            warehouseId: $request->integer('warehouse_id') ?: null,
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
        );

        return StockMovementResource::make($movement->load(['item', 'warehouse']));
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
        );

        return StockMovementResource::make($movement->load(['item', 'warehouse']));
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
        );

        return StockMovementResource::collection(collect([$out, $in])->each->load(['item', 'warehouse']));
    }
}
