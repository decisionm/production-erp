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
// gstin / state_name: the party details the masters pull brings across so a
// vendor can be made from a ledger without retyping them. Nullable and written
// only by LedgerSyncService, only from what Tally actually returned.
#[Fillable(['tally_guid', 'name', 'tally_group_name', 'ledger_group_id', 'gstin', 'state_name'])]
class Ledger extends Model
{
    use SoftDeletes;

    public function group(): BelongsTo
    {
        return $this->belongsTo(LedgerGroup::class, 'ledger_group_id');
    }
}
