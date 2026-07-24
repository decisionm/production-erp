<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\Enums\TallyLedgerRole;
use App\Modules\TallySync\Models\TallyLedgerMapping;
use Illuminate\Support\Facades\DB;

/**
 * Manages the role → Tally-ledger-name mappings the voucher builders read
 * instead of hardcoding ledger names. Always presents the full set of known
 * roles (mapped or not) so the Settings UI can show every one.
 */
class TallyLedgerMappingService
{
    /** @return array<string, string|null> role => tally ledger name (null if unmapped) */
    public function all(): array
    {
        $existing = TallyLedgerMapping::query()->pluck('tally_ledger_name', 'role');

        $result = [];
        foreach (TallyLedgerRole::cases() as $role) {
            $result[$role->value] = $existing[$role->value] ?? null;
        }

        return $result;
    }

    public function get(TallyLedgerRole $role): ?string
    {
        return TallyLedgerMapping::query()->where('role', $role->value)->value('tally_ledger_name');
    }

    /**
     * Bulk upsert. Unknown roles are ignored (defensive — the role set is
     * owned by TallyLedgerRole, not the request). Blank values clear a mapping.
     *
     * @param  array<string, string|null>  $mappings
     */
    public function setMany(array $mappings): void
    {
        DB::transaction(function () use ($mappings): void {
            foreach ($mappings as $role => $ledgerName) {
                if (TallyLedgerRole::tryFrom((string) $role) === null) {
                    continue;
                }

                $name = is_string($ledgerName) ? trim($ledgerName) : '';

                TallyLedgerMapping::query()->updateOrCreate(
                    ['role' => $role],
                    ['tally_ledger_name' => $name !== '' ? $name : null],
                );
            }
        });
    }
}
