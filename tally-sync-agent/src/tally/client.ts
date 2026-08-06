import axios from 'axios';
import { XMLParser } from 'fast-xml-parser';
import { getConfig } from '../config';
import logger from '../logger';

export interface TallyImportResult {
    success: boolean;
    created: number;
    errors: number;
    message: string;
    rawResponse: string;
}

const parser = new XMLParser({ ignoreAttributes: false });

/**
 * Tally returns HTTP 200 even when an import fails — the real result is in
 * the response body's <RESPONSE> block (CREATED / ALTERED / ERRORS /
 * LINEERROR counts). This parses that shape, but Tally's exact response
 * fields have historically varied a little by version — if `success` comes
 * back wrong against your real instance, log rawResponse and adjust the
 * checks below rather than guessing. See docs/archive/TALLY-SYNC-MASTER-PLAN.md §3's
 * "errors hide in HTTP 200" gotcha.
 */
export async function postVoucherXml(xml: string): Promise<TallyImportResult> {
    const cfg = getConfig();
    const url = `http://${cfg.tallyHost}:${cfg.tallyPort}`;

    logger.debug(`Posting to Tally at ${url}`, { xmlLength: xml.length });

    const { data: rawResponse } = await axios.post<string>(url, xml, {
        headers: { 'Content-Type': 'text/xml' },
        timeout: 30000,
        responseType: 'text',
    });

    let parsed: any;
    try {
        parsed = parser.parse(rawResponse);
    } catch (err) {
        return {
            success: false,
            created: 0,
            errors: 1,
            message: `Could not parse Tally's response as XML: ${(err as Error).message}`,
            rawResponse,
        };
    }

    const response = parsed?.RESPONSE ?? parsed?.ENVELOPE?.BODY?.DATA?.IMPORTRESULT ?? {};
    const created = Number(response.CREATED ?? response.CREATED2 ?? 0);
    const altered = Number(response.ALTERED ?? 0);
    const errors = Number(response.ERRORS ?? 0);
    const lineError = response.LINEERROR ?? null;

    const success = errors === 0 && !lineError && (created > 0 || altered > 0);

    return {
        success,
        created: created + altered,
        errors,
        message: lineError ? String(lineError) : success ? 'Imported successfully' : 'Tally did not confirm import',
        rawResponse,
    };
}

/**
 * A lightweight reachability check for the tray's status display — hits
 * Tally with an empty-ish request just to see if something answers on the
 * port at all, without attempting a real import.
 */
export async function pingTally(): Promise<boolean> {
    const cfg = getConfig();
    try {
        await axios.get(`http://${cfg.tallyHost}:${cfg.tallyPort}`, { timeout: 5000 });
        return true;
    } catch {
        return false;
    }
}
