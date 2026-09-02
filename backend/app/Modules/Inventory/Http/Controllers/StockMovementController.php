<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\ListStockMovementsRequest;
use App\Modules\Inventory\Http\Requests\StoreStockIssueRequest;
use App\Modules\Inventory\Http\Requests\StoreStockReceiptRequest;
use App\Modules\Inventory\Http\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Models\Enums\StockMovementPurpose;
use App\Modules\Inventory\Services\StockMovementService;
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
     *
     * `q` — the reference needle — is ListStockMovementsRequest's; the three
     * readers below keep their own refusal rules.
     */
    public function index(ListStockMovementsRequest $request): AnonymousResourceCollection
    {
        return StockMovementResource::collection($this->stock->paginateMovements(
            itemId: $this->filterId($request, 'item_id'),
            warehouseId: $this->filterId($request, 'warehouse_id'),
            // WHY the ledger can now be narrowed by purpose: the column and
            // its index were added by 2026_08_17_150000 for "the reads this
            // exists for group and filter the ledger by purpose", and until
            // now nothing could use either. The Store <-> Production history
            // is the first caller — one chronological list of issues and
            // returns — but the capability is general, not that screen's
            // private door (CLAUDE.md decision 3: the API is a product
            // surface, not the SPA's implementation detail).
            purposes: $this->filterEnumList($request, 'purpose', StockMovementPurpose::class),
            perPage: $this->perPage($request),
            reference: $request->reference(),
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
