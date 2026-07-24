<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Tally ledger group, mirrored for reference/pick-lists. Self-referencing so
 * Tally's arbitrarily deep accounting hierarchy is preserved. Lives in the
 * TallySync mirror, separate from the ERP's own gl_accounts.
 */
#[Fillable(['tally_guid', 'name', 'tally_parent_name', 'parent_id'])]
class LedgerGroup extends Model
{
    use SoftDeletes;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(Ledger::class, 'ledger_group_id');
    }
}
