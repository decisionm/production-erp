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
                    ...self::partyDetails($row),
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
                ...self::partyDetails($row),
            ]);
            $created++;
        }

        return ['created' => $created, 'updated' => $updated, 'total' => count($ledgers)];
    }

    /**
     * The party details of a pull, ABSENT-MEANS-LEAVE-ALONE.
     *
     * A key the agent did not send is omitted from the returned array, so fill()
     * never touches that column. The direction is the point: the exact Tally
     * spelling of a ledger's GSTIN and state fields is not proven by any export
     * in this repository, so the agent reads several candidate tags and sends
     * nothing when it finds nothing. A wrong guess at a field name must
     * therefore cost an EMPTY column, never a recorded GSTIN wiped by the next
     * sync.
     *
     * An explicit null still clears, because that is Tally saying the ledger
     * has no GSTIN rather than the agent saying it did not look.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string|null>
     */
    private static function partyDetails(array $row): array
    {
        $details = [];

        foreach (['gstin', 'state_name'] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = is_string($row[$key]) ? trim($row[$key]) : null;
            $details[$key] = $value !== null && $value !== '' ? $value : null;
        }

        return $details;
    }
}
