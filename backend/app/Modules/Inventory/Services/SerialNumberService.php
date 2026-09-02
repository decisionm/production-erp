<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Http\Requests\ListSerialNumbersRequest;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\SerialNumber;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class SerialNumberService
{
    /**
     * `$search` matches the serial number itself or the item it belongs to
     * (SKU or name) — the same shape as BatchService::paginate, and archived
     * and soft-deleted items are reached for the same reason: the unit exists
     * whatever its master's state.
     *
     * `$code` matches the WHOLE serial number, for the same reason and with
     * the same case handling as BatchService::paginate — see the note there.
     * A scanner asks "is this exact unit ours"; a substring search answers a
     * different question, and answers it only as far as the page size reaches.
     *
     * `item` is eager-loaded WITH TRASHED for the reason spelled out there
     * too: the `whereHas` decides which rows come back, the eager load is a
     * separate query, and a unit of a deleted item was listing with a null
     * item — unreadable on the very screen that has to trace it.
     */
    public function paginate(?int $itemId, int $perPage = 20, ?string $search = null, ?string $code = null, ?string $sort = null): LengthAwarePaginator
    {
        $query = SerialNumber::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($code !== null, fn ($query) => $query->whereRaw(
                'lower(serial_number) = ?', [Str::lower($code)]
            ))
            ->when($search !== null, function ($query) use ($search) {
                $like = "%{$search}%";
                $query->where(fn ($outer) => $outer
                    ->where('serial_number', 'like', $like)
                    ->orWhereHas('item', fn ($item) => $item->withTrashed()
                        ->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like))));
            })
            ->with(['item' => fn ($item) => $item->withTrashed(), 'warehouse']);

        return ListSort::apply($query, $sort, ListSerialNumbersRequest::SORTABLE, '-id')
            ->paginate($perPage);
    }

    /**
     * @param  array{item_id: int, serial_number: string}  $data
     */
    public function create(array $data): SerialNumber
    {
        return SerialNumber::create([
            'status' => SerialNumberStatus::Registered->value,
            'warehouse_id' => null,
            ...$data,
        ])->load(['item', 'warehouse']);
    }

    public function history(SerialNumber $serialNumber): SerialNumber
    {
        return $serialNumber->load(['item' => fn ($item) => $item->withTrashed(), 'warehouse', 'movements' => fn ($query) => $query->with('warehouse')->orderBy('movement_date')->orderBy('id')]);
    }
}
