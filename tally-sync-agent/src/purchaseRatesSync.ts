import { type PurchaseRatesSyncSummary, syncPurchaseRates } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { exportPurchaseRates } from './tally/purchaseRates';

/**
 * Read the factory's Day Book for the configured window, keep the Purchase
 * Order and Purchase lines, and push them to the cloud so Procurement's
 * vendor/item rate lookup has something to answer with.
 *
 * THERE IS NO TIMER HERE, AND THERE MUST NOT BE ONE. Since v0.3.4 this agent
 * reads from Tally only when a person asks it to — the factory's rule after
 * the 07/08-Aug-2026 corruption scare, when every variant of an automatic
 * read crashed or wedged the live TallyPrime and the company data was
 * reported corrupt afterwards. The masters pull was demoted to a tray action
 * for exactly this reason, and a Day Book export is a HEAVIER read than the
 * masters one: several megabytes on a full financial year. So this is a tray
 * action too, and the operator's press is the authority for it.
 *
 * READ-ONLY AND ONE-DIRECTIONAL besides. It exports from Tally and posts to
 * the ERP; it never posts to Tally and never touches the voucher queue.
 */

let running = false;

export interface PurchaseRatesRunResult {
    from: string;
    to: string;
    read: number;
    posted: PurchaseRatesSyncSummary;
}

/** Today, as the local machine sees it — the factory PC is in factory time. */
function today(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export async function runPurchaseRatesSync(): Promise<PurchaseRatesRunResult | null> {
    if (running) {
        logger.debug('Purchase-rate pull already in progress, ignoring this press');
        return null;
    }
    if (!isConfigured()) {
        logger.debug('Agent not configured yet, skipping purchase-rate pull');
        return null;
    }

    running = true;
    try {
        const cfg = getConfig();
        const from = cfg.purchaseRatesFromDate;
        const to = today();

        logger.info('Purchase-rate pull: starting', { from, to, company: cfg.tallyCompanyName });

        const lines = await exportPurchaseRates(
            { host: cfg.tallyHost, port: cfg.tallyPort, company: cfg.tallyCompanyName },
            from,
            to,
        );

        // Counts only in the log. A rate or a party name here would put
        // Owner/Accounts data (FC-06) into a file the factory PC keeps for 30
        // days and anybody at that desk can open.
        logger.info(`Purchase-rate pull: read ${lines.length} quotable lines from Tally`);

        const posted = await syncPurchaseRates(lines, cfg.tallyCompanyName);

        logger.info(
            `Purchase-rate pull: posted to ERP — ${posted.created} created, ${posted.updated} updated, ${posted.deleted} withdrawn`,
        );

        return { from, to, read: lines.length, posted };
    } catch (err) {
        logger.error('Purchase-rate pull failed', { message: err instanceof Error ? err.message : String(err) });
        throw err;
    } finally {
        running = false;
    }
}

