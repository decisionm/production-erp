import Store from 'electron-store';
import logger from './logger';
import type { QueuedSnapshot, SnapshotQueueStore } from './snapshotQueue';

/**
 * On-disk home of the snapshot journal (Phase 7 hardening) — the post-Tally
 * snapshots the cloud did not take, waiting to be re-sent. The rules live in
 * snapshotQueue.ts (pure, tested); this file is only the persistence, the
 * same shape as postJournal.ts and for the same reason: an agent restart —
 * a Windows reboot, an auto-update, the operator quitting from the tray — is
 * one of the likelier things to happen during the outage the record is
 * waiting out, and an in-memory queue would not survive it.
 *
 * Its own file (`snapshot-journal.json` beside post-journal.json and
 * config.json in the per-user app-data folder), NOT a section of the post
 * journal: a snapshot body can be up to ~2 MB and electron-store rewrites
 * the whole file on every set, so the double-post guard's small, critical
 * records must not share a write with it.
 */
interface JournalShape {
    snapshots: QueuedSnapshot[];
}

const store = new Store<JournalShape>({
    name: 'snapshot-journal',
    defaults: { snapshots: [] },
    // A journal file that will not parse (a crash mid-write, a hand edit)
    // starts empty rather than throwing at module load: these are RECORDS,
    // and losing queued snapshots is a warn line, whereas an agent that
    // cannot start is a factory whose vouchers stop reaching Tally. The post
    // journal (the double-post guard) deliberately does NOT do this.
    clearInvalidConfig: true,
});

/** The {load, save} pair snapshotQueue.ts works over. */
export const snapshotQueue: SnapshotQueueStore = {
    load: () => store.get('snapshots'),
    save: (items) => store.set('snapshots', items),
};

/**
 * Startup breadcrumb, like the post journal's: an operator reading the log
 * after a restart is told that records are waiting and where the file is.
 */
export function logOutstandingOnStartup(): void {
    const records = store.get('snapshots');

    if (records.length > 0) {
        logger.warn(
            `${records.length} post-Tally snapshot(s) were not taken by the cloud yet — they will be re-sent once it answers again`,
            {
                entries: records.map((r) => ({
                    entryId: r.entryId,
                    attempts: r.attempts,
                    queuedAt: r.queuedAt,
                    sha256: r.body?.xml_sha256?.slice(0, 12) ?? null,
                })),
                journal: store.path,
            },
        );
    }
}
