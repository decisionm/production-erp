import { syncMasters } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { exportMasters } from './tally/masters';

/**
 * The inbound masters loop: pull item groups, godowns, ledgers and items from
 * the local Tally, and push them to the cloud. Runs on its own slower interval
 * than the voucher sync — masters don't change hourly. Independent of the
 * outbound voucher loop so a problem in one never stalls the other.
 */

let running = false;
let intervalHandle: ReturnType<typeof setInterval> | null = null;

export async function runMastersSync(): Promise<void> {
    if (running) {
        logger.debug('Masters pull already in progress, skipping this tick');
        return;
    }
    if (!isConfigured()) {
        logger.debug('Agent not configured yet, skipping masters pull');
        return;
    }

    running = true;
    try {
        const cfg = getConfig();
        const payload = await exportMasters({
            host: cfg.tallyHost,
            port: cfg.tallyPort,
            company: cfg.tallyCompanyName,
        });

        const counts = Object.fromEntries(Object.entries(payload).map(([key, rows]) => [key, rows.length]));
        logger.info('Pulled masters from Tally', counts);

        const summary = await syncMasters(payload);
        logger.info('Pushed masters to cloud', summary);
    } catch (err) {
        logger.error('Masters pull failed', { message: err instanceof Error ? err.message : String(err) });
    } finally {
        running = false;
    }
}

export function startMastersLoop(): void {
    if (intervalHandle) return;

    const cfg = getConfig();
    const intervalMs = Math.max(cfg.mastersPollIntervalSeconds, 300) * 1000;

    logger.info(`Starting masters loop, pulling every ${intervalMs / 1000}s`);
    void runMastersSync();
    intervalHandle = setInterval(() => void runMastersSync(), intervalMs);
}

export function stopMastersLoop(): void {
    if (intervalHandle) {
        clearInterval(intervalHandle);
        intervalHandle = null;
    }
}
