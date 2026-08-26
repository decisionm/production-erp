<?php

namespace App\Modules\Production\Models\Enums;

/**
 * The life of a production request — what the SALES side is owed, tracked
 * as paperwork.
 *
 *   queued       on the floor's worklist, nobody has picked it up
 *   in_progress  somebody said they have picked it up
 *   produced     the order line is covered; nothing further is owed
 *   cancelled    withdrawn, with a reason
 *
 * NONE OF THESE IS A BATCH. `in_progress` is a person pressing Start on the
 * QUEUE, never the ERP noticing a shift entry: this build creates, starts
 * and cancels no batches at all (invariant 2) and holds no FK into
 * shift_production_entries.
 *
 * `produced` IS JUDGED ON THE ORDER LINE, NEVER ON THIS ROW (S1). A request
 * for 10 pieces is not "produced" because 10 pieces appeared somewhere — it
 * is produced when the LINE it serves is covered by holds plus deliveries.
 * Reserving 90 free pieces against a 100-piece line with a 10-piece request
 * outstanding leaves that request exactly where it was; the coverage is 90.
 * See ProductionRequestService::markProducedIfCovered.
 *
 * Deliberately NOT MaterialRequestStatus: that enum is fulfilled in PARTS by
 * the store (draft/submitted/partially_issued/issued) and faces the other
 * direction entirely.
 */
enum ProductionRequestStatus: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Produced = 'produced';
    case Cancelled = 'cancelled';

    /**
     * Still owed — the ONE definition of "open", and the one the
     * one-open-request-per-line rule is written against.
     */
    public function isOpen(): bool
    {
        return $this === self::Queued || $this === self::InProgress;
    }

    /** Finished for good — nothing further happens to this request. */
    public function isFinal(): bool
    {
        return $this === self::Produced || $this === self::Cancelled;
    }
}
