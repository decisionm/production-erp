<?php

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\SpcCharacteristic;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SpcCharacteristicService
{
    /** The columns the register sorts on besides id (ListSpcCharacteristicsRequest validates the same list). */
    public const SORTABLE = ['name', 'unit_of_measure', 'target_value'];

    /** By name unless `$sort` (a validated column, ListSort spelling) says otherwise. */
    public function paginate(?int $itemId, int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = SpcCharacteristic::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->with('item');

        return ListSort::apply($query, $sort, self::SORTABLE, 'name')->paginate($perPage);
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
