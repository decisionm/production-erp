import axios from 'axios';
import { XMLParser } from 'fast-xml-parser';
import logger from '../logger';
import { withTallyGate } from './gate';
import { escapeXml } from './voucherBuilders/xmlHelpers';

/**
 * READS THE FACTORY'S OUTSTANDING POSITION OUT OF TALLY — what clients owe,
 * and what they have ordered that has not shipped.
 *
 * READ-ONLY, ABSOLUTELY. This module exports; it never posts. Nothing here
 * creates, alters or cancels a voucher, and the cloud endpoint it feeds writes
 * two tables that nothing posts from.
 *
 * TWO REPORTS, NOT ONE. Bills Receivable answers "what is owed"; Sales Order
 * Outstanding answers "what is still to ship". They are separate Tally reports
 * with separate shapes, and deriving either from the other would be this agent
 * inventing a number the factory never wrote down.
 *
 * NO PATH IS ASSUMED, ANYWHERE. #66 is the whole reason: the first Day Book
 * parser followed `ENVELOPE.BODY.IMPORTDATA.REQUESTDATA.TALLYMESSAGE`, taken
 * from the factory's own saved export FILES — and a live export over the HTTP
 * gateway answers `EXPORTDATA` instead. That parser read every evidence file
 * perfectly and returned ZERO against the real Tally. So both readers below
 * WALK the parsed document for their node by name and never follow a path.
 *
 * A ZERO READ IS REPORTED WITH WHAT THE DOCUMENT HELD (#64). "The factory is
 * owed nothing" and "this parser did not understand the answer" must never
 * again be the same observation. Counts and node names only reach the log —
 * never a party, a bill reference or an amount, which are Owner/Accounts
 * (FC-06) and the factory PC keeps its log for 30 days.
 *
 * THE RECEIVABLES SHAPE IS NOW MEASURED against the factory exports taken on
 * 02-Sep-2026. `Bills Receivable` answers with paired `DSPACCNAME` and
 * `DSPACCINFO` rows — one party closing balance per pair — rather than putting
 * the party inside `BILLFIXED`. A separately exported single-party detail
 * report carries `BILLFIXED`, `BILLCL` and the dates as flat siblings but does
 * not repeat the party name, so it cannot safely be joined to a client by this
 * all-parties pull. The parser therefore accepts the measured party-summary
 * shape as a balance-only position and still accepts the older nested bill
 * shape for Tally builds that genuinely return it.
 */

export interface TallyTarget {
    host: string;
    port: number;
    company: string;
}

export interface ReceivableBill {
    party_ledger_name: string;
    party_ledger_guid: string | null;
    bill_reference: string | null;
    bill_date: string | null;
    due_date: string | null;
    /** Signed as Tally states it: a credit or advance stays negative. */
    closing_amount: number;
    opening_amount: number | null;
}

export interface PendingSalesOrder {
    party_ledger_name: string;
    party_ledger_guid: string | null;
    order_reference: string | null;
    order_date: string | null;
    due_date: string | null;
    stock_item_name: string | null;
    pending_quantity: number | null;
    quantity_unit: string | null;
    pending_amount: number | null;
}

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

function listOf(node: unknown): any[] {
    if (node == null) return [];
    return Array.isArray(node) ? node : [node];
}

/** Tally's `20260701` → `2026-07-01`; also accepts an already-ISO date. Anything else is null. */
export function parseTallyDate(raw: unknown): string | null {
    const text = textOf(raw);

    if (/^\d{8}$/.test(text)) return `${text.slice(0, 4)}-${text.slice(4, 6)}-${text.slice(6, 8)}`;

    return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : null;
}

/**
 * A Tally amount. Returns null — NEVER 0 — when the field is absent or
 * unreadable: a 0 outstanding is a settled bill, and printing one the factory
 * never stated would take a real debt off somebody's collection list.
 *
 * Tally writes amounts with an optional currency symbol and comma grouping.
 * The sign survives unchanged here; its accounting meaning depends on the
 * report field, so the receivables boundary interprets it separately below.
 */
export function parseAmount(raw: unknown): number | null {
    const text = textOf(raw).replace(/[,\s]/g, '').replace(/^[₹$]/, '');

    if (text === '') return null;

    const value = Number(text);

    return Number.isFinite(value) ? value : null;
}

/**
 * Tally's accounting sign to the page's business sign.
 *
 * Both the factory's detailed receivables export and Tally's documented
 * `DSPCLDRAMTA` examples state a DEBIT balance as a leading negative number.
 * On this page, however, a positive value means "the client owes us" and a
 * negative value means "the client is in credit". Negating once at the Tally
 * boundary keeps that contract consistent through the database and UI.
 */
function receivableAmount(raw: unknown): number | null {
    const tallyAmount = parseAmount(raw);

    return tallyAmount === null ? null : -tallyAmount;
}

/** ` 48.000 Kgs.` → { value: 48, unit: 'Kgs.' } — the quantity idiom from purchaseRates.ts. */
export function parseQuantity(raw: unknown): { value: number | null; unit: string | null } {
    const text = textOf(raw);
    if (text === '') return { value: null, unit: null };

    const match = /^(-?[\d.,]+)\s*(.*)$/.exec(text);
    if (match === null) return { value: null, unit: null };

    const value = Number(match[1].replace(/,/g, ''));

    return { value: Number.isFinite(value) ? value : null, unit: match[2].trim() || null };
}

/**
 * Every node with one of these names, found by walking the document rather
 * than by following a path — see the module docblock (#66).
 */
function findNodes(node: unknown, names: Set<string>, depth = 0): any[] {
    if (node == null || typeof node !== 'object' || depth > 14) return [];

    if (Array.isArray(node)) return node.flatMap((child) => findNodes(child, names, depth + 1));

    const found: any[] = [];

    for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
        if (names.has(key)) {
            found.push(...listOf(value));

            continue;
        }

        found.push(...findNodes(value, names, depth + 1));
    }

    return found;
}

/**
 * What the export actually contained, for the log when it yields nothing.
 *
 * NODE NAMES AND COUNTS ONLY — never a value. This is what tells us, on the
 * first real pull from the factory, whether the tag names above are right,
 * without putting a client's debt in a log file.
 */
export function describeDocument(xml: string): { nodes: Record<string, number>; bytes: number } {
    const nodes: Record<string, number> = {};

    const walk = (node: unknown, depth = 0): void => {
        if (node == null || typeof node !== 'object' || depth > 14) return;

        if (Array.isArray(node)) {
            for (const child of node) walk(child, depth + 1);
            return;
        }

        for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
            // COUNT THE SIBLINGS, NOT THE KEY. fast-xml-parser collapses
            // repeated sibling tags into ONE key holding an array, so counting
            // the key would report two outstanding bills as one — and this
            // function's whole job is to be trustworthy about how much was in
            // a document that yielded nothing.
            nodes[key] = (nodes[key] ?? 0) + (Array.isArray(value) ? value.length : 1);
            walk(value, depth + 1);
        }
    };

    walk(parser.parse(xml));

    return { nodes, bytes: xml.length };
}

/** Tally's Bills Receivable rows live under BILLFIXED, or BILLS on some builds. */
const BILL_NODES = new Set(['BILLFIXED', 'BILLS']);

/** The measured all-parties summary rows in this factory's Tally export. */
const PARTY_NAME_NODES = new Set(['DSPACCNAME']);
const PARTY_BALANCE_NODES = new Set(['DSPACCINFO']);

/**
 * Parse the factory's measured party-summary response.
 *
 * fast-xml-parser groups the interleaved sibling tags into two arrays while
 * preserving the order within each tag. They may only be zipped when the
 * counts agree; a partial pair is a parser failure, not permission to attach
 * one client's money to another client's name.
 */
function parsePartySummary(document: unknown): ReceivableBill[] {
    const names = findNodes(document, PARTY_NAME_NODES);
    const balances = findNodes(document, PARTY_BALANCE_NODES);

    if (names.length === 0 || names.length !== balances.length) return [];

    const bills: ReceivableBill[] = [];

    for (let i = 0; i < names.length; i++) {
        const party = clean(names[i]?.DSPDISPNAME);
        const debit = parseAmount(balances[i]?.DSPCLDRAMT?.DSPCLDRAMTA);
        const credit = parseAmount(balances[i]?.DSPCLCRAMT?.DSPCLCRAMTA);

        if (party === '' || (debit === null && credit === null)) continue;

        // The two columns are Tally-signed components. Sum them first, then
        // cross the sign boundary once: debit-only -1000 => client owes +1000;
        // credit-only +250 => client credit -250; both => the honest net.
        const tallyClosing = (debit ?? 0) + (credit ?? 0);

        bills.push({
            party_ledger_name: party,
            party_ledger_guid: null,
            bill_reference: null,
            bill_date: null,
            due_date: null,
            closing_amount: -tallyClosing,
            opening_amount: null,
        });
    }

    return bills;
}

/** Every outstanding bill in a Bills Receivable export's XML. */
export function parseBillsReceivable(xml: string): ReceivableBill[] {
    const document = parser.parse(xml);
    const bills: ReceivableBill[] = [];

    for (const node of findNodes(document, BILL_NODES)) {
        const party = clean(node?.LEDGERNAME) || clean(node?.PARTYNAME) || clean(node?.BILLPARTY);

        // BILLCL is Tally's closing balance for the bill — what is still
        // outstanding, which is the whole point of the report.
        const closing = receivableAmount(node?.BILLCL ?? node?.CLOSINGBALANCE ?? node?.AMOUNT);

        // A bill with no party cannot be chased, and one with no closing
        // amount is not an outstanding. Neither is emitted half-formed.
        if (party === '' || closing === null) continue;

        bills.push({
            party_ledger_name: party,
            party_ledger_guid: clean(node?.LEDGERGUID) || clean(node?.GUID) || null,
            bill_reference: clean(node?.BILLREF) || clean(node?.NAME) || null,
            bill_date: parseTallyDate(node?.BILLDATE ?? node?.DATE),
            due_date: parseTallyDate(node?.BILLDUEDATE ?? node?.DUEDATE),
            closing_amount: closing,
            opening_amount: receivableAmount(node?.BILLOP ?? node?.OPENINGBALANCE),
        });
    }

    // A Tally build returning real bill rows wins because it carries dates and
    // references. The factory's measured response has no valid nested rows,
    // so it falls through to the paired balance-only summary instead.
    return bills.length > 0 ? bills : parsePartySummary(document);
}

/**
 * Sales Order Outstanding rows. Tally reports these as vouchers with inventory
 * lines carrying the BALANCE (still-to-ship) quantity.
 */
const ORDER_NODES = new Set(['VOUCHER']);

/** Every still-to-ship sales-order line in a Sales Order Outstanding export. */
export function parsePendingSalesOrders(xml: string): PendingSalesOrder[] {
    const orders: PendingSalesOrder[] = [];

    for (const voucher of findNodes(parser.parse(xml), ORDER_NODES)) {
        // Tally's own verdict. A cancelled or deleted order is not something
        // the factory still owes anybody.
        if (
            textOf(voucher?.ISCANCELLED).toLowerCase() === 'yes' ||
            textOf(voucher?.ISDELETED).toLowerCase() === 'yes' ||
            textOf(voucher?.ISOPTIONAL).toLowerCase() === 'yes'
        ) {
            continue;
        }

        const party = clean(voucher?.PARTYLEDGERNAME) || clean(voucher?.PARTYNAME) || clean(voucher?.BASICBASEPARTYNAME);

        if (party === '') continue;

        const orderReference = clean(voucher?.BASICPURCHASEORDERNO) || clean(voucher?.REFERENCE) || clean(voucher?.VOUCHERNUMBER) || null;
        const orderDate = parseTallyDate(voucher?.DATE);
        const guid = clean(voucher?.PARTYLEDGERGUID) || null;

        const lines = listOf(voucher?.['ALLINVENTORYENTRIES.LIST']);

        // An order Tally returned with no inventory line is still a real
        // pending order — it is emitted with the quantities it does not have
        // left null, rather than dropped.
        if (lines.length === 0) {
            orders.push({
                party_ledger_name: party,
                party_ledger_guid: guid,
                order_reference: orderReference,
                order_date: orderDate,
                due_date: parseTallyDate(voucher?.BASICPURCHASEORDERDUEDATE ?? voucher?.DUEDATE),
                stock_item_name: null,
                pending_quantity: null,
                quantity_unit: null,
                pending_amount: null,
            });

            continue;
        }

        for (const entry of lines) {
            // BALANCE is what is STILL DUE on the line. ACTUALQTY is what was
            // ordered, and using it would report a fully-shipped order as
            // entirely outstanding.
            const pending = parseQuantity(entry?.BALANCEQTY ?? entry?.BALANCE);

            // A line with nothing left to ship is not pending. A line whose
            // balance Tally did not state is kept, with a null quantity: it is
            // a real order line, and assuming zero would hide it.
            if (pending.value !== null && pending.value === 0) continue;

            orders.push({
                party_ledger_name: party,
                party_ledger_guid: guid,
                order_reference: orderReference,
                order_date: orderDate,
                due_date: parseTallyDate(entry?.DUEDATE ?? voucher?.BASICPURCHASEORDERDUEDATE),
                stock_item_name: clean(entry?.STOCKITEMNAME) || null,
                pending_quantity: pending.value,
                quantity_unit: pending.unit,
                pending_amount: parseAmount(entry?.AMOUNT),
            });
        }
    }

    return orders;
}

function buildReportXml(company: string, reportId: string, asOf: string): string {
    const tallyDate = asOf.replace(/-/g, '');

    return (
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        // A REPORT REQUEST — AND THIS IS THE OPEN QUESTION ON THIS MODULE.
        //
        // This was written when the Day Book read used the same shape and was
        // believed to be what this factory's Tally answers. #67 then MEASURED
        // the opposite: the purchase-rate read returned zero three times over,
        // and only a TDL COLLECTION got real vouchers out of it. That reader
        // now asks by Collection and falls back to the report, reporting which
        // one answered.
        //
        // Bills Receivable and Sales Order Outstanding are different reports
        // and may answer perfectly well — but that is a hope, not a
        // measurement, and the honest statement is that the first pull may
        // return nothing for exactly the reason #67 found. Which is why these
        // readers say WHAT THE DOCUMENT HELD when they come back empty. Moving
        // them to Collection-with-fallback is the follow-up; it is not done
        // here because guessing a second unmeasured shape is how #66 happened.
        `<TYPE>Data</TYPE><ID>${escapeXml(reportId)}</ID></HEADER><BODY><DESC><STATICVARIABLES>` +
        '<SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>' +
        `<SVFROMDATE>${escapeXml(tallyDate)}</SVFROMDATE>` +
        `<SVTODATE>${escapeXml(tallyDate)}</SVTODATE>` +
        `<SVCURRENTCOMPANY>${escapeXml(company)}</SVCURRENTCOMPANY>` +
        '<EXPLODEFLAG>Yes</EXPLODEFLAG>' +
        '</STATICVARIABLES></DESC></BODY></ENVELOPE>'
    );
}

async function exportReport(target: TallyTarget, reportId: string, asOf: string): Promise<string> {
    const { data } = await withTallyGate(() =>
        axios.post<string>(`http://${target.host}:${target.port}`, buildReportXml(target.company, reportId, asOf), {
            headers: { 'Content-Type': 'text/xml' },
            timeout: 180000,
            responseType: 'text',
        }),
    );

    return typeof data === 'string' ? data : '';
}

/**
 * The outstanding position as at a date: what is owed, and what is still to
 * ship.
 *
 * Both reads go through withTallyGate like every other read — one request to
 * Tally at a time, ever.
 */
export async function exportOutstandingPosition(
    target: TallyTarget,
    asOf: string,
): Promise<{ bills: ReceivableBill[]; orders: PendingSalesOrder[] }> {
    const billsXml = await exportReport(target, 'Bills Receivable', asOf);
    const bills = parseBillsReceivable(billsXml);

    if (bills.length === 0) {
        // Node names and counts only — never a party or an amount (FC-06).
        logger.warn('Receivables read found no receivable rows', { asOf, ...describeDocument(billsXml) });
    }

    const ordersXml = await exportReport(target, 'Sales Order Outstanding', asOf);
    const orders = parsePendingSalesOrders(ordersXml);

    if (orders.length === 0) {
        logger.warn('Receivables read found no pending sales orders', { asOf, ...describeDocument(ordersXml) });
    }

    return { bills, orders };
}
