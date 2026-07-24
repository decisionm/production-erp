<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\LedgerGroup;
use App\Support\Tally\HierarchyUpsert;

/**
 * Mirrors Tally's chart of accounts (ledger groups + ledgers) into the TallySync
 * tables that back the Settings pick-lists. Kept out of the ERP's own gl_accounts
 * so pulling a client's COA never disturbs native accounting.
 */
class LedgerSyncService
{
    /**
     * @param  array<int, array{guid: string, name: string, parent?: string|null}>  $groups
     * @return array{created: int, updated: int, total: int}
     */
    public function syncGroups(array $groups): array
    {
        return HierarchyUpsert::sync(LedgerGroup::class, $groups);
    }

    /**
     * Ledgers are leaves under a ledger group (not self-referencing). Matched on
     * GUID; the group link resolves by name, null until that group is pulled.
     *
     * @param  array<int, array{guid: string, name: string, group?: string|null}>  $ledgers
     * @return array{created: int, updated: int, total: int}
     */
    public function syncLedgers(array $ledgers): array
    {
        $created = 0;
        $updated = 0;

        foreach ($ledgers as $row) {
            $groupName = $row['group'] ?? null;
            $groupId = $groupName !== null ? LedgerGroup::where('name', $groupName)->value('id') : null;

            $ledger = Ledger::withTrashed()->where('tally_guid', $row['guid'])->first();

            if ($ledger !== null) {
                $ledger->fill([
                    'name' => $row['name'],
                    'tally_group_name' => $groupName,
                    'ledger_group_id' => $groupId,
                ]);
                if ($ledger->trashed()) {
                    $ledger->restore();
                }
                $ledger->save();
                $updated++;

                continue;
            }

            Ledger::create([
                'tally_guid' => $row['guid'],
                'name' => $row['name'],
                'tally_group_name' => $groupName,
                'ledger_group_id' => $groupId,
            ]);
            $created++;
        }

        return ['created' => $created, 'updated' => $updated, 'total' => count($ledgers)];
    }
}
