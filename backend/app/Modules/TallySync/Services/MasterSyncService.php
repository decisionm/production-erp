<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Services\ItemGroupService;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Support\Facades\DB;

/**
 * One entry point for the agent's full masters pull. Accepts any subset of the
 * master types and upserts them in dependency order (groups before the leaves
 * that reference them) inside a single transaction. Each section is delegated to
 * the owning module's service, per the cross-module-writes rule in CLAUDE.md.
 * Every section is optional and idempotent, so partial pulls and re-pulls are
 * both safe.
 */
class MasterSyncService
{
    public function __construct(
        private readonly ItemGroupService $itemGroups,
        private readonly WarehouseService $warehouses,
        private readonly ItemSyncService $items,
        private readonly LedgerSyncService $ledgers,
    ) {}

    /**
     * @param  array{
     *     item_groups?: array<int, array<string, mixed>>,
     *     godowns?: array<int, array<string, mixed>>,
     *     ledger_groups?: array<int, array<string, mixed>>,
     *     ledgers?: array<int, array<string, mixed>>,
     *     items?: array<int, array<string, mixed>>
     * }  $payload
     * @return array<string, array{created: int, updated: int, total: int}>
     */
    /**
     * @param  string|null  $company  the Tally company this payload came from,
     *                                stamped onto every row it writes. Null only
     *                                for an older agent that does not send it —
     *                                and an unstamped row is exactly the state
     *                                the six foreign godowns were found in.
     */
    public function sync(array $payload, ?string $company = null): array
    {
        $summary = [];

        DB::transaction(function () use ($payload, $company, &$summary): void {
            // Order matters: parents/groups first so the leaves that reference
            // them (items → item_groups, ledgers → ledger_groups) resolve links.
            if (! empty($payload['item_groups'])) {
                $summary['item_groups'] = $this->itemGroups->syncFromTally($payload['item_groups']);
            }

            if (! empty($payload['godowns'])) {
                $summary['godowns'] = $this->warehouses->syncGodownsFromTally($payload['godowns'], $company);
            }

            if (! empty($payload['ledger_groups'])) {
                $summary['ledger_groups'] = $this->ledgers->syncGroups($payload['ledger_groups']);
            }

            if (! empty($payload['ledgers'])) {
                $summary['ledgers'] = $this->ledgers->syncLedgers($payload['ledgers']);
            }

            if (! empty($payload['items'])) {
                $summary['items'] = $this->items->sync($payload['items'], $company);
            }
        });

        return $summary;
    }
}
