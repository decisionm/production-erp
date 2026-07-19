<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\SerialNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SerialNumberService
{
    public function paginate(?int $itemId, int $perPage = 20): LengthAwarePaginator
    {
        return SerialNumber::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
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
