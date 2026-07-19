<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Bom;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BomService
{
    public function paginate(?int $itemId, int $perPage = 20): LengthAwarePaginator
    {
        return Bom::query()
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->with(['item', 'lines.component'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * The BOM currently in effect for an item — used by Production (work
     * order creation) and MRP (explosion) whenever a caller doesn't pin a
     * specific BOM/version explicitly.
     */
    public function activeFor(int $itemId): ?Bom
    {
        return Bom::query()
            ->where('item_id', $itemId)
            ->where('is_active', true)
            ->with('lines')
            ->first();
    }

    /**
     * @param  array{item_id: int, name: string, version?: string, is_active?: bool, lines: array<int, array{component_item_id: int, quantity_per: string}>}  $data
     */
    public function create(array $data): Bom
    {
        return DB::transaction(function () use ($data) {
            $isActive = $data['is_active'] ?? true;

            // Only one active BOM per item at a time — a new active BOM
            // supersedes whichever one was active before it, rather than
            // requiring the caller to deactivate it first.
            if ($isActive) {
                Bom::query()->where('item_id', $data['item_id'])->where('is_active', true)->update(['is_active' => false]);
            }

            $bom = Bom::create([
                'item_id' => $data['item_id'],
                'name' => $data['name'],
                'version' => $data['version'] ?? '1',
                'is_active' => $isActive,
            ]);

            foreach ($data['lines'] as $line) {
                $bom->lines()->create([
                    'component_item_id' => $line['component_item_id'],
                    'quantity_per' => $line['quantity_per'],
                ]);
            }

            return $bom->load(['item', 'lines.component']);
        });
    }
}
