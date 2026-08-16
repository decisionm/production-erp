import Store from 'electron-store';
import logger from './logger';

/**
 * The agent's memory of vouchers it has already handed to Tally.
 *
 * Posting a voucher and telling the cloud about it are two different network
 * calls, and the gap between them is where a voucher gets posted twice: the
 * post succeeds, the ack never lands, the entry stays queued, and the next
 * cycle rebuilds the same voucher. Nothing in the cloud queue can close that
 * gap — only this machine knows what it actually sent — so the fact is
 * written down HERE, on disk, the moment Tally confirms the import and
 * before anything else is attempted.
 *
 * Persisted (electron-store, its own `post-journal.json` beside config.json
 * in the per-user app-data folder) rather than kept in memory, because the
 * agent restarting — a Windows reboot, an auto-update, the operator quitting
 * from the tray — is one of the likelier ways to lose an ack mid-flight.
 *
 * Three states, and the differences matter:
 *   posted     — Tally confirmed the import. The voucher is in the books;
 *                all that is left is to tell the cloud. Safe to ack.
 *   unverified — we sent the voucher and never got an answer (the request
 *                timed out). It may or may not be in the books. NOT safe to
 *                ack, NOT safe to re-post: a human has to look in Tally.
 *   rejected   — Tally answered and said no, so NOTHING was created. The
 *                cloud needs to hear that so the entry reaches the dashboard's
 *                failed list. Written down because the report itself can fail:
 *                during a deploy's maintenance window every cloud route
 *                answers 503, and without this record the next cycle sees only
 *                a delivered_at stamp it cannot explain and refuses the entry
 *                forever, invisibly (issue #168). `message` is kept so the
 *                retry reports the same reason Tally actually gave.
 */
export type PostOutcome = 'posted' | 'unverified' | 'rejected';

export interface PostRecord {
    entryId: number;
    outcome: PostOutcome;
    voucherNumber: string | null;
    at: string;
    /** Only set for `rejected` — what Tally said, so it can be re-reported verbatim. */
    message?: string;
}

interface JournalShape {
    records: PostRecord[];
}

const store = new Store<JournalShape>({
    name: 'post-journal',
    defaults: { records: [] },
});

function all(): PostRecord[] {
    return store.get('records');
}

function save(records: PostRecord[]): void {
    store.set('records', records);
}

/** What we know about this entry, if anything. */
export function lookup(entryId: number): PostRecord | undefined {
    return all().find((record) => record.entryId === entryId);
}

/**
 * Write down what happened to a voucher we sent. Called BEFORE the first
 * acknowledgement attempt, never after — if the process dies between the
 * post and the ack, this file is the only thing that stops the next cycle
 * from posting the same voucher again.
 */
export function record(
    entryId: number,
    outcome: PostOutcome,
    voucherNumber: string | null,
    message?: string,
): void {
    const records = all().filter((existing) => existing.entryId !== entryId);
    records.push({ entryId, outcome, voucherNumber, at: new Date().toISOString(), ...(message === undefined ? {} : { message }) });
    save(records);
}

/** Drop an entry once the cloud has confirmed it knows the voucher synced. */
export function forget(entryId: number): void {
    save(all().filter((record) => record.entryId !== entryId));
}

/**
 * Drop anything the cloud no longer considers pending. /pending returns the
 * whole queue (no pagination), so an id we're holding that isn't in the
 * freshly fetched list has left the queue — acked, failed, or resolved by
 * hand — and there is nothing left for us to say about it.
 */
export function prune(pendingIds: number[]): void {
    const live = new Set(pendingIds);
    const kept = all().filter((record) => live.has(record.entryId));

    if (kept.length !== all().length) {
        save(kept);
    }
}

/**
 * Startup breadcrumb: an operator reading the log after a restart should be
 * told immediately that something is mid-flight, and where the file is.
 */
export function logOutstandingOnStartup(): void {
    const records = all();

    if (records.length > 0) {
        logger.warn(
            `${records.length} voucher(s) were sent to Tally but never confirmed to the cloud — they will be resolved on the next poll`,
            { records, journal: store.path },
        );
    }
}
