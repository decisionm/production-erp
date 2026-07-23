<?php

namespace App\Modules\Production\Events;

use App\Modules\Production\Models\ShiftProductionEntry;

/**
 * Raised when the accountant approves a shift's production (the §4a gate).
 * Only approved entries are ever eligible to sync — TallySync listens and
 * enqueues a Tally Manufacturing/Stock Journal. Production stays unaware of
 * TallySync.
 */
class ShiftProductionEntryApproved
{
    public function __construct(public readonly ShiftProductionEntry $entry) {}
}
