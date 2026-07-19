<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ItemService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Item::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): Item
    {
        // Explicit here rather than relying on the DB column default: Eloquent's
        // create() doesn't re-fetch DB-applied defaults into the returned model.
        return Item::create([
            'reorder_level' => 0,
            'tracking_type' => 'none',
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(Item $item, array $data): Item
    {
        $item->update($data);

        return $item;
    }
}
