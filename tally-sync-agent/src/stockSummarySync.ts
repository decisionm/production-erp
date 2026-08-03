import { previewStockSummary, type StockSummaryPreview } from './cloudApi';
import { getConfig, isConfigured } from './config';
import logger from './logger';
import { exportStockSummary } from './tally/stockSummary';

/**
 * The Stock Summary preview run: read the godown-wise closing position out of
 * the local Tally and send it to the cloud to be REPORTED ON, never imported.
 *
 * Manual only, on purpose. There is no loop and no interval, unlike the voucher
 * and masters syncs. An opening-stock snapshot is a one-off act tied to a
 * cutover date that a person chose; putting it on a timer would mean the
 * factory's opening position could silently change under a running shift.
 */
export async function runStockSummaryPreview(asOf: string): Promise<StockSummaryPreview | null> {
    if (!isConfigured()) {
        logger.warn('Stock Summary: agent not configured yet');
        return null;
    }

    const cfg = getConfig();

    if (!cfg.tallyCompanyName) {
        logger.warn('Stock Summary: no Tally company selected — choose one in Settings first');
        return null;
    }

    logger.info('Stock Summary: reading from Tally (read-only)', {
        tally: `${cfg.tallyHost}:${cfg.tallyPort}`,
        company: cfg.tallyCompanyName,
        asOf,
    });

    const payload = await exportStockSummary(
        { host: cfg.tallyHost, port: cfg.tallyPort, company: cfg.tallyCompanyName },
        asOf,
    );

    logger.info(`Stock Summary: read ${payload.lines.length} line(s) from Tally`, {
        company: payload.company,
        asOf: payload.as_of,
    });

    const preview = await previewStockSummary(payload);

    logger.info('Stock Summary: preview returned by ERP — NOTHING was imported', preview.totals);

    return preview;
}
