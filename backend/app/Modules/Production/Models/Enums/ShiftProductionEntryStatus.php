<?php

namespace App\Modules\Production\Models\Enums;

/**
 * The accountant's approval/Tally-sync workflow — a separate concern from
 * BatchStatus (whether the batch itself finished running).
 */
enum ShiftProductionEntryStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Synced = 'synced';
    case Failed = 'failed';
}
