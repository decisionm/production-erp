import { type ReceivablesSyncSummary, syncReceivables } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { exportOutstandingPosition } from './tally/receivables';

/**
 * Read what the factory is owed and what it has still to ship, and push it to
 * the cloud so the CRM's client-outstanding page has something to answer with.
 *
 * THERE IS NO TIMER HERE, AND THERE MUST NOT BE ONE. Since v0.3.4 this agent
 * reads from Tally only when a person asks it to — the factory's rule after
 * the 07/08-Aug-2026 corruption scare, when every variant of an automatic read
 * crashed or wedged the live TallyPrime and the company data was reported
 * corrupt afterwards. This is two report exports, so it is a tray action like
 * the masters and Day Book pulls, and the operator's press is its authority.
 *
 * READ-ONLY AND ONE-DIRECTIONAL besides. It exports from Tally and posts to
 * the ERP; it never posts to Tally and never touches the voucher queue.
 */

let running = false;

export interface ReceivablesRunResult {
    asOf: string;
    bills: number;
    orders: number;
    posted: ReceivablesSyncSummary;
}

/** Today, as the local machine sees it — the factory PC is in factory time. */
function today(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

export async function runReceivablesSync(): Promise<ReceivablesRunResult | null> {
    if (running) {
        logger.debug('Receivables pull already in progress, ignoring this press');
        return null;
    }
    if (!isConfigured()) {
        logger.debug('Agent not configured yet, skipping receivables pull');
        return null;
    }

    running = true;
    try {
        const cfg = getConfig();
        const asOf = today();

        logger.info('Receivables pull: starting', { asOf, company: cfg.tallyCompanyName });

        const { bills, orders } = await exportOutstandingPosition(
            { host: cfg.tallyHost, port: cfg.tallyPort, company: cfg.tallyCompanyName },
            asOf,
        );

        // Counts only in the log. A party name or an amount here would put
        // Owner/Accounts data (FC-06) into a file the factory PC keeps for 30
        // days and anybody at that desk can open.
        logger.info(`Receivables pull: read ${bills.length} outstanding bills and ${orders.length} pending order lines`);

        const posted = await syncReceivables(bills, orders, asOf, cfg.tallyCompanyName);

        // The cloud declines to wipe a standing position on an entirely empty
        // pull. Saying so out loud is the difference between "nothing is owed"
        // and "we did not understand Tally's answer" — the distinction #64 and
        // #66 were both about.
        if (posted.skipped_empty) {
            logger.warn('Receivables pull: Tally returned nothing at all, so the ERP kept the position it already had');
        } else {
            logger.info(`Receivables pull: posted to ERP — ${posted.bills} bills, ${posted.orders} orders, ${posted.parties} parties`);
        }

        return { asOf, bills: bills.length, orders: orders.length, posted };
    } catch (err) {
        logger.error('Receivables pull failed', { message: err instanceof Error ? err.message : String(err) });
        throw err;
    } finally {
        running = false;
    }
}
