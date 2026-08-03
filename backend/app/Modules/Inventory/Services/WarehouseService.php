<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Support\Tally\HierarchyUpsert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

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

    /**
     * One warehouse by id, or null when it does not exist (or was soft
     * deleted). The cross-module read other modules use instead of touching
     * Inventory's models — e.g. Production resolving the configured factory
     * day-bin warehouse, which must survive that warehouse being retired
     * rather than blow up mid-shift.
     */
    public function find(int $id): ?Warehouse
    {
        return Warehouse::query()->find($id);
    }

    /**
     * Upsert Tally godowns as warehouses. Godowns can nest, so the same
     * self-referencing hierarchy resolution is used; a warehouse `code` (unique,
     * required) is generated from the name for new ones — staff can rename later.
     *
     * @param  array<int, array{guid: string, name: string, parent?: string|null}>  $godowns
     * @return array{created: int, updated: int, total: int}
     */
    /**
     * @param  string|null  $company  the Tally company these godowns came from.
     *                                Recorded on every row: without it a
     *                                foreign company's godown is
     *                                indistinguishable from a real one, which
     *                                is how six of them came to be live here.
     */
    public function syncGodownsFromTally(array $godowns, ?string $company = null): array
    {
        return HierarchyUpsert::sync(Warehouse::class, $godowns, fn (array $row): array => array_filter([
            'code' => $this->uniqueCodeFrom($row['name']),
            'is_active' => true,
            'tally_company' => $company,
        ], fn ($value) => $value !== null));
    }

    private function uniqueCodeFrom(string $name): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $base = $base !== '' ? $base : 'GDN';
        $code = $base;

        for ($i = 2; Warehouse::withTrashed()->where('code', $code)->exists(); $i++) {
            $code = "{$base}-{$i}";
        }

        return $code;
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
