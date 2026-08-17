import axios from 'axios';
import { acknowledge, fetchPending, reportFailure, uploadSnapshot, type TallySyncEntry } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { decideAction } from './postDecision';
import * as journal from './postJournal';
import { sendSnapshot } from './snapshot';
import * as snapshotJournal from './snapshotJournal';
import { flushSnapshotQueue } from './snapshotQueue';
import { postVoucherXml, type TallyImportResult } from './tally/client';
import { buildVoucherXml } from './tally/voucherBuilders';
import { agentVersion } from './version';

export interface SyncStatus {
    running: boolean;
    paused: boolean;
    lastRunAt: Date | null;
    lastError: string | null;
    lastSyncedCount: number;
}

const status: SyncStatus = {
    running: false,
    paused: false,
    lastRunAt: null,
    lastError: null,
    lastSyncedCount: 0,
};

export function getStatus(): SyncStatus {
    return { ...status };
}

export function setPaused(paused: boolean): void {
    status.paused = paused;
    logger.info(paused ? 'Sync paused' : 'Sync resumed');
}

/** How many times an acknowledgement is retried before we give up for this cycle. */
const ACK_ATTEMPTS = 3;

/** Backoff between acknowledgement attempts: 2s, then 4s. */
const ACK_BACKOFF_MS = 2000;

type SyncOutcome = 'synced' | 'failed' | 'skipped';

export { decideAction, type SyncAction } from './postDecision';

const delay = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Entries we have already shouted about. The loop runs every ~90s and a
 * refusal only clears when a human intervenes, so without this the log fills
 * with the same paragraph until someone notices it. Cleared for an entry the
 * moment we act on it again, and pruned with the queue each cycle.
 */
const refusalsLogged = new Set<number>();

/**
 * Tell the cloud the voucher landed, retrying a few times with backoff.
 *
 * This is deliberately NOT inside the try that wraps the Tally post: once
 * Tally has confirmed the import, a failure here is a failure to record
 * bookkeeping we have already done, and reporting THAT as a sync failure is
 * what used to put a Retry button on a voucher that was already in the books.
 */
async function acknowledgeWithRetry(entryId: number): Promise<boolean> {
    for (let attempt = 1; attempt <= ACK_ATTEMPTS; attempt += 1) {
        try {
            await acknowledge(entryId);

            return true;
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);
            logger.warn(`Acknowledgement attempt ${attempt}/${ACK_ATTEMPTS} failed for entry #${entryId}`, { message });

            if (attempt < ACK_ATTEMPTS) {
                await delay(ACK_BACKOFF_MS * attempt);
            }
        }
    }

    return false;
}

/**
 * Did this request definitely never reach Tally?
 *
 * A timeout is the one answer that tells us nothing: Tally may have imported
 * the voucher and simply taken too long to say so. Everything else — most
 * importantly ECONNREFUSED, the everyday "Tally isn't running" — means the
 * voucher never landed, and must stay reportable so it shows up on the
 * dashboard as failed rather than sitting silently in the queue.
 */
function isInconclusivePostError(err: unknown): boolean {
    return axios.isAxiosError(err) && err.response === undefined
        && (err.code === 'ECONNABORTED' || err.code === 'ETIMEDOUT');
}

/**
 * Report a failure to the cloud, never letting the report's own failure
 * escape. Returns whether the cloud actually heard it — the caller keeps the
 * journal record when it did not, so a later cycle can say it again.
 */
async function report(entryId: number, message: string): Promise<boolean> {
    // Report back to the cloud queue too, not just the local log — a
    // network blip talking to Tally shouldn't silently strand the entry
    // in "pending" forever with no visibility from the retry dashboard.
    try {
        await reportFailure(entryId, message);

        return true;
    } catch (reportErr) {
        logger.error(`Also failed to report failure for entry #${entryId} to the cloud`, {
            message: reportErr instanceof Error ? reportErr.message : String(reportErr),
        });

        return false;
    }
}

/**
 * Write the rejection down BEFORE telling the cloud, then tell it. Same shape
 * as the posted path journalling before the ack, and for the same reason: the
 * second call can fail, and the local note is the only thing that survives it.
 */
async function reportRejection(entry: TallySyncEntry, message: string): Promise<void> {
    journal.record(entry.id, 'rejected', voucherNumberOf(entry), message);

    if (await report(entry.id, message)) {
        journal.forget(entry.id);
    }
}

/**
 * Upload the record of this post — the exact XML we sent and what Tally
 * answered (null when it never did) — to the cloud (Phase 4).
 *
 * Called ONLY after the outcome above it is settled: after the ack or the
 * failure report has run, and only on the paths where an XML was actually
 * built and posted this cycle. It is a record, not a report — it changes
 * nothing about the entry, and it is not allowed to fail into the loop:
 * sendSnapshot never throws, and this wrapper catches anyway, so a cloud
 * that will not take the snapshot costs one warn line and nothing else —
 * and, since Phase 7, a journal record: the failed body is written to
 * snapshot-journal.json and re-sent by resendQueuedSnapshots() on a later
 * cycle, so a Tally answer captured while the cloud was down still reaches
 * the drawer.
 */
async function snapshot(entry: TallySyncEntry, xml: string, tally: TallyImportResult | null): Promise<void> {
    try {
        await sendSnapshot(
            { entry, xml, tally, agentVersion: agentVersion() },
            {
                upload: uploadSnapshot,
                warn: (message, meta) => logger.warn(message, meta),
                info: (message, meta) => logger.info(message, meta),
                error: (message, meta) => logger.error(message, meta),
                queue: snapshotJournal.snapshotQueue,
            },
        );
    } catch (err) {
        logger.warn(`Snapshot upload failed for entry #${entry.id}`, {
            message: err instanceof Error ? err.message : String(err),
        });
    }
}

/**
 * Re-send the snapshots an earlier cycle could not upload (Phase 7). Runs
 * right after /pending has answered — the cloud is demonstrably up, so the
 * bounded attempts in snapshotQueue.ts are spent on a cloud that refuses a
 * record, never on an outage — and BEFORE this cycle's entries are worked,
 * so the drawer receives records in the order the posts happened. Records
 * only: nothing here posts, acks or reports, and flushSnapshotQueue never
 * throws; the catch is belt-and-braces like snapshot()'s.
 */
async function resendQueuedSnapshots(): Promise<void> {
    try {
        await flushSnapshotQueue(snapshotJournal.snapshotQueue, {
            upload: uploadSnapshot,
            warn: (message, meta) => logger.warn(message, meta),
            info: (message, meta) => logger.info(message, meta),
            error: (message, meta) => logger.error(message, meta),
        });
    } catch (err) {
        logger.warn('Re-sending queued snapshots failed', {
            message: err instanceof Error ? err.message : String(err),
        });
    }
}

async function syncOne(entry: TallySyncEntry): Promise<SyncOutcome> {
    const cfg = getConfig();
    const action = decideAction(entry, journal.lookup(entry.id));

    if (action.kind === 'refuse') {
        if (!refusalsLogged.has(entry.id)) {
            refusalsLogged.add(entry.id);
            logger.error(
                `REFUSING to post entry #${entry.id} (${entry.tally_voucher_type}) again — ${action.reason}. `
                + 'Open Tally and search for this voucher. If it is NOT there, hit Retry on the Tally Sync page '
                + 'to re-authorise it; if it IS there, leave it — do not retry, or the books get it twice.',
                { voucherNumber: voucherNumberOf(entry) },
            );
        }

        return 'skipped';
    }

    // We're acting on it again, so a future refusal is news worth logging.
    refusalsLogged.delete(entry.id);

    if (action.kind === 'report-only') {
        const message = action.record.message
            ?? 'Tally rejected this voucher; the agent could not reach the cloud at the time to say so.';

        if (await report(entry.id, message)) {
            journal.forget(entry.id);
            logger.info(`Reported entry #${entry.id} as failed — Tally had rejected it at ${action.record.at}`);

            return 'failed';
        }

        logger.error(
            `Entry #${entry.id} was REJECTED by Tally at ${action.record.at} and the cloud still cannot be told. `
            + 'Nothing was created in the books. It stays queued and will be reported on a later poll.',
        );

        return 'skipped';
    }

    if (action.kind === 'ack-only') {
        if (await acknowledgeWithRetry(entry.id)) {
            journal.forget(entry.id);
            logger.info(`Acknowledged entry #${entry.id} — posted to Tally at ${action.record.at}, cloud now updated`);

            return 'synced';
        }

        logger.error(
            `Entry #${entry.id} is in Tally but the cloud still shows it pending — acknowledgement is still failing. `
            + 'It will be retried on the next poll; the voucher will NOT be posted again.',
        );

        return 'skipped';
    }

    let posted: TallyImportResult;
    // Hoisted so the catch below can snapshot the document we actually sent
    // on the timed-out path; stays null when the builder itself threw (no
    // XML existed, nothing to record).
    let xml: string | null = null;

    try {
        xml = buildVoucherXml(entry, cfg.tallyCompanyName);
        posted = await postVoucherXml(xml);
    } catch (err) {
        const message = err instanceof Error ? err.message : String(err);

        if (isInconclusivePostError(err)) {
            // We sent the voucher and Tally never answered. Reporting a
            // failure here would offer a Retry that might post it twice;
            // acking would claim a sync we cannot see. Write down that we
            // don't know, and stop touching it.
            journal.record(entry.id, 'unverified', voucherNumberOf(entry));
            logger.error(
                `Entry #${entry.id} was sent to Tally but the request timed out with no answer — `
                + 'it MAY be in the books. Left queued and untouched; check Tally for this voucher.',
                { message },
            );

            // The one record that IS useful here: what we sent, and that
            // nothing came back (tally: null). Cannot change the outcome.
            if (xml !== null) {
                await snapshot(entry, xml, null);
            }

            return 'skipped';
        }

        logger.error(`Failed to sync entry #${entry.id}`, { message });
        await reportRejection(entry, message);

        return 'failed';
    }

    if (!posted.success) {
        // Tally answered and said no — nothing was created, so this is a
        // clean failure the dashboard can offer a Retry for.
        await reportRejection(entry, posted.message);
        logger.warn(`Tally rejected entry #${entry.id}`, {
            message: posted.message,
            rawResponse: posted.rawResponse.slice(0, 2000),
        });

        // After the failure report, never before it: the record of what we
        // sent and what Tally said, for the dashboard drawer.
        await snapshot(entry, xml, posted);

        return 'failed';
    }

    // Tally has the voucher. Record that fact BEFORE anything else can go
    // wrong, so a crash between here and the acknowledgement can't lose it.
    journal.record(entry.id, 'posted', voucherNumberOf(entry));

    const acknowledged = await acknowledgeWithRetry(entry.id);

    if (acknowledged) {
        journal.forget(entry.id);
        logger.info(`Synced entry #${entry.id} (${entry.tally_voucher_type})`, { message: posted.message });
    } else {
        logger.error(
            `Entry #${entry.id} POSTED TO TALLY but the cloud could not be told after ${ACK_ATTEMPTS} attempts. `
            + 'It stays queued and will be acknowledged on a later poll — the voucher will NOT be posted again. '
            + 'No failure has been reported, because the voucher is in the books.',
        );
    }

    // After the acknowledgement path has run, whichever way it went: the
    // outcome is settled, so the record cannot alter it.
    await snapshot(entry, xml, posted);

    return acknowledged ? 'synced' : 'skipped';
}

function voucherNumberOf(entry: TallySyncEntry): string | null {
    const number = entry.payload?.voucher_number;

    return typeof number === 'string' ? number : null;
}

/**
 * One full poll-translate-post-ack cycle. Called on the interval timer and
 * also directly from the tray's "Sync Now" menu item — both paths go
 * through here so there's exactly one place this logic lives.
 */
export async function runSyncCycle(): Promise<void> {
    if (status.running) {
        logger.debug('Sync cycle already in progress, skipping this tick');
        return;
    }
    if (status.paused) {
        logger.debug('Sync paused, skipping this tick');
        return;
    }
    if (!isConfigured()) {
        logger.debug('Agent not configured yet, skipping this tick');
        return;
    }

    status.running = true;
    let syncedCount = 0;

    try {
        const pending = await fetchPending();
        logger.info(`Fetched ${pending.length} pending entr${pending.length === 1 ? 'y' : 'ies'}`);

        // The cloud answered: anything it would not take earlier goes first.
        await resendQueuedSnapshots();

        // Anything we're still holding that the cloud no longer lists as
        // pending has been resolved elsewhere — forget it rather than carry
        // it forever.
        const pendingIds = pending.map((entry) => entry.id);
        journal.prune(pendingIds);
        for (const id of refusalsLogged) {
            if (!pendingIds.includes(id)) {
                refusalsLogged.delete(id);
            }
        }

        for (const entry of pending) {
            // Only actual syncs count: an entry we deliberately left alone
            // is not progress, and the tray's "last synced N" must not
            // claim otherwise.
            if (await syncOne(entry) === 'synced') {
                syncedCount += 1;
            }
        }

        status.lastError = null;
    } catch (err) {
        status.lastError = err instanceof Error ? err.message : String(err);
        logger.error('Sync cycle failed to fetch pending entries', { message: status.lastError });
    } finally {
        status.running = false;
        status.lastRunAt = new Date();
        status.lastSyncedCount = syncedCount;
    }
}

let intervalHandle: ReturnType<typeof setInterval> | null = null;

export function startSyncLoop(): void {
    if (intervalHandle) return;

    const cfg = getConfig();
    const intervalMs = Math.max(cfg.pollIntervalSeconds, 15) * 1000;

    logger.info(`Starting sync loop, polling every ${intervalMs / 1000}s`);
    journal.logOutstandingOnStartup();
    snapshotJournal.logOutstandingOnStartup();
    void runSyncCycle();
    intervalHandle = setInterval(() => void runSyncCycle(), intervalMs);
}

export function stopSyncLoop(): void {
    if (intervalHandle) {
        clearInterval(intervalHandle);
        intervalHandle = null;
    }
}
