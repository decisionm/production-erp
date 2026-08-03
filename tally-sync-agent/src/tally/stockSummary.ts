import axios from 'axios';
import { XMLParser } from 'fast-xml-parser';
import { escapeXml } from './voucherBuilders/xmlHelpers';
import type { TallyTarget } from './masters';

/**
 * Reads a GODOWN-WISE STOCK SUMMARY out of Tally, as at a closing date.
 *
 * STRICTLY READ-ONLY. This exports a collection; it never imports, alters or
 * writes anything to Tally. The same guarantee the masters export gives.
 *
 * Why it exists: the masters sync carries item and godown NAMES but no
 * quantities, so the ERP has never had an authoritative opening position — it
 * was filled with hand-typed "provisional" figures during rehearsal, and those
 * became indistinguishable from real stock. This is the read that replaces
 * them, straight from the books, per item per godown.
 *
 * Defensive in the same way masters.ts is, and for the same reason: real client
 * Tally data varies by version and setup. Every field is optional, values are
 * sanitised, and nothing is matched on a name — the item GUID is the identity
 * carried to the server, so the ERP can join on the identifier it already
 * stores rather than guessing from text.
 */

const parser = new XMLParser({ ignoreAttributes: false, parseTagValue: false, trimValues: true });

export interface StockSummaryLine {
    /** The stable Tally identity. The ERP joins on this, never on the name. */
    item_guid: string;
    item_name: string;
    /** Tally's base unit, e.g. "Kgs." or "Nos." — carried verbatim, dots and all. */
    unit: string | null;
    /** Null when the row is the item's total rather than a godown breakdown. */
    godown: string | null;
    closing_quantity: string | null;
    closing_rate: string | null;
    closing_value: string | null;
}

export interface StockSummaryPayload {
    company: string;
    /** The closing date asked for, ISO. Echoed back so the server can prove what it received. */
    as_of: string;
    lines: StockSummaryLine[];
}

function clean(value: unknown): string {
    const raw = String(value ?? '');
    let out = '';
    for (const ch of raw) {
        if (ch.charCodeAt(0) >= 0x20) out += ch;
    }
    return out.trim();
}

function textOf(field: unknown): string {
    if (field == null) return '';
    if (typeof field === 'object') return clean((field as Record<string, unknown>)['#text']);
    return clean(field);
}

/**
 * Tally returns quantities as "1,234.500 Kgs" and amounts as "= 85.00/Kgs" or
 * "-1234.56". Kept as a STRING with only the number extracted — never parsed to
 * a JS float here, because these become opening balances and a rounding error
 * introduced in transit is one nobody would ever find.
 */
function numericText(field: unknown): string | null {
    const raw = textOf(field);
    if (raw === '') return null;

    const match = raw.replace(/,/g, '').match(/-?\d+(\.\d+)?/);
    return match ? match[0] : null;
}

/** The unit trailing a Tally quantity ("1,234.500 Kgs" → "Kgs"), when present. */
function unitFromQuantity(field: unknown): string | null {
    const raw = textOf(field).replace(/,/g, '').trim();
    const match = raw.match(/-?\d+(?:\.\d+)?\s*(.+)$/);
    return match && match[1] ? match[1].trim() : null;
}

/**
 * Dates go to Tally as YYYYMMDD. Accepts an ISO date so callers can speak the
 * format everything else in this project uses.
 */
function tallyDate(iso: string): string {
    return iso.replace(/-/g, '').slice(0, 8);
}

function buildStockSummaryXml(company: string, asOf: string): string {
    const date = tallyDate(asOf);

    return (
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        '<TYPE>Collection</TYPE><ID>AgentStockSummary</ID></HEADER><BODY><DESC>' +
        '<STATICVARIABLES><SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>' +
        `<SVCURRENTCOMPANY>${escapeXml(company)}</SVCURRENTCOMPANY>` +
        // Both dates are set. Tally derives a CLOSING balance from SVTODATE, and
        // omitting SVFROMDATE has been seen to make it fall back to the company's
        // current period rather than the date asked for.
        `<SVFROMDATE>${date}</SVFROMDATE><SVTODATE>${date}</SVTODATE>` +
        '</STATICVARIABLES>' +
        '<TDL><TDLMESSAGE><COLLECTION NAME="AgentStockSummary" ISMODIFY="No" ISFIXED="No" ' +
        'ISINITIALIZE="No" ISOPTION="No" ISINTERNAL="No"><TYPE>StockItem</TYPE>' +
        // BatchAllocations is where Tally puts the godown-wise breakdown. The
        // item-level closing figures are fetched too, so an item held in no
        // godown still arrives rather than vanishing from the snapshot.
        '<FETCH>Name, GUID, BaseUnits, ClosingBalance, ClosingRate, ClosingValue, ' +
        'BatchAllocations.GodownName, BatchAllocations.ClosingBalance, ' +
        'BatchAllocations.ClosingRate, BatchAllocations.ClosingValue</FETCH>' +
        '</COLLECTION></TDLMESSAGE></TDL></DESC></BODY></ENVELOPE>'
    );
}

function asArray(node: unknown): any[] {
    if (node == null) return [];
    return Array.isArray(node) ? node : [node];
}

/**
 * One item node → its lines. A godown-wise item yields one line per godown; an
 * item with no godown breakdown yields a single line with `godown: null`, so
 * the server can still see it and say so rather than silently dropping stock.
 */
function linesFor(node: any): StockSummaryLine[] {
    const guid = textOf(node.GUID);
    const name = clean(node['@_NAME'] ?? textOf(node.NAME));

    if (!guid || !name) return [];

    const unit = textOf(node.BASEUNITS) || unitFromQuantity(node.CLOSINGBALANCE);
    const batches = asArray(node.BATCHALLOCATIONS);

    if (batches.length === 0) {
        return [
            {
                item_guid: guid,
                item_name: name,
                unit: unit || null,
                godown: null,
                closing_quantity: numericText(node.CLOSINGBALANCE),
                closing_rate: numericText(node.CLOSINGRATE),
                closing_value: numericText(node.CLOSINGVALUE),
            },
        ];
    }

    return batches.map((b: any) => ({
        item_guid: guid,
        item_name: name,
        unit: unit || null,
        godown: clean(textOf(b.GODOWNNAME)) || null,
        closing_quantity: numericText(b.CLOSINGBALANCE),
        closing_rate: numericText(b.CLOSINGRATE),
        closing_value: numericText(b.CLOSINGVALUE),
    }));
}

/**
 * Pull the godown-wise stock summary for one company as at `asOf` (ISO date).
 *
 * @param asOf closing date, e.g. '2026-08-02'
 */
export async function exportStockSummary(target: TallyTarget, asOf: string): Promise<StockSummaryPayload> {
    const url = `http://${target.host}:${target.port}`;
    const xml = buildStockSummaryXml(target.company, asOf);

    const { data } = await axios.post<string>(url, xml, {
        headers: { 'Content-Type': 'text/xml' },
        // A full stock summary is heavier than a master list; a real catalogue
        // of several hundred items across godowns has been seen to take a while.
        timeout: 120000,
        responseType: 'text',
    });

    const parsed = parser.parse(data);
    const collection = parsed?.ENVELOPE?.BODY?.DATA?.COLLECTION;
    const nodes = asArray(collection ? collection.STOCKITEM : undefined);

    return {
        company: target.company,
        as_of: asOf,
        lines: nodes.flatMap(linesFor),
    };
}
