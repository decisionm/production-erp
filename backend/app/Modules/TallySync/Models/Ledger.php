<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Tally ledger (leaf account), mirrored so the Settings UI can offer a
 * pick-list when mapping posting roles → real ledger names (see
 * TallyLedgerMapping). Belongs to a LedgerGroup (its accounting parent).
 */
// gstin / state_name / email / phone: the party details the masters pull brings
// across so a vendor can be made from a ledger without retyping them. Nullable
// and written only by LedgerSyncService, only from what Tally actually
// returned. MEASURED coverage in the live company's own All Masters export
// (1742 ledgers): 665 carry a GSTIN, 78 a phone, and 4 an email. Email and
// phone will therefore be null on almost every row, and that is the state of
// the books rather than a fault in the pull.
//
// tally_synced_at is stamped by the pull that wrote the row, and is what the
// screens mean by "synced". `updated_at` cannot answer that: it moves on every
// sync whether or not a value changed.
#[Fillable(['tally_guid', 'name', 'tally_group_name', 'ledger_group_id', 'gstin', 'state_name', 'email', 'phone', 'tally_synced_at'])]
class Ledger extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'tally_synced_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LedgerGroup::class, 'ledger_group_id');
    }
}
