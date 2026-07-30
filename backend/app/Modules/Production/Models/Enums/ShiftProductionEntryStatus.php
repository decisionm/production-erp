<?php

namespace App\Modules\Production\Models\Enums;

/**
 * The approval chain + the Tally-sync tail — a separate concern from
 * BatchStatus (whether the batch itself finished running). Flow:
 *
 *   pending → pm_approved → approved (accountant) → synced
 *                                                 ↘ failed
 *   (rejected at either pre-approval stage → rejected, back to the supervisor)
 *
 * THE ACCOUNTANT IS FINAL. Their approval is the posting gate: the entry
 * becomes eligible for Tally immediately, so production quantities reach the
 * books the same shift with no MD wait. There is no MD approval step — the MD
 * gets a live read-only view of production instead of a queue to clear.
 *
 * `accountant_approved` is retained as a value but is never written. It backed
 * a fourth MD gate that was removed; keeping the case means any historical row
 * still carrying it continues to cast rather than blowing up on read.
 */
enum ShiftProductionEntryStatus: string
{
    case Pending = 'pending';
    case PmApproved = 'pm_approved';
    case AccountantApproved = 'accountant_approved';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Synced = 'synced';
    case Failed = 'failed';
}
