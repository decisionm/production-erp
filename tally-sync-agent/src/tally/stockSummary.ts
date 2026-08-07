import axios from 'axios';
import { XMLParser } from 'fast-xml-parser';
import { withTallyGate } from './gate';
import { escapeXml } from './voucherBuilders/xmlHelpers';
import type { TallyTarget } from './masters';

/**
 * Reads a GODOWN-WISE STOCK SUMMARY out of Tally, as at a closing date — in
 * requests that are each PROVEN SMALL before they are sent.
 *
 * STRICTLY READ-ONLY. This exports collections; it never imports, alters or
 * writes anything to Tally. The same guarantee the masters export gives.
 *
 * The field history that shaped this file, both incidents on 07 Aug 2026:
 *  - v0.2.0: ONE full-catalogue request (every StockItem with godown-wise
 *    closing quantity, rate and value) crashed the live Tally from one click.
 *  - v0.3.0: chunking by stock group was not enough — the "ungrouped items"
 *    chunk wedged TallyPrime twice, deterministically, DURING the request.
 *    Either most of the catalogue is ungrouped, or CHILDOF does not scope on
 *    this Tally build; from the outside the two are indistinguishable.
 *
 * So v0.3.1 stops assuming and starts probing. Two request weights exist:
 *
 *  LIGHT — Name + GUID only, no balances. The same class of request as the
 *  masters item list, which this Tally demonstrably serves every hour without
 *  strain. Light requests are safe even when a scope filter silently fails
 *  and they return the entire catalogue.
 *
 *  HEAVY — closing quantity/rate/value with the godown-wise batch breakdown.
 *  This is the weight that kills. A heavy request is only ever sent for a
 *  scope that a light probe has ALREADY proven returns a bounded number of
 *  items. No probe, no heavy request — that is the contract of this file.
 *
 * Scoping mechanisms (CHILDOF for a group's direct children, a TDL name
 * filter for a single item) are Tally-version-sensitive, which is exactly why
 * the caller canary-tests each mechanism with a LIGHT request and aborts the
 * whole run if Tally returns more than the scope should hold. The lethal
 * request is not merely discouraged — it cannot be emitted, because every
 * heavy request's scope was measured first.
 *
 * Defensive in the same way masters.ts is, and for the same reason: real
 * client Tally data varies by version and setup. Every field is optional,
 * values are sanitised, and nothing is matched on a name — the item GUID is
 * the identity carried to the server.
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

/** A stock-group scope: a group name, or null for items directly under Tally's root. */
export type GroupScope = string | null;

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

/**
 * Item names carrying a double-quote cannot be placed inside a TDL formula
 * string safely — escapeXml's &quot; decodes back to a literal quote inside
 * the formula and breaks it. The caller skips such items out loud instead.
 */
export function nameFitsFilter(name: string): boolean {
    return !name.includes('"');
}

const LIGHT_FETCH = 'Name, GUID';
const HEAVY_FETCH =
    'Name, GUID, BaseUnits, ClosingBalance, ClosingRate, ClosingValue, ' +
    'BatchAllocations.GodownName, BatchAllocations.ClosingBalance, ' +
    'BatchAllocations.ClosingRate, BatchAllocations.ClosingValue';

/**
 * One collection request. Scope is always present: the direct children of one
 * stock group (BELONGSTO No), or of the reserved root for `group === null` —
 * optionally narrowed further to a single item name via a TDL filter formula.
 */
function buildRequestXml(
    company: string,
    asOf: string,
    group: GroupScope,
    opts: { light: boolean; itemName?: string },
): string {
    const date = tallyDate(asOf);
    const filter = opts.itemName !== undefined
        ? '<FILTER>AgentStockPick</FILTER>'
        : '';
    const filterFormula = opts.itemName !== undefined
        ? `<SYSTEM TYPE="Formulae" NAME="AgentStockPick">$Name = "${escapeXml(opts.itemName)}"</SYSTEM>`
        : '';

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
        // The scope boundary. BELONGSTO No = direct children only, so a
        // sub-group's items arrive in the sub-group's own scope and never twice.
        `<CHILDOF>${group === null ? '$$SysName:Primary' : escapeXml(group)}</CHILDOF>` +
        '<BELONGSTO>No</BELONGSTO>' +
        filter +
        `<FETCH>${opts.light ? LIGHT_FETCH : HEAVY_FETCH}</FETCH>` +
        '</COLLECTION>' + filterFormula + '</TDLMESSAGE></TDL></DESC></BODY></ENVELOPE>'
    );
}

function asArray(node: unknown): any[] {
    if (node == null) return [];
    return Array.isArray(node) ? node : [node];
}

async function post(target: TallyTarget, xml: string, timeoutMs: number): Promise<any[]> {
    const url = `http://${target.host}:${target.port}`;

    const { data } = await withTallyGate(() =>
        axios.post<string>(url, xml, {
            headers: { 'Content-Type': 'text/xml' },
            timeout: timeoutMs,
            responseType: 'text',
        }),
    );

    const parsed = parser.parse(data);
    const collection = parsed?.ENVELOPE?.BODY?.DATA?.COLLECTION;
    return asArray(collection ? collection.STOCKITEM : undefined);
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
 * LIGHT probe of a scope: which item GUIDs does Tally hold directly under
 * this group (optionally narrowed to one item name)? No balances are computed
 * — safe even if the scope filter silently fails and this returns the world,
 * which is precisely the failure the caller uses it to detect.
 */
export async function probeScope(
    target: TallyTarget,
    asOf: string,
    group: GroupScope,
    itemName?: string,
): Promise<string[]> {
    const xml = buildRequestXml(target.company, asOf, group, { light: true, itemName });
    const nodes = await post(target, xml, 60000);

    return nodes.map((n) => textOf(n.GUID)).filter((g) => g !== '');
}

/**
 * HEAVY read of one PRE-PROVEN scope: the godown-wise closing position for
 * the items directly under one stock group, or one named item within it.
 *
 * Callers must have probed the scope (or be operating under a run-level
 * canary that proved the mechanism) before calling this — see the module
 * comment. ONE attempt, NO automatic retry: a timed-out request leaves Tally
 * still computing, and firing another while it does is exactly the stacking
 * that kills it. The operator retries by running the read again, which
 * resumes where it stopped — after restarting Tally if it was left wedged.
 */
export async function exportScope(
    target: TallyTarget,
    asOf: string,
    group: GroupScope,
    itemName?: string,
): Promise<StockSummaryLine[]> {
    const xml = buildRequestXml(target.company, asOf, group, { light: false, itemName });
    // Generous for a scope already proven small. A proven-small request that
    // still needs longer than this is a Tally that is struggling, and the
    // right response is to stop, not to wait harder.
    const nodes = await post(target, xml, 120000);

    return nodes.flatMap(linesFor);
}
