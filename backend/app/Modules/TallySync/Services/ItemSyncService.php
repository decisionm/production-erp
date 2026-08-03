<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Services\ItemService;
use Illuminate\Support\Facades\DB;

/**
 * Inbound masters sync: takes the clean JSON stock-item list the local agent
 * pulls from Tally's XML gateway and upserts it into the ERP's item master.
 * The agent owns the Tally-XML translation; this side only ever sees plain
 * JSON (TALLY-SYNC-MASTER-PLAN.md §3). Item writes go through Inventory's
 * ItemService, never Item directly, per the module-boundary rule in CLAUDE.md.
 */
class ItemSyncService
{
    public function __construct(private readonly ItemService $items) {}

    /**
     * @param  array<int, array{guid: string, name: string, base_unit?: string|null, alter_id?: int|null}>  $tallyItems
     * @return array{created: int, updated: int, total: int}
     */
    /** @param  string|null  $company  the Tally company these items came from. */
    public function sync(array $tallyItems, ?string $company = null): array
    {
        $created = 0;
        $updated = 0;

        // One transaction for the whole batch: a mid-batch failure shouldn't
        // leave the item master half-updated for that pull cycle.
        DB::transaction(function () use ($tallyItems, $company, &$created, &$updated) {
            foreach ($tallyItems as $tallyItem) {
                $result = $this->items->upsertFromTally($tallyItem + ($company !== null ? ['company' => $company] : []));

                $result['created'] ? $created++ : $updated++;
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => count($tallyItems),
        ];
    }
}
