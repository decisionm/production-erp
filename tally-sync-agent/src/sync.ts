import axios from 'axios';
import { acknowledge, fetchPending, reportFailure, type TallySyncEntry } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import * as journal from './postJournal';
import { postVoucherXml, type TallyImportResult } from './tally/client';
import { buildVoucherXml } from './tally/voucherBuilders';

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

export type SyncAction =
    | { kind: 'post' }
    | { kind: 'ack-only'; record: journal.PostRecord }
    | { kind: 'refuse'; reason: string };

/**
 * What to do with one queued entry — the whole double-post guard, as a pure
 * function of what the cloud sent us and what this machine remembers doing.
 *
 * Four cases:
 *
 *   1. Tally confirmed the import → only the acknowledgement is outstanding.
 *      Ack it; never rebuild the voucher. This one beats everything else,
 *      including a cleared stamp: Tally having said "created" is a harder
 *      fact than any judgement made from the dashboard.
 *   2. We sent it and never heard back, OR the cloud has handed it to an
 *      agent before and we have no memory of it (a reinstall, a wiped
 *      profile, a second agent) → we cannot tell "never posted" from
 *      "posted, answer lost". Refuse both ways: posting risks a duplicate in
 *      the live books, acking risks marking a voucher synced that Tally
 *      never received.
 *   3. Either of those, but the entry arrives with delivered_at cleared →
 *      somebody hit Retry on the dashboard, which is a person saying "I have
 *      looked in Tally and this voucher is not there". That is the only
 *      thing that can resolve an ambiguity this agent cannot, and it is why
 *      Retry nulls the stamp. Post it. (A stale "unverified" note needs no
 *      cleanup: the post overwrites it, and a clean rejection takes the
 *      entry out of the pending queue, where prune() drops it.)
 *   4. Fresh entry, first delivery → post it.
 */
export function decideAction(entry: TallySyncEntry, remembered: journal.PostRecord | undefined): SyncAction {
    if (remembered?.outcome === 'posted') {
        return { kind: 'ack-only', record: remembered };
    }

    // A cleared stamp is a human's re-authorisation and outranks this
    // agent's "I don't know" — without this, one timed-out post (or one
    // mistyped tallyHost, which times out for every voucher in the queue)
    // strands entries until someone deletes post-journal.json by hand.
    if (entry.delivered_at === null) {
        return { kind: 'post' };
    }

    if (remembered?.outcome === 'unverified') {
        return {
            kind: 'refuse',
            reason: `this agent sent the voucher to Tally at ${remembered.at} and never got an answer, so it may already be in the books`,
        };
    }

    return {
        kind: 'refuse',
        reason: `the cloud already handed this voucher to an agent at ${entry.delivered_at} and this agent has no record of what happened to it`,
    };
}

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

/** Report a failure to the cloud, never letting the report's own failure escape. */
async function report(entryId: number, message: string): Promise<void> {
    // Report back to the cloud queue too, not just the local log — a
    // network blip talking to Tally shouldn't silently strand the entry
    // in "pending" forever with no visibility from the retry dashboard.
    await reportFailure(entryId, message).catch((reportErr) => {
        logger.error(`Also failed to report failure for entry #${entryId} to the cloud`, {
            message: reportErr instanceof Error ? reportErr.message : String(reportErr),
        });
    });
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

    try {
        const xml = buildVoucherXml(entry, cfg.tallyCompanyName);
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

            return 'skipped';
        }

        logger.error(`Failed to sync entry #${entry.id}`, { message });
        await report(entry.id, message);

        return 'failed';
    }

    if (!posted.success) {
        // Tally answered and said no — nothing was created, so this is a
        // clean failure the dashboard can offer a Retry for.
        await report(entry.id, posted.message);
        logger.warn(`Tally rejected entry #${entry.id}`, {
            message: posted.message,
            rawResponse: posted.rawResponse.slice(0, 2000),
        });

        return 'failed';
    }

    // Tally has the voucher. Record that fact BEFORE anything else can go
    // wrong, so a crash between here and the acknowledgement can't lose it.
    journal.record(entry.id, 'posted', voucherNumberOf(entry));

    if (await acknowledgeWithRetry(entry.id)) {
        journal.forget(entry.id);
        logger.info(`Synced entry #${entry.id} (${entry.tally_voucher_type})`, { message: posted.message });

        return 'synced';
    }

    logger.error(
        `Entry #${entry.id} POSTED TO TALLY but the cloud could not be told after ${ACK_ATTEMPTS} attempts. `
        + 'It stays queued and will be acknowledged on a later poll — the voucher will NOT be posted again. '
        + 'No failure has been reported, because the voucher is in the books.',
    );

    return 'skipped';
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
    void runSyncCycle();
    intervalHandle = setInterval(() => void runSyncCycle(), intervalMs);
}

export function stopSyncLoop(): void {
    if (intervalHandle) {
        clearInterval(intervalHandle);
        intervalHandle = null;
    }
}
