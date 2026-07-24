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
#[Fillable(['tally_guid', 'name', 'tally_group_name', 'ledger_group_id'])]
class Ledger extends Model
{
    use SoftDeletes;

    public function group(): BelongsTo
    {
        return $this->belongsTo(LedgerGroup::class, 'ledger_group_id');
    }
}
