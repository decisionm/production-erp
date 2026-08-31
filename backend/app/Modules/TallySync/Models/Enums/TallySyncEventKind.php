<?php

namespace App\Modules\TallySync\Models\Enums;

/**
 * The closed vocabulary of tally_sync_events.event.
 *
 * Where the agent file log (TallySyncAgentController::agentLog) already had
 * a name for something, that name is reused verbatim, so a reader can lay
 * the 30-day file beside the table and see the same words. The names that
 * are new here are the entry-side mutations that never had a trace beyond
 * the columns they overwrite.
 */
enum TallySyncEventKind: string
{
    /** A voucher entered the queue — batch mode, or a NEW shift voucher (including a -2/-3 follow-up). */
    case VoucherEnqueued = 'voucher.enqueued';

    /** Later approvals joined an existing, still-open shift voucher and its payload was rebuilt. */
    case VoucherMerged = 'voucher.merged';

    /** A payload rebuild (merge or retry) left members off the lines — recorded ONLY when something was excluded. */
    case VoucherRebuilt = 'voucher.rebuilt';

    /** Handed to the agent for the first time (delivered_at stamped) — the file log's own name. */
    case PendingDelivered = 'pending.delivered';

    /** The agent reported Tally accepted it. */
    case VoucherSynced = 'voucher.synced';

    /** The agent reported Tally rejected it. */
    case VoucherFailed = 'voucher.failed';

    /** The agent reported a failure for a voucher already in Tally; the service refused to mark it. */
    case VoucherFailureRefused = 'voucher.failure_refused';

    /** A person re-queued it (payload regenerated where a rebuilder exists). */
    case VoucherRetried = 'voucher.retried';

    /** A person wrote it off — it will never be sent to Tally. */
    case VoucherDismissed = 'voucher.dismissed';

    /** A person released a held shift voucher ahead of the gate. */
    case VoucherReleased = 'voucher.released';

    /**
     * A person pressed the ERP page's "Sync Now" — DEC-20260825-002's
     * queue-wide request that what is already queued go out on the agent's
     * next poll. Carries NO entry id: the request is about the queue, not
     * about one voucher, and the vouchers it actually frees get their own
     * voucher.released rows on their own timelines.
     *
     * Recorded on EVERY press, including the ones that free nothing — a
     * request made while the agent is offline, or with nothing held, would
     * otherwise leave no trace at all, and "who asked, and when" is the
     * whole audit question for a button that reaches the live books.
     */
    case SyncRequested = 'sync.requested';

    /** Tally → ERP: a masters pull landed. */
    case MastersReceived = 'masters.received';

    /** Tally → ERP: the instance bound itself to a Tally company (trust-on-first-use). */
    case CompanyBound = 'company.bound';

    /** Tally → ERP: the agent reported the companies it found. */
    case CompaniesReceived = 'companies.received';

    /**
     * Tally → ERP: purchase-order and purchase-invoice rate lines landed from
     * a Day Book read. Details carry the counts and the date window only —
     * NEVER a rate, a party or an item, because purchase rates and supplier
     * identity are Owner/Accounts (FC-06) and the event feed is not.
     */
    case PurchaseRatesReceived = 'purchase-rates.received';

    /**
     * Tally → ERP: the outstanding position landed from a Bills Receivable and
     * Sales Order Outstanding read. Details carry COUNTS AND THE AS-AT DATE
     * ONLY — never a party, a bill reference or an amount. What a named client
     * owes is Owner/Accounts (FC-06) and the event feed is not gated for it.
     */
    case ReceivablesReceived = 'receivables.received';

    /** Tally → ERP: a godown-wise stock summary was previewed and kept as a snapshot. */
    case StockSummaryPreviewed = 'stock-summary.previewed';

    /**
     * The agent uploaded a post-Tally snapshot — the XML it sent and what
     * Tally answered (tally_sync_snapshots; Phase 4). Details carry the
     * snapshot id, attempt, sha256, byte size, Tally's success flag, the
     * agent version and the payload verdict — NEVER the XML or Tally's
     * message text, which are reader-gated on the snapshot itself (FC-06).
     */
    case SnapshotStored = 'snapshot.stored';
}
