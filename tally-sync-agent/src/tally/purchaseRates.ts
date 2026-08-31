import axios from 'axios';
import { XMLParser } from 'fast-xml-parser';
import logger from '../logger';
import { withTallyGate } from './gate';
import { escapeXml } from './voucherBuilders/xmlHelpers';

/**
 * READS THE FACTORY'S PURCHASE RATES OUT OF TALLY'S DAY BOOK — the inbound
 * half of Procurement's vendor/item rate lookup.
 *
 * READ-ONLY, ABSOLUTELY. This module exports; it never posts. Nothing here
 * creates, alters or cancels a voucher, and the cloud endpoint it feeds writes
 * one table that nothing posts from. The existing approved workflows continue
 * to handle voucher posting.
 *
 * WHY A DAY BOOK EXPORT AND NOT A COLLECTION. The tag names below are not
 * guesses: they are read off the factory's OWN exports — 107 Purchase Order
 * vouchers (12-Aug) and 17 Purchase vouchers (24-Aug) — which were produced by
 * exactly this request shape, so what this asks for is what those files
 * contain. A `<TYPE>Collection</TYPE>` request with a FETCH of the inventory
 * sublists would have been a guess at a second shape for the same data, and
 * masters.ts already records what guessing a Tally field name costs.
 *
 * WHAT IS DELIBERATELY DROPPED, and why each matters:
 *   · any voucher type but Purchase Order and Purchase — the Day Book carries
 *     everything the factory did that day;
 *   · a voucher Tally marks CANCELLED, DELETED or OPTIONAL. Q39 names voucher
 *     72 of the 92 as the cancelled one. A cancelled voucher feeding "the
 *     latest rate" is a withdrawn number presented as evidence;
 *   · an inventory line with no RATE. Measured: 8 of 18 inventory entries in
 *     the 24-Aug purchase export carry one. A line with no rate cannot be
 *     quoted from, and a zero would be a fabrication.
 *
 * THE RATE KEEPS ITS BASIS. Tally spells it `674.000/Kgs.` — a number and the
 * unit it is per — and both halves travel. The cloud refuses to prefill a rate
 * whose unit disagrees with the ERP item's own; it can only do that if the
 * unit arrives.
 */

export interface TallyTarget {
    host: string;
    port: number;
    company: string;
}

export interface PurchaseRateLine {
    voucher_guid: string;
    line_index: number;
    voucher_type: 'purchase_order' | 'purchase_invoice';
    voucher_number: string | null;
    voucher_reference: string | null;
    voucher_date: string;
    party_ledger_name: string;
    party_gstin: string | null;
    stock_item_name: string;
    rate_value: number;
    rate_unit: string | null;
    quantity: number | null;
    quantity_unit: string | null;
    amount: number | null;
    cgst_rate: number | null;
    sgst_rate: number | null;
    igst_rate: number | null;
    cess_rate: number | null;
    hsn_code: string | null;
    purchase_ledger_name: string | null;
}

/**
 * Tally's own names for the two voucher types, mapped to the cloud's. Read
 * from VCHTYPE on the voucher node, which is what both evidence sets carry.
 *
 * A factory that has RENAMED its purchase voucher type in Tally will match
 * neither and this pull will return nothing for it — visibly, as an empty
 * lookup, never as a wrong rate. That is the right failure: the alternative
 * is matching on a substring and quoting a "Purchase Return" as a purchase.
 */
const VOUCHER_TYPES: Record<string, PurchaseRateLine['voucher_type']> = {
    'Purchase Order': 'purchase_order',
    Purchase: 'purchase_invoice',
};

const parser = new XMLParser({ ignoreAttributes: false, parseTagValue: false, trimValues: true });

/** Strip Tally's control-char language markers and trim — as masters.ts does. */
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

/** A Tally `<X>Yes</X>` flag, defaulting to false when the tag is absent. */
function isYes(field: unknown): boolean {
    return textOf(field).toLowerCase() === 'yes';
}

/** Whatever the parser gave us for a repeatable tag, as an array. */
function listOf(node: unknown): any[] {
    if (node == null) return [];
    return Array.isArray(node) ? node : [node];
}

/**
 * `674.000/Kgs.` → { value: 674, unit: 'Kgs.' }.
 *
 * The unit half is what stops a rate being applied on the wrong basis, so a
 * rate with no `/unit` returns a null unit rather than an assumed one, and a
 * value that is not a finite number returns null throughout — never 0, which
 * would read as "free".
 */
export function parseRate(raw: unknown): { value: number | null; unit: string | null } {
    const text = textOf(raw);
    if (text === '') return { value: null, unit: null };

    const slash = text.indexOf('/');
    const valueText = (slash === -1 ? text : text.slice(0, slash)).trim();
    const unit = slash === -1 ? null : text.slice(slash + 1).trim() || null;

    const value = Number(valueText);

    // A rate whose NUMBER could not be read carries no usable unit either.
    // Returning `{value: null, unit: 'a'}` for "n/a" would put a nonsense unit
    // in front of the cloud's unit comparison, where the only safe answers are
    // a real unit or nothing.
    if (!Number.isFinite(value) || valueText === '') return { value: null, unit: null };

    return { value, unit };
}

/**
 * ` 48.000 Kgs.` → { value: 48, unit: 'Kgs.' }.
 *
 * A quantity is a number then a unit separated by space (Tally leads with one
 * too). Split on the LAST run of digits so a unit containing a digit does not
 * eat the number.
 */
export function parseQuantity(raw: unknown): { value: number | null; unit: string | null } {
    const text = textOf(raw);
    if (text === '') return { value: null, unit: null };

    const match = /^(-?[\d.,]+)\s*(.*)$/.exec(text);
    if (match === null) return { value: null, unit: null };

    const value = Number(match[1].replace(/,/g, ''));

    return { value: Number.isFinite(value) ? value : null, unit: match[2].trim() || null };
}

/** Tally's `20260701` → `2026-07-01`. Anything else yields null. */
export function parseTallyDate(raw: unknown): string | null {
    const text = textOf(raw);

    return /^\d{8}$/.test(text) ? `${text.slice(0, 4)}-${text.slice(4, 6)}-${text.slice(6, 8)}` : null;
}

/**
 * The GST rates a line carries, by duty head.
 *
 * Per LINE and per VOUCHER, never rolled into a per-item tax master: Q39
 * measured that 9 of 43 items appear under both 5% and 18%, and 3 of 20
 * vendors use both, so the rate is a property of neither.
 */
function gstRatesOf(entry: any): Pick<PurchaseRateLine, 'cgst_rate' | 'sgst_rate' | 'igst_rate' | 'cess_rate'> {
    const rates: Record<string, number> = {};

    for (const detail of listOf(entry['RATEDETAILS.LIST'])) {
        const head = textOf(detail?.GSTRATEDUTYHEAD);
        const rateText = textOf(detail?.GSTRATE);
        const value = Number(rateText);

        // The EMPTY CHECK IS THE POINT. A duty head Tally lists without a rate
        // — Cess is listed on every line and rated on almost none — reads as
        // `Number('')`, which is 0 and finite. Recording that would be the ERP
        // asserting a 0% Cess that Tally never stated: an invented factory
        // value, which AGENTS.md forbids outright. Absent stays absent.
        if (head !== '' && rateText !== '' && Number.isFinite(value)) rates[head] = value;
    }

    return {
        cgst_rate: rates.CGST ?? null,
        // Tally spells the state head "SGST/UTGST".
        sgst_rate: rates['SGST/UTGST'] ?? rates.SGST ?? null,
        igst_rate: rates.IGST ?? null,
        cess_rate: rates.Cess ?? null,
    };
}

/**
 * The factory's own purchase ledger this line was booked to — the
 * local-versus-interstate evidence (DEC-20260812-003), carried so Accounts can
 * see WHY a tax split looks as it does. Read, never enforced here.
 */
function purchaseLedgerOf(entry: any): string | null {
    for (const allocation of listOf(entry['ACCOUNTINGALLOCATIONS.LIST'])) {
        const name = clean(allocation?.LEDGERNAME);
        if (name !== '') return name;
    }

    return null;
}

/** One voucher's quotable lines, or none. */
export function linesOfVoucher(voucher: any): PurchaseRateLine[] {
    const type = VOUCHER_TYPES[clean(voucher?.['@_VCHTYPE']) || textOf(voucher?.VOUCHERTYPENAME)];

    if (type === undefined) return [];

    // Tally's own verdict on the voucher. A cancelled, deleted or optional
    // voucher is not evidence of a rate — see the module docblock.
    if (isYes(voucher.ISCANCELLED) || isYes(voucher.ISDELETED) || isYes(voucher.ISOPTIONAL)) return [];

    const guid = textOf(voucher.GUID) || clean(voucher['@_REMOTEID']);
    const date = parseTallyDate(voucher.DATE);
    const party = clean(voucher.PARTYLEDGERNAME) || clean(voucher.PARTYNAME) || clean(voucher.BASICBASEPARTYNAME);

    if (guid === '' || date === null || party === '') return [];

    const lines: PurchaseRateLine[] = [];

    // The index is the line's position in THIS voucher and is half of the
    // cloud's identity for the row, so it counts every inventory entry —
    // including the ones dropped below. Renumbering around a skipped line
    // would make the same line change identity when a rate is later filled in.
    let index = -1;

    for (const entry of listOf(voucher['ALLINVENTORYENTRIES.LIST'])) {
        index += 1;

        const item = clean(entry?.STOCKITEMNAME);
        const rate = parseRate(entry?.RATE);

        if (item === '' || rate.value === null) continue;

        const quantity = parseQuantity(entry?.BILLEDQTY ?? entry?.ACTUALQTY);
        const amountText = textOf(entry?.AMOUNT);
        const amount = Number(amountText);

        lines.push({
            voucher_guid: guid,
            line_index: index,
            voucher_type: type,
            voucher_number: clean(voucher.VOUCHERNUMBER) || null,
            voucher_reference: clean(voucher.REFERENCE) || null,
            voucher_date: date,
            party_ledger_name: party,
            party_gstin: clean(voucher.PARTYGSTIN) || null,
            stock_item_name: item,
            rate_value: rate.value,
            rate_unit: rate.unit,
            quantity: quantity.value,
            quantity_unit: quantity.unit,
            // ABSOLUTE. Tally signs the inventory side of a purchase negative
            // by its own double-entry convention; a person reading "what did
            // this line come to" means the magnitude, and the sign carries no
            // information a rate lookup can use.
            amount: amountText !== '' && Number.isFinite(amount) ? Math.abs(amount) : null,
            ...gstRatesOf(entry),
            hsn_code: clean(entry?.GSTHSNNAME) || null,
            purchase_ledger_name: purchaseLedgerOf(entry),
        });
    }

    return lines;
}

/**
 * Every VOUCHER node anywhere in a parsed export, found by walking the tree
 * rather than by following a path.
 *
 * THE PATH WAS THE BUG. The first version of this read
 * `ENVELOPE.BODY.IMPORTDATA.REQUESTDATA.TALLYMESSAGE`, taken from the
 * factory's own export FILES — and those files are `IMPORTDATA` because they
 * are what Tally's UI *saves*, an import-shaped document. A live export over
 * the HTTP gateway answers `EXPORTDATA`. The shape of the artifact on disk is
 * not the shape on the wire, and matching the artifact produced a parser that
 * read every evidence file perfectly and returned ZERO against the real Tally
 * (31-Aug-2026, `purchase-rates.received total: 0`).
 *
 * So no path is assumed. A voucher is found wherever it sits, which is correct
 * for IMPORTDATA, EXPORTDATA, a bare `BODY.DATA`, and whatever a different
 * Tally build answers — the one thing every shape agrees on is that the
 * voucher is called VOUCHER.
 *
 * Depth is bounded so a pathological document cannot spin, and arrays are
 * walked because sibling vouchers arrive as one.
 */
function findVouchers(node: unknown, depth = 0): any[] {
    if (node == null || typeof node !== 'object' || depth > 12) return [];

    if (Array.isArray(node)) return node.flatMap((child) => findVouchers(child, depth + 1));

    const found: any[] = [];

    for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
        if (key === 'VOUCHER') {
            found.push(...listOf(value));

            continue;
        }

        // A voucher never nests inside another voucher, so nothing is missed
        // by not descending into the ones already collected.
        found.push(...findVouchers(value, depth + 1));
    }

    return found;
}

/** Every quotable purchase line in a Day Book export's XML. */
export function parseDayBook(xml: string): PurchaseRateLine[] {
    return findVouchers(parser.parse(xml)).flatMap(linesOfVoucher);
}

/**
 * What the export actually contained, for the log when it yields nothing.
 *
 * A pull that reports zero is either "the factory bought nothing in this
 * window" or "this parser did not understand the answer", and on 31-Aug those
 * two were indistinguishable from the cloud side. Counting the vouchers seen
 * and the types among them separates them in one line, without putting a rate,
 * a party or an item name into a log file (FC-06).
 */
export function describeDayBook(xml: string): { vouchers: number; types: Record<string, number> } {
    const vouchers = findVouchers(parser.parse(xml));
    const types: Record<string, number> = {};

    for (const voucher of vouchers) {
        const type = clean(voucher?.['@_VCHTYPE']) || textOf(voucher?.VOUCHERTYPENAME) || '(untyped)';
        types[type] = (types[type] ?? 0) + 1;
    }

    return { vouchers: vouchers.length, types };
}

/** Tally wants `20260701`; this takes `2026-07-01`. */
function toTallyDate(iso: string): string {
    return iso.replace(/-/g, '');
}

/**
 * The date window and company every request shape shares.
 *
 * SVFROMDATE is set as well as SVTODATE for the reason stockSummary.ts records
 * from experience against this same Tally: omitting it has been seen to make
 * Tally fall back to the company's current period rather than the range asked
 * for.
 */
function staticVariables(company: string, from: string, to: string): string {
    return (
        '<STATICVARIABLES><SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>' +
        `<SVFROMDATE>${escapeXml(toTallyDate(from))}</SVFROMDATE>` +
        `<SVTODATE>${escapeXml(toTallyDate(to))}</SVTODATE>` +
        `<SVCURRENTCOMPANY>${escapeXml(company)}</SVCURRENTCOMPANY>` +
        '</STATICVARIABLES>'
    );
}

/**
 * A COLLECTION over vouchers — the shape every read that has ever worked
 * against this factory's Tally uses.
 *
 * WHY THIS EXISTS. The Day Book request below is the ONLY `TYPE=Data` report
 * request in this agent, and it was also the only read that came back empty
 * against the live gateway (three pulls on 31-Aug-2026, `total: 0`, against a
 * company holding 107+ purchase orders in the window). Meanwhile
 * `masters.ts` (AgentMasters, List of Companies) and `stockSummary.ts`
 * (AgentStockSummary) are all `TYPE=Collection` with a TDL COLLECTION, and all
 * three work. That is a fact about this codebase against this Tally, not a
 * preference.
 *
 * The FILTER + `<SYSTEM TYPE="Formulae">` pairing is copied deliberately from
 * stockSummary.ts's item filter rather than invented: it is the one filtering
 * idiom already proven here.
 *
 * NATIVEMETHOD carries the sub-collections. `AllInventoryEntries.*` asks for
 * the whole inventory sub-collection including its own nested lists — the rate
 * details and accounting allocations the GST split and purchase ledger come
 * from — which a flat FETCH of dotted paths does not reliably reach.
 */
function buildVoucherCollectionXml(company: string, from: string, to: string): string {
    return (
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        '<TYPE>Collection</TYPE><ID>AgentPurchaseVouchers</ID></HEADER><BODY><DESC>' +
        staticVariables(company, from, to) +
        '<TDL><TDLMESSAGE><COLLECTION NAME="AgentPurchaseVouchers" ISMODIFY="No" ISFIXED="No" ' +
        'ISINITIALIZE="No" ISOPTION="No" ISINTERNAL="No"><TYPE>Voucher</TYPE>' +
        '<FILTER>AgentIsPurchaseVoucher</FILTER>' +
        '<NATIVEMETHOD>GUID,Date,VoucherNumber,VoucherTypeName,Reference,PartyLedgerName,' +
        'PartyName,PartyGSTIN,IsCancelled,IsOptional,IsDeleted</NATIVEMETHOD>' +
        '<NATIVEMETHOD>AllInventoryEntries.*</NATIVEMETHOD>' +
        '</COLLECTION>' +
        '<SYSTEM TYPE="Formulae" NAME="AgentIsPurchaseVoucher">' +
        '$VoucherTypeName = "Purchase" OR $VoucherTypeName = "Purchase Order"' +
        '</SYSTEM></TDLMESSAGE></TDL></DESC></BODY></ENVELOPE>'
    );
}

/** The original report-style export, kept as the second thing to try. */
function buildDayBookXml(company: string, from: string, to: string): string {
    return (
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        '<TYPE>Data</TYPE><ID>Day Book</ID></HEADER><BODY><DESC>' +
        staticVariables(company, from, to).replace(
            '</STATICVARIABLES>',
            // Without this Tally exports voucher headers with no inventory
            // lines, and a rate lookup with no rates is the whole feature
            // missing.
            '<EXPLODEFLAG>Yes</EXPLODEFLAG></STATICVARIABLES>',
        ) +
        '</DESC></BODY></ENVELOPE>'
    );
}

/**
 * The request shapes to try, in order of how much this codebase trusts them.
 *
 * TWO SHAPES, ONE PRESS, and that is a deliberate response to how expensive a
 * wrong guess is here: every attempt costs an agent publish and a round trip
 * through somebody standing at the factory PC. Two hypotheses were each
 * shipped alone today and each was only partly right. This build tests both
 * and SAYS WHICH ONE ANSWERED, so the next change is informed rather than
 * guessed.
 *
 * The second is only sent if the first yields nothing, so the ordinary path
 * remains a single read through the Tally gate.
 */
const REQUEST_SHAPES: { name: string; build: (company: string, from: string, to: string) => string }[] = [
    { name: 'collection:voucher', build: buildVoucherCollectionXml },
    { name: 'data:daybook', build: buildDayBookXml },
];

/** One request through the Tally gate — one at a time, ever. */
async function askTally(target: TallyTarget, xml: string): Promise<string> {
    const { data } = await withTallyGate(() =>
        axios.post<string>(`http://${target.host}:${target.port}`, xml, {
            headers: { 'Content-Type': 'text/xml' },
            // Generous: a full financial year of vouchers is several megabytes
            // on this factory's data.
            timeout: 180000,
            responseType: 'text',
        }),
    );

    return typeof data === 'string' ? data : String(data ?? '');
}

/** What one attempt saw — logged whether it answered or not. */
interface Attempt {
    shape: string;
    bytes: number;
    vouchersInDocument: number;
    voucherTypes: Record<string, number>;
    quotableLines: number;
    error?: string;
}

/**
 * Read this factory's purchase vouchers for a date window.
 *
 * TRIES EACH REQUEST SHAPE UNTIL ONE ANSWERS, and records what every attempt
 * saw. The shapes are ordered by how much this codebase trusts them — see
 * REQUEST_SHAPES — and the second is only sent when the first yields nothing,
 * so the ordinary path stays a single read.
 *
 * WHY IT REPORTS SO MUCH. On 31-Aug-2026 three consecutive pulls returned
 * `total: 0` and, from the cloud side, "the factory bought nothing", "Tally
 * refused the request" and "this parser did not understand the answer" were
 * one indistinguishable observation. Each round of guessing cost a publish and
 * somebody walking to the factory PC. The log line below separates them in one
 * read: bytes says whether Tally answered at all, vouchersInDocument says
 * whether the document was understood, and voucherTypes says whether anything
 * in it was a purchase.
 *
 * Counts and voucher-type names ONLY. No rate, party or item name reaches the
 * log — those are Owner/Accounts (FC-06) and this file is on the factory PC.
 */
export async function exportPurchaseRates(target: TallyTarget, from: string, to: string): Promise<PurchaseRateLine[]> {
    const attempts: Attempt[] = [];

    for (const shape of REQUEST_SHAPES) {
        let body = '';

        try {
            body = await askTally(target, shape.build(target.company, from, to));
        } catch (err) {
            // A shape Tally rejects outright must not abandon the read — the
            // next one may be the one it understands.
            attempts.push({
                shape: shape.name,
                bytes: 0,
                vouchersInDocument: 0,
                voucherTypes: {},
                quotableLines: 0,
                error: err instanceof Error ? err.message : String(err),
            });

            continue;
        }

        const lines = parseDayBook(body);
        const seen = describeDayBook(body);

        attempts.push({
            shape: shape.name,
            bytes: body.length,
            vouchersInDocument: seen.vouchers,
            voucherTypes: seen.types,
            quotableLines: lines.length,
        });

        if (lines.length > 0) {
            logger.info(`Purchase-rate read answered by "${shape.name}"`, { attempts, from, to });

            return lines;
        }
    }

    logger.warn('Purchase-rate read found no quotable lines in ANY request shape', { attempts, from, to });

    return [];
}
