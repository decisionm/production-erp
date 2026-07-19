<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WarehouseService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Warehouse::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function count(): int
    {
        return Warehouse::query()->count();
    }

    public function create(array $data): Warehouse
    {
        // Explicit here rather than relying on the DB column default: Eloquent's
        // create() doesn't re-fetch DB-applied defaults into the returned model.
        return Warehouse::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        $warehouse->update($data);

        return $warehouse;
    }
}
