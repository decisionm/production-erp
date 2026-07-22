<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ShiftStockCount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShiftStockCountService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return ShiftStockCount::query()
            ->with(['shift', 'item', 'createdBy'])
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{shift_id: int, production_date?: string, location_label: string, item_id: int, quantity_kg: string}  $data
     */
    public function create(array $data, ?int $createdBy): ShiftStockCount
    {
        $count = ShiftStockCount::create([
            'shift_id' => $data['shift_id'],
            'production_date' => $data['production_date'] ?? now()->toDateString(),
            'location_label' => $data['location_label'],
            'item_id' => $data['item_id'],
            'quantity_kg' => $data['quantity_kg'],
            'created_by' => $createdBy,
        ]);

        return $count->fresh(['shift', 'item']);
    }
}
