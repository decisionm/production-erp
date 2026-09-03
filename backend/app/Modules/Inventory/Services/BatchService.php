<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Http\Requests\ListBatchesRequest;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\StockMovementType;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class BatchService
{
    /**
     * `$search` matches the batch number or the item it belongs to (SKU or
     * name), and reaches archived and soft-deleted items on purpose: a batch
     * outlives its master's retirement, and a lot that cannot be found is a
     * lot nobody can trace.
     *
     * `$code` is the OTHER kind of question — "this exact printed number" —
     * and it is not a narrower `$search`. A substring search is capped by the
     * page size, so a scanned `LOT-4` sitting behind sixty newer numbers that
     * merely CONTAIN `LOT-4` is not on the page the scanner reads, and the
     * barcode this system printed answers "no match". Matching the whole
     * value instead means the page size stops mattering: the answer is the
     * handful of rows that ARE that number.
     *
     * `lower(...) = lower(...)` rather than a plain `=` on purpose. MySQL's
     * default collation compares case-insensitively and SQLite's `=` does
     * not, so a plain equality would resolve a lower-cased scan on production
     * and refuse it in every test — the same driver divergence
     * StockMovementService::assertIdentityBelongsToItem already warns about.
     * It costs the index on a table that holds one row per lot; a scan that
     * silently finds nothing costs more.
     *
     * `$itemId` is what the per-item picker reads, unchanged. `orderByDesc('id')`
     * is already a total order, so paging is stable without a tiebreaker.
     *
     * `item` is eager-loaded WITH TRASHED. Reaching a deleted item in the
     * `whereHas` only decides which rows come back; the eager load is a
     * second query with its own SoftDeletes scope, so without this the row
     * lists with a NULL item and the SKU the searcher typed is not on screen.
     */
    public function paginate(?int $itemId, int $perPage = 20, ?string $search = null, ?string $code = null, ?string $sort = null): LengthAwarePaginator
    {
        $query = Batch::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($code !== null, fn ($query) => $query->whereRaw(
                'lower(batch_number) = ?', [Str::lower($code)]
            ))
            ->when($search !== null, function ($query) use ($search) {
                $like = "%{$search}%";
                $query->where(fn ($outer) => $outer
                    ->where('batch_number', 'like', $like)
                    ->orWhereHas('item', fn ($item) => $item->withTrashed()
                        ->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like))));
            })
            ->with(['item' => fn ($item) => $item->withTrashed()]);

        // Newest first unless asked otherwise; an undated batch sorts last
        // on either date, whichever way the column points.
        return ListSort::apply($query, $sort, ListBatchesRequest::SORTABLE, '-id', ListBatchesRequest::NULLABLE_DATES)
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
        $movements = $batch->movements()->with(['item' => fn ($item) => $item->withTrashed(), 'warehouse'])->orderBy('movement_date')->orderBy('id')->get();

        $onHandByWarehouse = [];
        foreach ($movements as $movement) {
            $sign = in_array($movement->type, [StockMovementType::Receipt, StockMovementType::TransferIn], true) ? '1' : '-1';
            $delta = bcmul((string) $movement->quantity, $sign, 4);
            $onHandByWarehouse[$movement->warehouse_id] = bcadd($onHandByWarehouse[$movement->warehouse_id] ?? '0', $delta, 4);
        }

        $warehouses = Warehouse::query()->whereIn('id', array_keys($onHandByWarehouse))->get()->keyBy('id');

        return [
            'batch' => $batch->load(['item' => fn ($item) => $item->withTrashed()]),
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
