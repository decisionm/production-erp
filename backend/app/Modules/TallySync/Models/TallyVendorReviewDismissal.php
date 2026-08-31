<?php

namespace App\Modules\TallySync\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A DIFFERENCE A PERSON HAS SET ASIDE — the only thing the vendor review
 * screen persists.
 *
 * The queue itself is computed from the ledger mirror against the vendor
 * master on every request, so it cannot go stale behind a re-sync. A
 * dismissal has to outlive the request, and it is scoped to the VALUE that
 * was dismissed: if Tally later carries a different GSTIN for that ledger,
 * this row no longer matches it and the difference is raised again. Setting
 * aside one fact must never blind the factory to the next one.
 */
#[Fillable(['tally_ledger_guid', 'field', 'dismissed_value', 'dismissed_by', 'dismissed_at'])]
class TallyVendorReviewDismissal extends Model
{
    /** The whole-row dismissal: "this ledger is not a vendor". */
    public const FIELD_ALL = '*';

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }
}
