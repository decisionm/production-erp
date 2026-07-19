<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Routing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RoutingService
{
    public function paginate(?int $itemId, int $perPage = 20): LengthAwarePaginator
    {
        return Routing::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->with(['item', 'operations.workCenter'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{item_id: int, name: string, is_active?: bool, operations: array<int, array{work_center_id: int, sequence: int, name: string, standard_time_minutes?: string}>}  $data
     */
    public function create(array $data): Routing
    {
        return DB::transaction(function () use ($data) {
            $routing = Routing::create([
                'item_id' => $data['item_id'],
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            foreach ($data['operations'] as $operation) {
                $routing->operations()->create([
                    'work_center_id' => $operation['work_center_id'],
                    'sequence' => $operation['sequence'],
                    'name' => $operation['name'],
                    'standard_time_minutes' => $operation['standard_time_minutes'] ?? null,
                ]);
            }

            return $routing->load(['item', 'operations.workCenter']);
        });
    }
}
