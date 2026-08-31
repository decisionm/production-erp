<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Ledger;
use App\Modules\TallySync\Models\LedgerGroup;
use App\Support\Tally\HierarchyUpsert;
use App\Support\Tally\TallyText;
use Illuminate\Support\Carbon;

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

        // ONE STAMP FOR THE WHOLE PULL, taken before the loop. Every row this
        // call writes carries the same instant, so "synced at" describes the
        // pull rather than how far down the list a row happened to be — and
        // two ledgers read from Tally together never appear to disagree about
        // when they were last confirmed.
        $syncedAt = Carbon::now();

        foreach ($ledgers as $row) {
            $groupName = $row['group'] ?? null;
            $groupId = $groupName !== null ? LedgerGroup::where('name', $groupName)->value('id') : null;

            $ledger = Ledger::withTrashed()->where('tally_guid', $row['guid'])->first();

            if ($ledger !== null) {
                $ledger->fill([
                    'name' => $row['name'],
                    'tally_group_name' => $groupName,
                    'ledger_group_id' => $groupId,
                    'tally_synced_at' => $syncedAt,
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
                'tally_synced_at' => $syncedAt,
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
     * EVERY VALUE IS CLEANED HERE, not merely trimmed, and this is where the
     * 31-Aug-2026 outage is actually fixed. Tally exports numeric character
     * references — `&#13;&#10;` on a value someone pressed Enter in — and
     * `fast-xml-parser` does not decode them, so what arrives is those ten
     * characters LITERALLY, all printable, past any control-character strip.
     * TallyText decodes them, which RECOVERS the real GSTIN rather than
     * discarding it; a value still unusable afterwards is dropped on its own,
     * leaving the other 1741 ledgers to sync.
     *
     * EMAIL AND PHONE JOIN ON THE SAME CONTRACT and for the same reason. The
     * live All Masters export shows the contact tags are sparse (78 phones and
     * 4 emails across 1742 ledgers) and spelled several ways, so the agent
     * reads a candidate list and sends nothing when it finds nothing. A field
     * name guessed wrong must cost an empty column, never a contact detail a
     * person typed into the vendor form.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string|null>
     */
    private static function partyDetails(array $row): array
    {
        $details = [];

        // The column each value has to fit, and how it is judged. A value that
        // does not fit becomes NULL for that field on that ledger — never a
        // truncation (a shortened GSTIN or phone number is a WRONG one, and
        // wrong is worse than absent) and never a refusal of the whole pull.
        $fields = [
            'gstin' => fn (mixed $v): ?string => TallyText::gstin($v),
            'state_name' => fn (mixed $v): ?string => TallyText::fitting($v, 255),
            'email' => fn (mixed $v): ?string => TallyText::fitting($v, 255),
            'phone' => fn (mixed $v): ?string => TallyText::fitting($v, 255),
        ];

        foreach ($fields as $key => $judge) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $details[$key] = $judge($row[$key]);
        }

        return $details;
    }
}
