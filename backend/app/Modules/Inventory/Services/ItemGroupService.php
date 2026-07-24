<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ItemGroup;
use App\Support\Tally\HierarchyUpsert;

class ItemGroupService
{
    /**
     * @param  array<int, array{guid: string, name: string, parent?: string|null}>  $groups
     * @return array{created: int, updated: int, total: int}
     */
    public function syncFromTally(array $groups): array
    {
        return HierarchyUpsert::sync(ItemGroup::class, $groups);
    }
}
