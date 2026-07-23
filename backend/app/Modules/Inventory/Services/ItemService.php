<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ItemService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Item::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function count(): int
    {
        return Item::query()->count();
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

    /**
     * Upsert an item pulled from Tally, matched on its stable GUID (never on
     * name — Tally names get renamed and contain spaces/slashes). ERP-only
     * fields (tracking_type, reorder_level, description) are left untouched on
     * update, since Tally has no concept of them — see the split-ownership rule
     * in TALLY-SYNC-MASTER-PLAN.md §3. `parent` is the item's Tally stock-group
     * name; it's resolved to item_group_id if that group has been pulled.
     *
     * @param  array{guid: string, name: string, base_unit?: string|null, alter_id?: int|null, parent?: string|null}  $data
     * @return array{item: Item, created: bool}
     */
    public function upsertFromTally(array $data): array
    {
        $groupId = $this->resolveGroupId($data['parent'] ?? null);

        $item = Item::withTrashed()->where('tally_stock_item_guid', $data['guid'])->first();

        if ($item !== null) {
            $item->fill([
                'name' => $data['name'],
                'uom' => $data['base_unit'] ?? $item->uom,
                'tally_alter_id' => $data['alter_id'] ?? $item->tally_alter_id,
                'tally_synced_at' => now(),
                'item_group_id' => $groupId ?? $item->item_group_id,
            ]);

            // A previously deleted item reappearing in Tally means it's live
            // again — restore rather than silently leaving it soft-deleted.
            if ($item->trashed()) {
                $item->restore();
            }

            $item->save();

            return ['item' => $item, 'created' => false];
        }

        $item = $this->create([
            'sku' => $this->uniqueSkuFrom($data['name']),
            'name' => $data['name'],
            'uom' => $data['base_unit'] ?? 'PCS',
            'tally_stock_item_guid' => $data['guid'],
            'tally_alter_id' => $data['alter_id'] ?? null,
            'tally_synced_at' => now(),
            'item_group_id' => $groupId,
        ]);

        return ['item' => $item, 'created' => true];
    }

    /** Match a Tally stock-group name to a pulled ItemGroup; null if not yet pulled. */
    private function resolveGroupId(?string $groupName): ?int
    {
        $groupName = $groupName !== null ? trim($groupName) : null;

        if ($groupName === null || $groupName === '' || $groupName === 'Primary') {
            return null;
        }

        return ItemGroup::where('name', $groupName)->value('id');
    }

    /**
     * Seed a new Tally-sourced item's SKU from its name (staff can rename it
     * afterwards — SKU stays ERP-editable, §3). SKU is unique, so suffix a
     * counter on the rare collision with an existing item.
     */
    private function uniqueSkuFrom(string $name): string
    {
        $base = trim($name) !== '' ? trim($name) : 'ITEM';
        $sku = $base;

        for ($i = 2; Item::withTrashed()->where('sku', $sku)->exists(); $i++) {
            $sku = "{$base}-{$i}";
        }

        return $sku;
    }
}
