<?php

namespace App\Modules\Production\Events;

use App\Modules\Production\Models\ShiftProductionEntry;

/**
 * Raised when a batch's completion has been COMMITTED — the counts, the
 * kilograms at the run's frozen weight, the consumption issues, the packing
 * lines, the downtime and the finished-goods receipt are all in the database
 * by the time anything hears this. Raised again by an amendment, which is a
 * reversal followed by the ordinary completion (amendCompletion) — one event
 * per completion that stood, so a listener sees a corrected batch exactly as
 * it saw the first count. A handover raises it for the segment it closes
 * (handover() completes the outgoing segment through completeBatch), never
 * for the child it opens. Never raised by a start, a cancellation, or a
 * completion that rolled back.
 *
 * IT IS NOT A TALLY TRIGGER, and nothing may make it one. Only APPROVED
 * production ever reaches Tally (the §4a gate); ShiftProductionEntryApproved
 * is the event TallySync listens to, and it stays the only one. This event
 * exists for the things a completed-but-unapproved batch is already good
 * for — labels, cartons, the floor's own notifications, reports — and
 * CompletionEventAndDefaultsTest pins that dispatching it enqueues no voucher.
 *
 * Dispatched through DB::afterCommit from inside completeBatch's transaction,
 * so an amendment (whose outer transaction wraps completeBatch) raises it
 * only once ITS commit lands, not at the inner return.
 */
class ShiftProductionEntryCompleted
{
    public function __construct(public readonly ShiftProductionEntry $entry) {}
}
