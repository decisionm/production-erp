<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\SerialNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SerialNumberService
{
    /**
     * `$search` matches the serial number itself or the item it belongs to
     * (SKU or name) — the same shape as BatchService::paginate, and archived
     * and soft-deleted items are reached for the same reason: the unit exists
     * whatever its master's state.
     */
    public function paginate(?int $itemId, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        return SerialNumber::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($search !== null, function ($query) use ($search) {
                $like = "%{$search}%";
                $query->where(fn ($outer) => $outer
                    ->where('serial_number', 'like', $like)
                    ->orWhereHas('item', fn ($item) => $item->withTrashed()
                        ->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like))));
            })
            ->with(['item', 'warehouse'])
            ->orderByDesc('id')
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
        return $serialNumber->load(['item', 'warehouse', 'movements' => fn ($query) => $query->with('warehouse')->orderBy('movement_date')->orderBy('id')]);
    }
}
