<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\SpcCharacteristic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SpcCharacteristicService
{
    public function paginate(?int $itemId, int $perPage = 20): LengthAwarePaginator
    {
        return SpcCharacteristic::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->with('item')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * @param  array{item_id: int, name: string, unit_of_measure?: string, target_value?: string, lower_spec_limit?: string, upper_spec_limit?: string}  $data
     */
    public function create(array $data): SpcCharacteristic
    {
        return SpcCharacteristic::create([
            'is_active' => true,
            ...$data,
        ])->load('item');
    }
}
