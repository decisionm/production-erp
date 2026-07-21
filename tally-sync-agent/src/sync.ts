import { acknowledge, fetchPending, reportFailure, type TallySyncEntry } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { postVoucherXml } from './tally/client';
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

async function syncOne(entry: TallySyncEntry): Promise<void> {
    const cfg = getConfig();

    try {
        const xml = buildVoucherXml(entry, cfg.tallyCompanyName);
        const result = await postVoucherXml(xml);

        if (result.success) {
            await acknowledge(entry.id);
            logger.info(`Synced entry #${entry.id} (${entry.tally_voucher_type})`, { message: result.message });
        } else {
            await reportFailure(entry.id, result.message);
            logger.warn(`Tally rejected entry #${entry.id}`, {
                message: result.message,
                rawResponse: result.rawResponse.slice(0, 2000),
            });
        }
    } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        logger.error(`Failed to sync entry #${entry.id}`, { message });
        // Report back to the cloud queue too, not just the local log — a
        // network blip talking to Tally shouldn't silently strand the entry
        // in "pending" forever with no visibility from the retry dashboard.
        await reportFailure(entry.id, message).catch((reportErr) => {
            logger.error(`Also failed to report failure for entry #${entry.id} to the cloud`, {
                message: reportErr instanceof Error ? reportErr.message : String(reportErr),
            });
        });
    }
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

        for (const entry of pending) {
            await syncOne(entry);
            syncedCount += 1;
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
    void runSyncCycle();
    intervalHandle = setInterval(() => void runSyncCycle(), intervalMs);
}

export function stopSyncLoop(): void {
    if (intervalHandle) {
        clearInterval(intervalHandle);
        intervalHandle = null;
    }
}
