import { type MastersSyncSummary, reportCompanies, syncMasters } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { exportCompanies, exportMasters } from './tally/masters';

export interface MastersRunResult {
    companies: string[];
    pulled: Record<string, number>;
    posted: MastersSyncSummary;
}

/**
 * The inbound masters loop: pull item groups, godowns, ledgers and items from
 * the local Tally, and push them to the cloud. Runs on its own slower interval
 * than the voucher sync — masters don't change hourly. Independent of the
 * outbound voucher loop so a problem in one never stalls the other.
 */

let running = false;
let intervalHandle: ReturnType<typeof setInterval> | null = null;

export async function runMastersSync(): Promise<MastersRunResult | null> {
    if (running) {
        logger.debug('Masters pull already in progress, skipping this tick');
        return null;
    }
    if (!isConfigured()) {
        logger.debug('Agent not configured yet, skipping masters pull');
        return null;
    }

    running = true;
    try {
        const cfg = getConfig();
        logger.info('Masters pull: starting', {
            tally: `${cfg.tallyHost}:${cfg.tallyPort}`,
            company: cfg.tallyCompanyName,
            cloud: cfg.cloudApiBaseUrl,
        });

        // Report the available companies first (needs no selected company), so
        // Settings can offer them even before one is chosen.
        let companies: string[] = [];
        try {
            companies = await exportCompanies({ host: cfg.tallyHost, port: cfg.tallyPort });
            await reportCompanies(companies);
            logger.info(`Masters pull: found ${companies.length} Tally compan${companies.length === 1 ? 'y' : 'ies'}`, { companies });
        } catch (err) {
            logger.warn('Masters pull: could not report companies', { message: err instanceof Error ? err.message : String(err) });
        }

        const payload = await exportMasters({
            host: cfg.tallyHost,
            port: cfg.tallyPort,
            company: cfg.tallyCompanyName,
        });

        const pulled = Object.fromEntries(Object.entries(payload).map(([key, rows]) => [key, rows.length]));
        const pulledTotal = Object.values(pulled).reduce((sum, n) => sum + n, 0);
        logger.info(`Masters pull: fetched ${pulledTotal} records from Tally`, pulled);

        const posted = await syncMasters(payload, cfg.tallyCompanyName);
        const created = Object.values(posted).reduce((sum, s) => sum + s.created, 0);
        const updated = Object.values(posted).reduce((sum, s) => sum + s.updated, 0);
        logger.info(`Masters pull: posted to ERP — ${created} created, ${updated} updated`, posted);

        return { companies, pulled, posted };
    } catch (err) {
        logger.error('Masters pull failed', { message: err instanceof Error ? err.message : String(err) });
        throw err;
    } finally {
        running = false;
    }
}

export function startMastersLoop(): void {
    if (intervalHandle) return;

    const cfg = getConfig();
    const intervalMs = Math.max(cfg.mastersPollIntervalSeconds, 300) * 1000;

    logger.info(`Starting masters loop, pulling every ${intervalMs / 1000}s`);
    // The loop is fire-and-forget; runMastersSync already logs failures, so
    // swallow the rejection here (it re-throws only for the setup UI's benefit).
    const tick = () => void runMastersSync().catch(() => {});
    tick();
    intervalHandle = setInterval(tick, intervalMs);
}

export function stopMastersLoop(): void {
    if (intervalHandle) {
        clearInterval(intervalHandle);
        intervalHandle = null;
    }
}
