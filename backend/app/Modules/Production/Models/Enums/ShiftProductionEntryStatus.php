<?php

namespace App\Modules\Production\Models\Enums;

/**
 * The 4-stage approval chain (factory answer 9) + the Tally-sync tail — a
 * separate concern from BatchStatus (whether the batch itself finished
 * running). Flow:
 *
 *   pending → pm_approved → accountant_approved → approved (MD) → synced
 *                                                              ↘ failed
 *   (rejected at any pre-MD stage → rejected, back to the supervisor)
 *
 * 'approved' is deliberately the MD's FINAL approval: the Tally enqueue and
 * the agent's synced/failed write-back already key off that value, so the
 * chain slots in front of the existing sync machinery without touching it.
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
