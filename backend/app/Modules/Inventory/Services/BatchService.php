<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class BatchService
{
    /**
     * `$search` matches the batch number or the item it belongs to (SKU or
     * name), and reaches archived and soft-deleted items on purpose: a batch
     * outlives its master's retirement, and a lot that cannot be found is a
     * lot nobody can trace.
     *
     * `$itemId` is what the per-item picker reads, unchanged. `orderByDesc('id')`
     * is already a total order, so paging is stable without a tiebreaker.
     */
    public function paginate(?int $itemId, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        return Batch::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($search !== null, function ($query) use ($search) {
                $like = "%{$search}%";
                $query->where(fn ($outer) => $outer
                    ->where('batch_number', 'like', $like)
                    ->orWhereHas('item', fn ($item) => $item->withTrashed()
                        ->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like))));
            })
            ->with('item')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{item_id: int, batch_number: string, manufactured_date?: string, expiry_date?: string, notes?: string}  $data
     */
    public function create(array $data): Batch
    {
        return Batch::create($data)->load('item');
    }

    /**
     * Where a batch currently sits and how it got there — derived entirely
     * from the movements tagged with it, not a running balance (see
     * StockMovementService).
     *
     * @return array{batch: Batch, on_hand: array<int, array{warehouse_id: int, warehouse_code: ?string, quantity: string}>, movements: Collection}
     */
    public function ledger(Batch $batch): array
    {
        $movements = $batch->movements()->with(['item', 'warehouse'])->orderBy('movement_date')->orderBy('id')->get();

        $onHandByWarehouse = [];
        foreach ($movements as $movement) {
            $sign = in_array($movement->type, [StockMovementType::Receipt, StockMovementType::TransferIn], true) ? '1' : '-1';
            $delta = bcmul((string) $movement->quantity, $sign, 4);
            $onHandByWarehouse[$movement->warehouse_id] = bcadd($onHandByWarehouse[$movement->warehouse_id] ?? '0', $delta, 4);
        }

        $warehouses = Warehouse::query()->whereIn('id', array_keys($onHandByWarehouse))->get()->keyBy('id');

        return [
            'batch' => $batch->load('item'),
            'on_hand' => collect($onHandByWarehouse)
                ->map(fn ($quantity, $warehouseId) => [
                    'warehouse_id' => (int) $warehouseId,
                    'warehouse_code' => $warehouses->get($warehouseId)?->code,
                    'quantity' => $quantity,
                ])
                ->values()
                ->all(),
            'movements' => $movements,
        ];
    }
}
