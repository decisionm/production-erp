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
 *
 * BILL-WISE IS ASKED FOR FIRST, AS OF 0.4.7. A balance-only position cannot be
 * aged: with no bill date and no due date, every rupee lands in the page's "no
 * due date" bucket and the 1-30/31-60/61-90/90+ columns are empty for good. So
 * the bills are now requested as a TDL COLLECTION — the shape #67 measured
 * this Tally actually answers — and the report request is kept as the second
 * thing to try. Which one answered, and how many rows carried a due date, are
 * both reported: a pull that cannot tell "owed nothing" from "answered without
 * ageing" costs a publish and an operator's press to find out.
 *
 * AND THE WINDOW IS NO LONGER ONE DAY. Both reads asked with SVFROMDATE =
 * SVTODATE = the as-at date, which can only describe bills raised that
 * morning. An outstanding position is an as-at reading over the whole book.
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
const MONTHS: Record<string, string> = {
    jan: '01', feb: '02', mar: '03', apr: '04', may: '05', jun: '06',
    jul: '07', aug: '08', sep: '09', oct: '10', nov: '11', dec: '12',
};

export function parseTallyDate(raw: unknown): string | null {
    const text = textOf(raw);

    if (/^\d{8}$/.test(text)) return `${text.slice(0, 4)}-${text.slice(4, 6)}-${text.slice(6, 8)}`;

    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;

    // THE FORM A HUMAN-READABLE TALLY REPORT USES: `3-Aug-26`, `27-Jul-26`,
    // `26-Jul-24`. Measured on the factory's own Group Outstandings export
    // (03-Sep-2026). Until this, every due date on that report parsed as null
    // and the whole ageing spine stayed empty even when Tally had stated the
    // date plainly.
    //
    // The two-digit year is windowed 2000-2099. That is not a guess about the
    // factory: Tally writes the century-less form only in a report whose own
    // period is set by the caller, and this agent asks for a window that
    // begins in 1990 — a bill from 1926 is not a thing this system can hold.
    const dmy = /^(\d{1,2})-([A-Za-z]{3})-(\d{2}|\d{4})$/.exec(text);

    if (dmy === null) return null;

    const month = MONTHS[dmy[2].toLowerCase()];

    if (month === undefined) return null;

    const year = dmy[3].length === 2 ? `20${dmy[3]}` : dmy[3];

    return `${year}-${month}-${dmy[1].padStart(2, '0')}`;
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

/**
 * A credit period Tally stated AS A NUMBER OF DAYS — `30 Days`, `45 day`.
 *
 * Anything else is null, deliberately. Tally permits a party with no credit
 * terms at all, and a period written some way this does not recognise is a
 * shape nobody has measured; both must reach the page as "no due date", which
 * is its own bucket there precisely so an unstated term is never asserted.
 */
export function parseCreditPeriodDays(raw: unknown): number | null {
    const match = /^(\d+)\s*days?$/i.exec(textOf(raw));

    if (match === null) return null;

    const days = Number(match[1]);

    return Number.isFinite(days) ? days : null;
}

/** An ISO date plus n days, in UTC — no wall clock is involved in a due date. */
function addDays(iso: string, days: number): string | null {
    const at = Date.parse(`${iso}T00:00:00Z`);

    if (!Number.isFinite(at)) return null;

    return new Date(at + days * 86_400_000).toISOString().slice(0, 10);
}

/**
 * WHEN THIS BILL FALLS DUE.
 *
 * Tally's own answer wherever it gives one. Where it does not, arithmetic on
 * two values Tally itself stated — the bill date and a credit period it
 * returned — because that is what Tally's own Bills Outstanding screen shows
 * in the "Due on" column, and a bill-wise read that cannot age anything is the
 * whole feature missing.
 *
 * NEVER A HOUSE TERM, AND NEVER A ZERO. If Tally states no period, or states
 * one in a shape not measured here, the answer is null. The page keeps "no due
 * date" as a bucket of its own, never folded into "current" and never into
 * "90+", exactly so that an unstated term is not invented here; and this repo
 * has already had to withdraw one derived value from live.
 */
export function dueDateOf(node: Record<string, unknown> | null | undefined, billDate: string | null): string | null {
    const stated = parseTallyDate(node?.BILLDUEDATE ?? node?.DUEDATE);

    if (stated !== null) return stated;

    // A Bills COLLECTION answers with the credit period rather than a due
    // date. Tally writes that either as a real date or as a count of days.
    const period = node?.BILLCREDITPERIOD ?? node?.CREDITPERIOD;

    const asDate = parseTallyDate(period);

    if (asDate !== null) return asDate;

    const days = parseCreditPeriodDays(period);

    if (days === null || billDate === null) return null;

    return addDays(billDate, days);
}

/**
 * THE SHAPE THE FACTORY'S TALLY ACTUALLY RETURNS — measured 03-Sep-2026 from a
 * Group Outstandings → Sundry Debtors → Pending Bills export of the live
 * company (621 bills across 135 parties).
 *
 * IT IS A FLAT, ORDERED STREAM, NOT A TREE. Every value belongs to the
 * `BILLFIXED` that PRECEDES it, as a SIBLING:
 *
 *     BILLFIXED  date=""        ref=""         party="A. ABUSHAHIR"   <- party header
 *     BILLOP ""  BILLCL ""  BILLDUE ""  BILLOVERDUE ""                <- header has no values
 *     BILLFIXED  date="3-Aug-26"  ref="567"    party=""               <- a bill
 *     BILLOP "13977.000"  BILLCL "13977.000"  BILLDUE "3-Aug-26"      <- ITS values, as siblings
 *     BILLFIXED  date=""        ref=""         party=""               <- subtotal separator
 *     LEDBILLOP ...  LEDBILLCL ...                                    <- the party's totals
 *
 * WHY THIS IS THE WHOLE BUG. The reader above looks for BILLCL as a CHILD of
 * BILLFIXED. In this shape BILLFIXED's only children are BILLDATE, BILLREF and
 * BILLPARTY — so every row failed the `closing === null` guard and was dropped,
 * and the pull posted zero. The ERP then correctly refused to wipe a standing
 * position on an empty answer, and the page stayed empty while Tally had been
 * answering with 621 bills, each carrying a due date, all along.
 *
 * THE PARTY IS CARRIED FORWARD. Only the header row names it; the bills under
 * it repeat nothing. So a party is remembered until the next header — and a
 * bill found before any header is emitted with no party rather than attached
 * to the wrong one.
 *
 * ORDER IS THE ONLY THING THAT ASSOCIATES A VALUE WITH ITS BILL, which is why
 * this parses with `preserveOrder` instead of the shared parser: the ordinary
 * one collapses repeated siblings into per-tag arrays, and those arrays do not
 * line up — this export holds 891 BILLFIXED against 756 BILLCL. Zipping them
 * would attach one client's money to another client's name.
 *
 * LEDBILL* ROWS ARE IGNORED. They are Tally's own per-party subtotals; keeping
 * them would double every party's balance.
 */
const orderedParser = new XMLParser({
    ignoreAttributes: false,
    parseTagValue: false,
    trimValues: true,
    preserveOrder: true,
});

/** The tag name of a preserveOrder node, ignoring its attribute bag. */
function orderedName(node: Record<string, unknown>): string {
    return Object.keys(node).filter((key) => key !== ':@')[0] ?? '';
}

/** The text of a preserveOrder leaf node. */
function orderedText(node: Record<string, unknown>): string {
    const value = node[orderedName(node)];

    if (!Array.isArray(value)) return '';

    const leaf = value.find((child: Record<string, unknown>) => '#text' in child);

    return leaf === undefined ? '' : clean(leaf['#text']);
}

/** Every node in the document, in document order, flattened depth-first. */
function orderedNodes(nodes: unknown, depth = 0): Record<string, unknown>[] {
    if (!Array.isArray(nodes) || depth > 14) return [];

    const out: Record<string, unknown>[] = [];

    for (const node of nodes) {
        if (node === null || typeof node !== 'object') continue;

        const record = node as Record<string, unknown>;
        const key = orderedName(record);

        if (key === '' || key === '#text') continue;

        out.push(record);

        // BILLFIXED's own children are read by the caller; anything else may
        // be a wrapper (ENVELOPE, BODY, an export container) whose children
        // are the real stream.
        if (key !== 'BILLFIXED') out.push(...orderedNodes(record[key], depth + 1));
    }

    return out;
}

export function parseGroupOutstandings(xml: string): ReceivableBill[] {
    const nodes = orderedNodes(orderedParser.parse(xml));

    const bills: ReceivableBill[] = [];

    let party: string | null = null;
    let current: ReceivableBill | null = null;

    const flush = (): void => {
        // A row Tally gave no closing amount for is a header or a separator,
        // not an outstanding. Never emitted, never counted as zero.
        if (current !== null && current.closing_amount !== null) bills.push(current);
        current = null;
    };

    for (const node of nodes) {
        const key = orderedName(node);

        if (key === 'BILLFIXED') {
            flush();

            const fields: Record<string, string> = {};

            for (const child of orderedNodes(node.BILLFIXED, 14)) {
                fields[orderedName(child)] = orderedText(child);
            }

            // A header names the party for everything beneath it and is not
            // itself a bill.
            if (fields.BILLPARTY !== undefined && fields.BILLPARTY !== '') {
                party = fields.BILLPARTY;

                continue;
            }

            // No party, no reference and no date: the separator that precedes
            // a party's LEDBILL* subtotals.
            if ((fields.BILLREF ?? '') === '' && (fields.BILLDATE ?? '') === '') continue;

            current = {
                party_ledger_name: party ?? '',
                party_ledger_guid: null,
                bill_reference: fields.BILLREF || null,
                bill_date: parseTallyDate(fields.BILLDATE),
                due_date: null,
                // Filled from the siblings below; null until one says so.
                closing_amount: null as unknown as number,
                opening_amount: null,
            };

            continue;
        }

        if (current === null) continue;

        const value = orderedText(node);

        // Tally states a receivable DEBIT as negative here (measured: a Sales
        // bill of 11,597 Dr arrives as -11597.000). The page contract is
        // positive-means-owed, so the sign crosses once, here, exactly as the
        // other readers do it.
        if (key === 'BILLCL') current.closing_amount = receivableAmount(value) as number;
        if (key === 'BILLOP') current.opening_amount = receivableAmount(value);
        if (key === 'BILLDUE') current.due_date = parseTallyDate(value);
    }

    flush();

    // A bill nobody can be chased for is not reportable.
    return bills.filter((bill) => bill.party_ledger_name !== '');
}

/** Every outstanding bill in a Bills Receivable export's XML. */
export function parseBillsReceivable(xml: string): ReceivableBill[] {
    const document = parser.parse(xml);
    const bills: ReceivableBill[] = [];

    for (const node of findNodes(document, BILL_NODES)) {
        // PARENT is the party on a Bills COLLECTION row — a bill object's
        // parent IS its ledger. The report shapes name it one of the others.
        const party =
            clean(node?.LEDGERNAME) || clean(node?.PARTYNAME) || clean(node?.BILLPARTY) || clean(node?.PARENT);

        // BILLCL is Tally's closing balance for the bill — what is still
        // outstanding, which is the whole point of the report.
        const closing = receivableAmount(node?.BILLCL ?? node?.CLOSINGBALANCE ?? node?.AMOUNT);

        // A bill with no party cannot be chased, and one with no closing
        // amount is not an outstanding. Neither is emitted half-formed.
        if (party === '' || closing === null) continue;

        const billDate = parseTallyDate(node?.BILLDATE ?? node?.DATE);

        bills.push({
            party_ledger_name: party,
            party_ledger_guid: clean(node?.LEDGERGUID) || clean(node?.GUID) || null,
            bill_reference: clean(node?.BILLREF) || clean(node?.NAME) || null,
            bill_date: billDate,
            due_date: dueDateOf(node, billDate),
            closing_amount: closing,
            opening_amount: receivableAmount(node?.BILLOP ?? node?.OPENINGBALANCE),
        });
    }

    // A Tally build returning real NESTED bill rows wins because it carries
    // dates and references as children.
    if (bills.length > 0) return bills;

    // Then the shape this factory's Tally actually returns: the same fields,
    // but as a flat ordered stream with the party on a header row. This is
    // where 03-Sep-2026's 621 bills live, and reading it is what turned a
    // permanently empty page into a real position.
    const grouped = parseGroupOutstandings(xml);

    if (grouped.length > 0) return grouped;

    // Last, 0.4.6's measured all-parties summary: balances with no bill
    // detail. Honest, but it can age nothing.
    return parsePartySummary(document);
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

/**
 * WHERE AN OUTSTANDING POSITION STARTS.
 *
 * An outstanding position is an AS-AT reading: every bill still open today was
 * raised before today, most of them in an earlier month and some in an earlier
 * year. Until 0.4.7 both reads asked with SVFROMDATE = SVTODATE = the as-at
 * day, which is a ONE-DAY WINDOW — it can only ever describe bills and orders
 * dated that morning.
 *
 * The from-date is therefore deliberately earlier than this company's books
 * can possibly begin, so that no live bill is excluded by the window. SVTODATE
 * remains the as-at date, which is the half that carries the meaning.
 */
const BOOKS_BEGIN = '19900401';

/** `2026-09-03` → `20260903`, Tally's own date literal. */
function toTallyDate(iso: string): string {
    return iso.replace(/-/g, '');
}

function staticVariables(company: string, asOf: string): string {
    return (
        '<STATICVARIABLES><SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>' +
        `<SVFROMDATE>${escapeXml(BOOKS_BEGIN)}</SVFROMDATE>` +
        `<SVTODATE>${escapeXml(toTallyDate(asOf))}</SVTODATE>` +
        `<SVCURRENTCOMPANY>${escapeXml(company)}</SVCURRENTCOMPANY>`
    );
}

/**
 * A COLLECTION over BILLS — bill-wise, with the dates.
 *
 * WHY THIS EXISTS. The report request below is what this module has always
 * sent, and against this factory's Tally it answers with the CONDENSED
 * party-summary shape: one closing balance per party, no bill reference, no
 * bill date, no due date. That answer is honest and the parser keeps handling
 * it, but it cannot age anything — every rupee lands in the page's "no due
 * date" bucket and the 1-30/31-60/61-90/90+ columns stay empty for good.
 *
 * A bill object carries what the summary drops. `Parent` is the party ledger,
 * `Name` the bill reference, and `BillDate` + `BillCreditPeriod` are what
 * Tally's own "Due on" column is computed from.
 *
 * THE SHAPE IS ASKED THE WAY THE READS THAT WORK HERE ASK. #67 measured that
 * this Tally answers a TDL Collection and returned zero three times to a
 * report request; masters.ts and stockSummary.ts are Collections and both
 * work. The COLLECTION/NATIVEMETHOD idiom below is copied from
 * purchaseRates.ts rather than invented.
 *
 * IT IS STILL UNMEASURED, AND IS TREATED THAT WAY. Nobody has run this against
 * the factory's Tally, an unrecognised NATIVEMETHOD can yield an empty
 * collection rather than an error, and that is exactly #64's failure mode. So
 * this is the FIRST of two shapes rather than the only one, every attempt is
 * described by node name and count, and the pull says WHICH SHAPE ANSWERED —
 * so the next press is informed rather than costing another publish to learn.
 */
export function buildBillsCollectionXml(company: string, asOf: string): string {
    return (
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        '<TYPE>Collection</TYPE><ID>AgentReceivableBills</ID></HEADER><BODY><DESC>' +
        staticVariables(company, asOf) +
        '</STATICVARIABLES>' +
        '<TDL><TDLMESSAGE><COLLECTION NAME="AgentReceivableBills" ISMODIFY="No" ISFIXED="No" ' +
        'ISINITIALIZE="No" ISOPTION="No" ISINTERNAL="No"><TYPE>Bills</TYPE>' +
        '<NATIVEMETHOD>Name,Parent,BillDate,BillCreditPeriod,ClosingBalance,OpeningBalance,IsAdvance</NATIVEMETHOD>' +
        '</COLLECTION></TDLMESSAGE></TDL></DESC></BODY></ENVELOPE>'
    );
}

/** The report request this module has always sent, kept as the fallback. */
export function buildReportXml(company: string, reportId: string, asOf: string): string {
    return (
        '<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST>' +
        `<TYPE>Data</TYPE><ID>${escapeXml(reportId)}</ID></HEADER><BODY><DESC>` +
        staticVariables(company, asOf) +
        // Without this Tally exports the condensed view; it is what asks for
        // the bill rows underneath a party. It has not been enough on its own
        // against this factory's Tally, which is why the Collection is tried
        // first, but it costs nothing and the report is strictly worse without.
        '<EXPLODEFLAG>Yes</EXPLODEFLAG>' +
        '</STATICVARIABLES></DESC></BODY></ENVELOPE>'
    );
}

/** One request through the Tally gate — one at a time, ever. */
async function askTally(target: TallyTarget, xml: string): Promise<string> {
    const { data } = await withTallyGate(() =>
        axios.post<string>(`http://${target.host}:${target.port}`, xml, {
            headers: { 'Content-Type': 'text/xml' },
            timeout: 180000,
            responseType: 'text',
        }),
    );

    return typeof data === 'string' ? data : '';
}

async function exportReport(target: TallyTarget, reportId: string, asOf: string): Promise<string> {
    return askTally(target, buildReportXml(target.company, reportId, asOf));
}

/**
 * The bill request shapes, in the order this codebase trusts them.
 *
 * The second is only sent if the first yields nothing, so the ordinary path
 * stays a single read through the Tally gate.
 */
const BILL_REQUEST_SHAPES: { name: string; build: (company: string, asOf: string) => string }[] = [
    { name: 'collection:bills', build: buildBillsCollectionXml },
    { name: 'data:billsreceivable', build: (company, asOf) => buildReportXml(company, 'Bills Receivable', asOf) },
];

/** What one attempt saw — logged whether it answered or not. */
interface BillAttempt {
    shape: string;
    bytes: number;
    rows: number;
    withDueDate: number;
    nodes?: Record<string, number>;
    error?: string;
}

/**
 * Read the outstanding bills, trying each shape until one answers.
 *
 * REPORTS WHICH SHAPE ANSWERED AND HOW MANY ROWS CARRIED A DUE DATE. Those two
 * numbers are the whole point: "the factory is owed nothing", "this Tally will
 * not answer a Collection" and "it answered, but with no ageing in it" are
 * three different situations, and a pull that cannot tell them apart costs an
 * agent publish and somebody standing at the factory PC to find out which.
 */
async function exportBills(target: TallyTarget, asOf: string): Promise<ReceivableBill[]> {
    const attempts: BillAttempt[] = [];

    for (const shape of BILL_REQUEST_SHAPES) {
        let xml = '';

        try {
            xml = await askTally(target, shape.build(target.company, asOf));
        } catch (err) {
            attempts.push({
                shape: shape.name,
                bytes: 0,
                rows: 0,
                withDueDate: 0,
                error: err instanceof Error ? err.message : String(err),
            });

            continue;
        }

        const bills = parseBillsReceivable(xml);
        const withDueDate = bills.filter((bill) => bill.due_date !== null).length;

        if (bills.length > 0) {
            attempts.push({ shape: shape.name, bytes: xml.length, rows: bills.length, withDueDate });
            // Counts and shape names only — never a party, a bill reference or
            // an amount, which are Owner/Accounts (FC-06) and this log sits on
            // the factory PC for 30 days.
            logger.info('Receivables read answered', { asOf, answeredBy: shape.name, attempts });

            return bills;
        }

        // describeDocument supplies `bytes` alongside the node census — what
        // the document HELD is the whole value of a zero read (#64).
        attempts.push({ shape: shape.name, rows: 0, withDueDate: 0, ...describeDocument(xml) });
    }

    logger.warn('Receivables read found no receivable rows', { asOf, attempts });

    return [];
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
    // Bill-wise first, the report second — and it says which one answered.
    const bills = await exportBills(target, asOf);

    // NEITHER READ MAY TAKE THE WHOLE PULL DOWN WITH IT.
    //
    // Until 0.4.7 a throw from either export propagated out of
    // runReceivablesSync, so the agent posted NOTHING: no receivables row, no
    // `receivables.received` event, no counts — the ERP could not distinguish
    // "the operator never pressed it" from "Tally refused the request". That
    // is exactly the state the live instance sat in on 03-Sep-2026: a healthy
    // agent doing successful `masters.received` reads minutes apart, an
    // operator who had pressed the button, and total silence on this path.
    //
    // A failed read now yields an EMPTY list and a logged reason, and the pull
    // goes on to post. Posting an empty position is safe by design — the cloud
    // declines to wipe a standing position on an entirely empty pull and
    // answers `skipped_empty` — so the cost is nothing and the gain is that
    // every press leaves a trace on both sides.
    let orders: PendingSalesOrder[] = [];

    try {
        const ordersXml = await exportReport(target, 'Sales Order Outstanding', asOf);

        orders = parsePendingSalesOrders(ordersXml);

        if (orders.length === 0) {
            logger.warn('Receivables read found no pending sales orders', { asOf, ...describeDocument(ordersXml) });
        }
    } catch (err) {
        // The message only — never a URL with a company in it, never a body.
        logger.error('Sales Order Outstanding read failed', {
            asOf,
            message: err instanceof Error ? err.message : String(err),
        });
    }

    return { bills, orders };
}
