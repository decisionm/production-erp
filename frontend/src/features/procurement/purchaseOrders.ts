import { type PickerOption, retiredOptionLabel } from '@/components/configuration/pickerOptions';
import { statusColor as tallyStatusColor, statusLabel as tallyStatusLabel } from '@/features/tally-sync/drawer';
import type { Item } from '@/features/inventory/types';
import type { TallyLink } from '@/features/sales/types';
import { itemLabel } from '@/lib/itemLabel';
import type {
    PurchaseOrder,
    PurchaseOrderCan,
    PurchaseOrderLine,
    PurchaseOrderListFilters,
    PurchaseOrderRevision,
    PurchaseOrderRevisionLine,
    PurchaseOrderStatus,
    PurchaseOrderTrace,
    TallyStagingAfter,
    TallyStagingReason,
    TraceConsumption,
    TraceDayBin,
    TraceItemRef,
    TraceLoad,
    TraceLot,
    TraceMovement,
    TraceRateWithheld,
    TraceReceipt,
} from './types';

/**
 * Pure helpers behind the Purchase Orders page, its detail drawer and its
 * trace drawer. No React, no axios — everything here is a function of its
 * arguments so it can be tested without a DOM (purchaseOrders.test.ts).
 * The words here are the words every procurement screen uses: ONE place,
 * so the table, the drawer and the trace cannot drift.
 */

// ------------------------------------------------------------------ status --

export const PURCHASE_ORDER_STATUSES: readonly PurchaseOrderStatus[] = ['draft', 'sent', 'partially_received', 'closed', 'cancelled'];

const STATUS_COLOR: Record<PurchaseOrderStatus, string> = {
    draft: 'default',
    sent: 'blue',
    partially_received: 'gold',
    closed: 'green',
    cancelled: 'red',
};

/** The status Tag — colour + words, sentence case ("Partially received", never the raw enum). An unknown status still renders (readably) rather than throwing the table down. */
export function statusTag(status: PurchaseOrderStatus | string): { color: string; label: string } {
    const words = String(status).replaceAll('_', ' ');

    return {
        color: (STATUS_COLOR as Record<string, string>)[status] ?? 'default',
        label: words.charAt(0).toUpperCase() + words.slice(1),
    };
}

/** The status filter's choices — all five, spelled for people. */
export function statusOptions(): { value: PurchaseOrderStatus; label: string }[] {
    return PURCHASE_ORDER_STATUSES.map((status) => ({ value: status, label: statusTag(status).label }));
}

/**
 * The ERP reference — "PO-{id}" — the SAME spelling the staged Purchase
 * Order voucher carries as its voucher_number (payload.voucher_number_source
 * = 'erp'; Q35(c) may change whose number is authoritative BEFORE the first
 * live write) and the spelling the list's `q` reads back.
 */
export function poNumber(order: number | { id: number }): string {
    return `PO-${typeof order === 'number' ? order : order.id}`;
}

// ------------------------------------------------------------- Tally state --

/**
 * The words for ONE refusal reason the cloud recorded (addendum 6's codes).
 * `detail` is the server's own identity text and is printed, never parsed;
 * an unknown code falls through with its detail — a reason this build has
 * not been taught is still better than a blank.
 */
export function tallyReasonWords(reason: TallyStagingReason): string {
    const detail = typeof reason.detail === 'string' && reason.detail.trim() !== '' ? reason.detail.trim() : null;

    switch (reason.code) {
        case 'party_unmapped':
            return 'vendor has no Tally ledger name';
        case 'item_unmapped':
            return detail ? `item ${detail} has no Tally identity` : 'an item has no Tally identity';
        case 'purchase_ledger_unmapped':
            return 'purchase ledger not mapped';
        case 'godown_unresolved':
            return 'no single Tally godown to allocate to';
        case 'no_lines':
            return 'no lines';
        case 'purchase_orders_disabled':
            return DISABLED_WORDS;
        case 'receipt_notes_disabled':
            return RECEIPT_NOTES_DISABLED_WORDS;
        case 'testing_company_unconfigured':
        case 'allowed_company_unconfigured':
            return 'no allowed Tally company is configured';
        case 'cancelled_before_delivery':
            return 'cancelled before the agent collected it';
        case 'closed_before_delivery':
            return 'closed before the agent collected it';
        default:
            return detail ? `${reason.code}: ${detail}` : String(reason.code);
    }
}

/**
 * The words for the staging record's optional `after` key: the ERP
 * cancelled or closed the order AFTER the agent had collected the voucher,
 * so the queue entry stands and the Tally side is the owner's call — said
 * out loud, never silent. Null when there is nothing to say; an unknown
 * code is carried with the same tail rather than dropped.
 */
export function tallyAfterWords(after: TallyStagingAfter | string | null | undefined): string | null {
    if (typeof after !== 'string' || after.trim() === '') return null;

    switch (after.trim()) {
        case 'cancelled_after_delivery':
            return `cancelled ${AFTER_TAIL}`;
        case 'closed_after_delivery':
            return `closed ${AFTER_TAIL}`;
        default:
            return `${after.trim()} ${AFTER_TAIL}`;
    }
}

const AFTER_TAIL = 'in the ERP after Tally received it (owner question)';
const DISABLED_WORDS = 'PO posting is disabled (owner gate Q35)';
/** Exported for grnTally.ts — the GRN cell's disabled line uses the same words. */
export const RECEIPT_NOTES_DISABLED_WORDS = 'Receipt Note posting is off (open question Q63)';
const NOT_SENT = 'Not sent to Tally';

export type TallyStateKind = 'mirror' | 'link' | 'enqueued' | 'refused' | 'disabled' | 'dismissed' | 'draft' | 'cancelled';

export interface TallyStateLine {
    kind: TallyStateKind;
    /** The one sentence for the Tally cell — with the `after` note appended when there is one. */
    text: string;
    /** antd Tag colour. */
    color: string;
    /** The queue entry, when one exists — the cell deep-links into Tally Sync from it. */
    link: TallyLink | null;
    /**
     * The `after` note on its own (tallyAfterWords), so a cell that renders
     * the link's Tag rather than `text` can still print it; null otherwise.
     */
    note: string | null;
}

/**
 * WHERE THIS ORDER STANDS WITH TALLY, in one line — the ONLY place these
 * words are chosen (P6-03).
 *
 * The order of precedence is the order of certainty:
 *   1. a Tally mirror lives in Tally already — the ERP never posts it;
 *   2. a queue entry (`tally` link) — its status is the live fact, worded
 *      exactly as the Tally Sync page and the Sales pages word it
 *      (imported from there, so the app has ONE status vocabulary);
 *   3. the staging record the cloud wrote at send time — enqueued without a
 *      readable link, refused with its reasons, or disabled by the owner
 *      gate (nothing was queued; the flag is off until Q35/Q39 are answered);
 *      or, written later by the lifecycle, DISMISSED — the order was
 *      cancelled/closed before the agent collected the staged voucher. A
 *      dismissed link and a dismissed record say the same thing, and the
 *      record carries the why, so its words are used and the link kept;
 *      a LIVE link (pending/synced/failed) still outranks it (rule 2);
 *   4. no record at all: a draft has not been sent anywhere; a cancelled
 *      order says so; anything else that is Sent-or-beyond with neither a
 *      link nor a record was sent before staging existed — and since the
 *      flag has never been on, "disabled" is the honest word for it too.
 *
 * Whatever the line, the record's `after` note — cancelled/closed in the ERP
 * AFTER Tally received the voucher; the entry stands; the owner decides —
 * is appended to the sentence and returned on its own as `note`.
 */
export function tallyStateLine(order: Pick<PurchaseOrder, 'status' | 'source' | 'tally_order_no' | 'tally' | 'tally_staging'>): TallyStateLine {
    if (order.source === 'tally') {
        return {
            kind: 'mirror',
            text: order.tally_order_no ? `Lives in Tally — mirror of ${order.tally_order_no}` : 'Lives in Tally — mirror',
            color: 'geekblue',
            link: null,
            note: null,
        };
    }

    const staging = order.tally_staging ?? null;
    const note = tallyAfterWords(staging?.after);
    const withNote = (line: Omit<TallyStateLine, 'note'>): TallyStateLine => ({
        ...line,
        text: note ? `${line.text} — ${note}` : line.text,
        note,
    });

    const link = order.tally ?? null;
    if (link && !(link.status === 'dismissed' && staging?.state === 'dismissed')) {
        return withNote({
            kind: 'link',
            text: tallyStatusLabel[link.status] ?? link.status,
            color: tallyStatusColor[link.status] ?? 'default',
            link,
        });
    }

    if (staging?.state === 'dismissed') {
        const reasons = (staging.reasons ?? []).map(tallyReasonWords).filter((words) => words !== '');

        return withNote({
            kind: 'dismissed',
            text: reasons.length > 0
                ? `${NOT_SENT} — the staged order was dismissed: ${reasons.join('; ')}`
                : `${NOT_SENT} — the staged order was dismissed (no reason recorded)`,
            color: 'default',
            link,
        });
    }
    if (staging?.state === 'enqueued') {
        return withNote({
            kind: 'enqueued',
            text: staging.entry_id
                ? `Queued for Tally — entry #${staging.entry_id} (status not readable here)`
                : 'Queued for Tally (status not readable here)',
            color: 'default',
            link: null,
        });
    }
    if (staging?.state === 'refused') {
        const reasons = (staging.reasons ?? []).map(tallyReasonWords).filter((words) => words !== '');

        return withNote({
            kind: 'refused',
            text: reasons.length > 0 ? `${NOT_SENT} — ${reasons.join('; ')}` : `${NOT_SENT} — refused (no reason recorded)`,
            color: 'orange',
            link: null,
        });
    }
    if (staging?.state === 'disabled') {
        return withNote({ kind: 'disabled', text: `${NOT_SENT} — ${DISABLED_WORDS}`, color: 'default', link: null });
    }

    if (order.status === 'draft') {
        return withNote({ kind: 'draft', text: 'Not sent yet — Tally staging happens on Send', color: 'default', link: null });
    }
    if (order.status === 'cancelled') {
        return withNote({ kind: 'cancelled', text: `${NOT_SENT} — cancelled`, color: 'default', link: null });
    }

    return withNote({ kind: 'disabled', text: `${NOT_SENT} — ${DISABLED_WORDS}`, color: 'default', link: null });
}

// --------------------------------------------------------- lifecycle actions --

export type PurchaseOrderAction = keyof PurchaseOrderCan;

/**
 * The button words, one place. Cancel says "Cancel order" — it sits near
 * Close, which merely stops further receiving, and near every modal's own
 * Cancel button, and a one-word "Cancel" among them read as three different
 * verbs wearing one label (28-Aug audit, item 7).
 */
export const ACTION_LABELS: Record<PurchaseOrderAction, string> = {
    send: 'Send',
    amend: 'Amend',
    close: 'Close',
    cancel: 'Cancel order',
};

/** The order the buttons appear in — the order the lifecycle runs. */
const ACTION_ORDER: readonly PurchaseOrderAction[] = ['send', 'amend', 'close', 'cancel'];

/**
 * The lifecycle buttons to offer: exactly the actions the SERVER said are
 * allowed (`can`, computed in PurchaseOrderService by the same rules the
 * POST actions enforce). No `can` → no buttons: the page never re-derives
 * the state machine from the status.
 */
export function canLabels(can: PurchaseOrderCan | null | undefined): { action: PurchaseOrderAction; label: string }[] {
    if (!can) return [];

    return ACTION_ORDER.filter((action) => can[action] === true).map((action) => ({ action, label: ACTION_LABELS[action] }));
}

/**
 * The close / cancel facts the server recorded, as one sentence — "Closed 12
 * Aug 2026 by Buyer — reason". Every part is conditional because every part
 * is nullable on the wire; null for an order in any other status.
 */
export function lifecycleNote(order: PurchaseOrder): string | null {
    if (order.status === 'closed') {
        return lifecycleSentence('Closed', order.closed_at, order.closed_by, order.closed_reason);
    }
    if (order.status === 'cancelled') {
        return lifecycleSentence('Cancelled', order.cancelled_at, order.cancelled_by, order.cancelled_reason);
    }

    return null;
}

/** "Buyer", "user #4", or nothing — however the server named the actor. */
export function actorWords(by: { id: number; name: string } | string | number | null | undefined): string | null {
    if (by === null || by === undefined) return null;
    if (typeof by === 'string') return by.trim() === '' ? null : by;
    if (typeof by === 'number') return `user #${by}`;

    return by.name?.trim() ? by.name : `user #${by.id}`;
}

function lifecycleSentence(
    verb: string,
    at: string | null | undefined,
    by: { id: number; name: string } | string | number | null | undefined,
    reason: string | null | undefined,
): string {
    let sentence = verb;
    if (at) sentence += ` ${calendarWords(at)}`;
    const who = actorWords(by);
    if (who) sentence += ` by ${who}`;
    if (reason && reason.trim() !== '') sentence += ` — ${reason.trim()}`;

    return sentence;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "12 Aug 2026" from an ISO instant or a date — read as written, no timezone round trip. */
function calendarWords(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
    if (!match) return value;
    const [, year, month, day] = match;

    return `${Number(day)} ${MONTHS[Number(month) - 1] ?? month} ${year}`;
}

/** The revision's snapshot rows, whichever key the server printed them under (`lines`, or the column name `lines_json`). */
export function revisionLines(revision: PurchaseOrderRevision): PurchaseOrderRevisionLine[] {
    return revision.lines ?? revision.lines_json ?? [];
}

// ------------------------------------------------------------ amend prefill --

export interface AmendLineDefaults {
    item_id: number;
    quantity: number;
    /** undefined (typed as number for the form) when the server withheld the rate — see amendFormDefaults. */
    unit_price: number;
    schedules?: { due_date: string; quantity: number; tally_reference?: string }[];
}

/**
 * The order's lines as the amend form's starting values. `unit_price` is
 * prefilled ONLY when the server served it (FC-06 — PurchaseOrderLineResource
 * omits it for a login without finance standing); otherwise the field starts
 * empty and `ratesNotPrefilled` tells the form to say so. Schedules come
 * across as they stand; a line whose item is gone from the master is
 * carried with no item so the form flags it rather than dropping it.
 */
export function amendFormDefaults(order: Pick<PurchaseOrder, 'lines'>): { lines: AmendLineDefaults[]; ratesNotPrefilled: boolean } {
    let ratesNotPrefilled = false;
    const lines = order.lines.map((line): AmendLineDefaults => {
        if (line.unit_price === undefined) ratesNotPrefilled = true;
        const schedules = (line.schedules ?? []).map((schedule) => ({
            due_date: schedule.due_date,
            quantity: Number(schedule.quantity),
            ...(schedule.tally_reference ? { tally_reference: schedule.tally_reference } : {}),
        }));

        return {
            item_id: line.item?.id as number,
            quantity: Number(line.quantity),
            unit_price: line.unit_price === undefined ? (undefined as unknown as number) : Number(line.unit_price),
            ...(schedules.length > 0 ? { schedules } : {}),
        };
    });

    return { lines, ratesNotPrefilled };
}

// ------------------------------------------------------- receipt reconcile --

export type ReceiptLineState = 'open' | 'partial' | 'complete' | 'over' | 'unknown';

export interface ReceiptLineRow {
    line_id: number;
    item: string;
    ordered: string;
    received: string;
    /** ordered − received at four places, floored at 0; '—' when a quantity could not be read. */
    remaining: string;
    state: ReceiptLineState;
}

export type ReceiptReconciliation = ReceiptLineRow[] & {
    summary: { lines: number; complete: number; ordered: string; received: string };
};

/**
 * Received against ordered, per line, from the quantities the order's own
 * lines carry (decimal strings on the wire, four places). Arithmetic is
 * done at four places on integers so 0.1-style float drift cannot make a
 * fully-received line read as short. A quantity that cannot be read is
 * carried as the server sent it and the row's state is `unknown` — never
 * NaN, never a guess.
 */
export function reconcileReceipts(lines: readonly PurchaseOrderLine[]): ReceiptReconciliation {
    let completeCount = 0;
    let orderedTotal = 0n;
    let receivedTotal = 0n;

    const rows = lines.map((line): ReceiptLineRow => {
        const ordered = toScaled(line.quantity);
        const received = toScaled(line.quantity_received);
        if (ordered === null || received === null) {
            return { line_id: line.id, item: itemLabel(line.item), ordered: line.quantity, received: line.quantity_received, remaining: '—', state: 'unknown' };
        }
        orderedTotal += ordered;
        receivedTotal += received;

        const remaining = ordered > received ? ordered - received : 0n;
        const state: ReceiptLineState =
            received === 0n ? 'open' : received < ordered ? 'partial' : received === ordered ? 'complete' : 'over';
        if (state === 'complete' || state === 'over') completeCount += 1;

        return {
            line_id: line.id,
            item: itemLabel(line.item),
            ordered: fromScaled(ordered),
            received: fromScaled(received),
            remaining: fromScaled(remaining),
            state,
        };
    }) as ReceiptReconciliation;

    rows.summary = { lines: lines.length, complete: completeCount, ordered: fromScaled(orderedTotal), received: fromScaled(receivedTotal) };

    return rows;
}

const SCALE = 4;

/** A decimal string → integer ten-thousandths (bigint), or null when it is not a number. */
function toScaled(value: string | number | null | undefined): bigint | null {
    if (value === null || value === undefined) return null;
    const text = String(value).trim();
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);
    if (!match) return null;
    const [, sign, whole, fraction = ''] = match;
    const scaledFraction = (fraction + '0'.repeat(SCALE)).slice(0, SCALE);
    const scaled = BigInt(whole) * 10n ** BigInt(SCALE) + BigInt(scaledFraction);

    return sign === '-' ? -scaled : scaled;
}

function fromScaled(value: bigint): string {
    const negative = value < 0n;
    const abs = negative ? -value : value;
    const whole = abs / 10n ** BigInt(SCALE);
    const fraction = (abs % 10n ** BigInt(SCALE)).toString().padStart(SCALE, '0');

    return `${negative ? '-' : ''}${whole}.${fraction}`;
}

// ------------------------------------------------------------- FC-06 cells --

export const WITHHELD_CELL = 'withheld';

type RateKey = 'unit_price' | 'unit_cost' | 'receipt_rate_per_kg' | 'current_rate_per_kg' | 'rate';

/**
 * A rate cell that is NEVER blank (FC-06 honesty). Procurement's server
 * convention is to OMIT the key for a reader without finance standing, so an
 * absent key IS the ruling and reads "withheld"; a server that instead nulls
 * the value and names it withheld beside it (`rate_withheld`,
 * `unit_cost_withheld`, …) reads the same. A null with no such note is a
 * rate genuinely not recorded — a different fact, said differently.
 */
export function rateCell(row: (Partial<Record<RateKey, string | number | null>> & TraceRateWithheld) | null | undefined, key: RateKey): string {
    if (!row) return WITHHELD_CELL;
    const withheldNote = row.rate_withheld ?? row.unit_cost_withheld ?? row.unit_price_withheld ?? null;
    if (withheldNote !== null && withheldNote !== undefined && withheldNote !== false) return WITHHELD_CELL;

    if (!(key in row) || row[key] === undefined) return WITHHELD_CELL;
    const value = row[key];
    if (value === null) return 'not recorded';

    return String(value);
}

// ------------------------------------------------------------------ filters --

/** What buildPurchaseOrderQuery() hands to axios as `params`. */
export type PurchaseOrderQuery = Record<string, string | number | string[]>;

const FILTER_KEYS: readonly (keyof PurchaseOrderListFilters)[] = ['status', 'vendor_id', 'item_id', 'from', 'to', 'q', 'sort', 'page', 'per_page'];
const NON_NARROWING_KEYS: readonly (keyof PurchaseOrderListFilters)[] = ['sort', 'page', 'per_page'];
const NUMBER_KEYS: readonly (keyof PurchaseOrderListFilters)[] = ['vendor_id', 'item_id', 'page', 'per_page'];

/** ListProcurementDocumentsRequest's ceiling — 1000, not Sales' 100 (the `?po=` deep links relied on it). */
export const MAX_PER_PAGE = 1000;

/**
 * The two statuses a goods receipt may be booked against: an order that has
 * been sent, and one already part-received. Draft is not yet with the
 * vendor; closed and cancelled are done. ONE spelling, so the server-side
 * query the GRN picker asks for and the guard over what comes back cannot
 * drift apart.
 */
export const RECEIVABLE_PO_STATUSES: readonly PurchaseOrderStatus[] = ['sent', 'partially_received'];

/**
 * What the GRN page's order picker asks the server for.
 *
 * The narrowing has to happen SERVER-side. An unfiltered call answers the
 * newest 20 (ProcurementDocumentQuery::PER_PAGE_DEFAULT); filtering those 20
 * in the browser cannot recover an older open order that 21 newer draft or
 * closed ones have already pushed off the page — it is simply absent, with
 * nothing on screen saying so. Same defect the vendor and item pickers had
 * (listAllVendors), same cure: name the statuses, and ask at the list's
 * ceiling (MAX_PER_PAGE) rather than one page of 20.
 *
 * That ceiling is a bound, not "everything": this is the first 1000 OPEN
 * orders, not all of them. 1000 simultaneously-open POs is not a state this
 * factory reaches, and it is the largest page the server will serve — but if
 * it ever were reached, the cure is paging here, not a bigger number.
 *
 * Frozen: it is shared across every render of the picker, and read-only.
 */
export const RECEIVABLE_PO_FILTERS: PurchaseOrderListFilters = Object.freeze({
    status: [...RECEIVABLE_PO_STATUSES],
    per_page: MAX_PER_PAGE,
});

/** Is this order one a receipt may be booked against? The predicate behind RECEIVABLE_PO_STATUSES. */
export function isReceivableOrder(order: { status: PurchaseOrderStatus | string }): boolean {
    return (RECEIVABLE_PO_STATUSES as readonly string[]).includes(order.status);
}

/** The columns ListPurchaseOrdersRequest sorts on, besides id. */
const SORT_FIELDS: readonly string[] = ['id', 'order_date', 'expected_date'];

const SORT_LABELS: Record<string, string> = {
    id: 'Number',
    order_date: 'Order date',
    expected_date: 'Expected date',
};

function calendarDay(value: string): string {
    return /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : value;
}

function sortAllowed(sort: string): boolean {
    return SORT_FIELDS.includes(sort.replace(/^-/, ''));
}

function knownStatuses(values: readonly unknown[]): PurchaseOrderStatus[] {
    return values.filter((value): value is PurchaseOrderStatus => typeof value === 'string' && (PURCHASE_ORDER_STATUSES as readonly string[]).includes(value));
}

/**
 * Filters → axios `params`, everything empty left out. ONE status goes as
 * the bare enum — the spelling ListPurchaseOrdersRequest validated before
 * Phase 6 — and several go as an array (`status[]=…`), which Phase 6's
 * request accepts alongside; a status the server does not know is dropped
 * rather than sent to a 422, as is a sort column it has not got.
 */
export function buildPurchaseOrderQuery(filters: PurchaseOrderListFilters | null | undefined): PurchaseOrderQuery {
    const query: PurchaseOrderQuery = {};
    if (!filters || typeof filters !== 'object') return query;

    for (const key of FILTER_KEYS) {
        const value = filters[key];
        if (value === undefined || value === null) continue;

        if (key === 'status') {
            const statuses = knownStatuses(Array.isArray(value) ? value : [value]);
            if (statuses.length === 1) query.status = statuses[0];
            else if (statuses.length > 1) query.status = statuses;
            continue;
        }

        if (typeof value === 'number') {
            if (!Number.isFinite(value)) continue;
            if (key === 'per_page' && value > MAX_PER_PAGE) continue;
            query[key] = value;
            continue;
        }

        if (typeof value !== 'string') continue;
        const text = value.trim();
        if (text === '') continue;

        if (key === 'sort') {
            if (sortAllowed(text)) query[key] = text;
            continue;
        }

        query[key] = key === 'from' || key === 'to' ? calendarDay(text) : text;
    }

    return query;
}

/** True when the user has NARROWED the list — sort and paging change order and position, not membership. */
export function hasActiveFilters(filters: PurchaseOrderListFilters | null | undefined): boolean {
    const query = buildPurchaseOrderQuery(filters);
    for (const key of NON_NARROWING_KEYS) delete query[key];

    return Object.keys(query).length > 0;
}

/**
 * Filters → the page's search params, so the view lives in the URL (a
 * pasted link reopens the same list). Statuses are repeated keys
 * (`status=sent&status=closed`); page 1 is the default and not written.
 */
export function searchParamsFromFilters(filters: PurchaseOrderListFilters | null | undefined): URLSearchParams {
    const params = new URLSearchParams();
    const query = buildPurchaseOrderQuery(filters);

    for (const key of FILTER_KEYS) {
        const value = query[key];
        if (value === undefined) continue;
        if (key === 'page' && value === 1) continue;
        if (Array.isArray(value)) {
            for (const one of value) params.append(key, one);
            continue;
        }
        params.set(key, String(value));
    }

    return params;
}

/**
 * The page's search params → filters. Numbers must be positive integers
 * (`vendor_id=abc` or `page=0` is a typo, dropped rather than sent to a
 * 422); a per_page above the ceiling is dropped for the same reason; an
 * unknown status is dropped. `status` may be repeated or comma-joined.
 */
export function filtersFromSearchParams(params: URLSearchParams): PurchaseOrderListFilters {
    const filters: PurchaseOrderListFilters = {};

    const statuses = knownStatuses(params.getAll('status').flatMap((value) => value.split(',')).map((value) => value.trim()));
    if (statuses.length > 0) filters.status = [...new Set(statuses)];

    for (const key of FILTER_KEYS) {
        if (key === 'status') continue;
        const raw = params.get(key);
        if (raw === null) continue;
        const text = raw.trim();
        if (text === '') continue;

        if (NUMBER_KEYS.includes(key)) {
            if (!/^\d+$/.test(text)) continue;
            const number = Number(text);
            if (!Number.isInteger(number) || number < 1) continue;
            if (key === 'per_page' && number > MAX_PER_PAGE) continue;
            (filters as Record<string, number>)[key] = number;
            continue;
        }

        if (key === 'sort' && !sortAllowed(text)) continue;

        (filters as Record<string, string>)[key] = key === 'from' || key === 'to' ? calendarDay(text) : text;
    }

    return filters;
}

/** The sort dropdown's choices, newest first as the first (default) choice. */
export function sortOptions(): { value: string; label: string }[] {
    return SORT_FIELDS.flatMap((field) => [
        { value: `-${field}`, label: `${SORT_LABELS[field] ?? field} ↓` },
        { value: field, label: `${SORT_LABELS[field] ?? field} ↑` },
    ]);
}

// -------------------------------------------------------------------- trace --

/** GET /procurement/purchase-orders/{id}/trace answers the object bare or wrapped in `data`; both are read. */
export function unwrapTraceResponse(body: PurchaseOrderTrace | { data: PurchaseOrderTrace }): PurchaseOrderTrace {
    if (body && typeof body === 'object' && 'data' in body && body.data && typeof body.data === 'object' && !('receipts' in body) && !('goods_receipts' in body)) {
        return body.data;
    }

    return body as PurchaseOrderTrace;
}

export type FlatLot = TraceLot & { receipt_id: number | null };
export type FlatMovement = TraceMovement & { receipt_id: number | null };

export interface FlatTrace {
    /** undefined = the server did not send this collection at all ("could not be read"); [] = measured, none. */
    receipts: TraceReceipt[] | undefined;
    lots: FlatLot[] | undefined;
    movements: FlatMovement[] | undefined;
    consumption: TraceConsumption[] | undefined;
}

/**
 * The trace's four collections, each as ONE flat list whatever shape the
 * server chose — lots and movements may ride at the top level, under each
 * receipt, or under each receipt line (PurchaseOrderTraceService nests
 * both under the receipt LINE; the Inventory services return flat lists)
 * — with the receipt each row came from kept beside it, and the line's
 * item stamped on a lot or movement that names none of its own (the
 * server's movement and lot rows carry no item: the line is the item).
 * Rows are de-duplicated by id when both a top-level and a nested copy
 * arrive. An absent collection stays undefined so the drawer can say
 * "could not be read" rather than "none".
 */
export function flattenTrace(trace: PurchaseOrderTrace | null | undefined): FlatTrace {
    const receipts = trace?.receipts ?? trace?.goods_receipts;

    const lots = new Map<number, FlatLot>();
    const movements = new Map<number, FlatMovement>();
    let sawLots = false;
    let sawMovements = false;

    const addLots = (rows: TraceLot[] | undefined, receiptId: number | null, item: TraceItemRef | null) => {
        if (!rows) return;
        sawLots = true;
        for (const lot of rows) {
            if (lots.has(lot.id)) continue;
            lots.set(lot.id, {
                ...lot,
                item: lot.item ?? item,
                receipt_id: receiptId ?? lot.goods_receipt_note_id ?? lot.grn_id ?? null,
            });
        }
    };
    const addMovements = (rows: TraceMovement[] | undefined, receipt: TraceReceipt | null, item: TraceItemRef | null) => {
        if (!rows) return;
        sawMovements = true;
        for (const movement of rows) {
            if (movements.has(movement.id)) continue;
            // The server's movement row names its warehouse by id only; the
            // receipt it hangs under names the store — the same store, since
            // the trace narrows the receipt's movements to its warehouse.
            const warehouse = movement.warehouse ?? (receipt?.warehouse && (movement.warehouse_id === undefined || movement.warehouse_id === null || movement.warehouse_id === receipt.warehouse.id) ? receipt.warehouse : null);
            movements.set(movement.id, {
                ...movement,
                item: movement.item ?? item,
                warehouse,
                receipt_id: receipt?.id ?? movement.goods_receipt_note_id ?? null,
            });
        }
    };

    addLots(trace?.material_lots ?? trace?.lots, null, null);
    addMovements(trace?.stock_movements ?? trace?.movements, null, null);

    for (const receipt of receipts ?? []) {
        addLots(receipt.material_lots ?? receipt.lots, receipt.id, null);
        addMovements(receipt.stock_movements ?? receipt.movements, receipt, null);
        for (const line of receipt.lines ?? []) {
            addLots(line.material_lots ?? line.lots, receipt.id, line.item ?? null);
            addMovements(line.stock_movements, receipt, line.item ?? null);
        }
    }

    // A receipts list that arrived at all means the lot/movement tiers were
    // walked: an order with receipts but nothing nested is "none", not
    // "unreadable".
    if (receipts !== undefined) {
        sawLots = true;
        sawMovements = true;
    }

    return {
        receipts,
        lots: sawLots ? [...lots.values()] : undefined,
        movements: sawMovements ? [...movements.values()] : undefined,
        consumption: trace?.consumption ?? trace?.consumptions ?? trace?.production_entries,
    };
}

// ------------------------------------------------------- loads / consumption --

/**
 * One day-bin LOAD in words: how much, where it was poured (the machine,
 * or the common resin input), and under which batch segment it was
 * recorded — or "outside any batch". FC-01: this is where the material
 * went, not whose it was; the words say "→", never "belongs to".
 */
export function loadWords(load: TraceLoad): string {
    const where = load.work_center?.name?.trim() ? load.work_center.name : 'common input';
    const under =
        load.shift_production_entry_id === null || load.shift_production_entry_id === undefined
            ? 'outside any batch'
            : load.batch_number?.trim()
                ? `batch ${load.batch_number}`
                : `entry #${load.shift_production_entry_id}`;

    return `${load.quantity_kg} kg → ${where} · ${under}`;
}

/** How much of one lot has been poured, across its bags — bags on the lot, pours, kg (four places, no float drift). */
export function lotLoadSummary(lot: TraceLot): { bags: number; loads: number; loaded_kg: string } {
    let loads = 0;
    let loadedKg = 0n;
    for (const bag of lot.bags ?? []) {
        for (const load of bag.loads ?? []) {
            loads += 1;
            loadedKg += toScaled(load.quantity_kg) ?? 0n;
        }
    }

    return { bags: lot.bag_count ?? lot.bags?.length ?? 0, loads, loaded_kg: fromScaled(loadedKg) };
}

export interface NormalisedConsumption {
    entry_id: number | null;
    batch: string | null;
    batch_status: string | null;
    production_date: string | null;
    machine: string | null;
    item: string;
    /** kg of THIS order's bags loaded under the segment (server figure), or null when the server did not say. */
    loaded_kg: string | null;
    /** The day-bin figure for the segment and item; null = not computable yet (no closing count). */
    consumed_kg: string | null;
    /** consumed_kg as a cell — the number, or the reason there is none. Never "0" for "unknown". */
    consumed_words: string;
    day_bin: TraceDayBin | null;
    issues: TraceMovement[];
    /** The Consumption issues summed at four places. */
    issued_qty: string;
}

export const CONSUMPTION_NOT_COMPUTABLE = 'not computable yet (no closing count)';

/**
 * The trace drawer's sentence for a MEASURED-EMPTY consumption list. It
 * never says "nothing consumed": under the confirmed common-input flow a
 * resin bag belongs to no machine and no batch (FC-01), and consumption is
 * not attributable to one order's bags through the day-bin ledger — an
 * empty list is "not attributable", not "not consumed". The item's own
 * Consumption movements live on the batches that consumed it. One place, so
 * the drawer cannot drift from the constitution.
 */
export const CONSUMPTION_NONE_WORDS =
    'No consumption is attributable to this order\'s bags through the day-bin ledger — under the common input a bag belongs to no machine or batch (FC-01). The item\'s Consumption movements live on the batches that consumed it.';

/**
 * One consumption row as the drawer prints it, from either spelling the
 * server may use — PurchaseOrderTraceService's (segment, item) row with the
 * day-bin figure and the batch's stock issues, or a narrower backend's flat
 * row. A null consumed figure is said as such: the day-bin formula needs a
 * closing count, and "not computable" is not zero.
 */
export function consumptionRow(row: TraceConsumption): NormalisedConsumption {
    const entry = row.shift_production_entry ?? null;
    const entryId = entry?.id ?? row.shift_production_entry_id ?? null;
    const batch = firstText(entry?.batch_number, row.batch_number, row.batch_no);
    const machine = firstText(entry?.work_center?.name, typeof row.machine === 'string' ? row.machine : row.machine?.name);
    const dayBin = row.day_bin ?? null;
    const consumed = firstText(dayBin?.consumed_kg, row.quantity);
    const issues = row.stock_issues ?? [];

    let issued = 0n;
    for (const issue of issues) issued += toScaled(issue.quantity) ?? 0n;

    return {
        entry_id: entryId,
        batch,
        batch_status: firstText(entry?.batch_status),
        production_date: firstText(entry?.production_date, row.production_date, row.consumed_at),
        machine,
        item: itemLabel(row.item),
        loaded_kg: firstText(row.loaded_kg_from_this_order),
        consumed_kg: consumed,
        consumed_words: consumed ?? CONSUMPTION_NOT_COMPUTABLE,
        day_bin: dayBin,
        issues,
        issued_qty: fromScaled(issued),
    };
}

// ------------------------------------------------------- item eligibility --

/**
 * MAY THIS ITEM BE PUT ON A NEW PURCHASE-ORDER LINE — the picker's copy of
 * `PurchasableItem`, the server rule it must agree with.
 *
 * ONE TEST: `is_active`. The server refuses an item that is archived OR
 * soft-deleted; this checks only the first half, and that is not drift —
 * `ItemService::paginate` queries through the SoftDeletes scope, so a trashed
 * item never reaches this list to be offered. The server checks both because
 * it takes an id from any client, not only from this picker.
 *
 * What KIND of thing the item is plays no part — a finished good, a
 * consumable and an item nobody has classified are all offered, because Q59
 * is open and says a document must not start refusing an item on its
 * category until that is answered. The classification is deliberately not
 * carried to this screen at all, so there is nothing here to filter on by
 * accident, and re-adding the filter would mean re-adding the type.
 *
 * Neither direction of drift is harmless. Offering a choice the server
 * refuses hands the buyer a validation error for a row the screen invited;
 * refusing one the server accepts hides a real material and looks like
 * missing data. So this moves only when `PurchasableItem` moves first.
 */
export function isPurchasableItem(item: Pick<Item, 'is_active'>): boolean {
    return item.is_active;
}

/**
 * The item ids the order being AMENDED already names — what
 * `purchasableItemOptions` must keep visible.
 *
 * `PurchaseOrderLine.item` is typed as required, and on the wire it is not
 * guaranteed: a line whose item the payload omitted has to DROP OUT here,
 * not reach the picker as `undefined` and not throw on the way. The optional
 * hops and the numeric filter are both load-bearing for that reason — this
 * is not defensive noise to tidy away.
 *
 * `undefined`/`null` lines = a NEW order, so nothing is kept.
 */
export function amendedItemIds(lines: readonly PurchaseOrderLine[] | undefined | null): number[] {
    return (lines ?? []).map((line) => line?.item?.id).filter((id): id is number => typeof id === 'number');
}

/**
 * The item options a purchase-order line may offer: every ACTIVE item, plus
 * — last, marked `(Retired)` and disabled — any item the lines being EDITED
 * already name that would not be offered.
 *
 * This exists rather than a `keep:` on `activePickerOptions` because a
 * purchase order has MANY lines and that helper keeps one row. The marker
 * word is imported rather than spelled again, so the two cannot drift.
 *
 * `keepIds` is empty for a NEW order and holds the amended draft's current
 * items for an amend — dropping those would blank a line and quietly edit
 * what the draft says. They are shown, not choosable: the only thing a buyer
 * can do about a retired line is point it at a live item, which is exactly
 * what `AmendPurchaseOrderRequest` now enforces.
 */
export function purchasableItemOptions(
    items: readonly Item[] | undefined | null,
    keepIds: readonly number[] = [],
): PickerOption[] {
    const offered: PickerOption[] = [];
    const kept: PickerOption[] = [];
    const keep = new Set(keepIds);

    for (const item of items ?? []) {
        const option: PickerOption = { value: item.id, label: itemLabel(item) };

        if (isPurchasableItem(item)) {
            offered.push(option);
            continue;
        }

        // Only an item a line actually names survives, and only once — a
        // list that repeats an id must not print it twice.
        if (keep.has(item.id) && !kept.some((row) => row.value === item.id)) {
            kept.push({ ...option, label: retiredOptionLabel(option.label), disabled: true });
        }
    }

    return [...offered, ...kept];
}

/** The first argument that is a non-empty string, else null. */
function firstText(...values: (string | null | undefined)[]): string | null {
    for (const value of values) {
        if (typeof value === 'string' && value.trim() !== '') return value;
    }

    return null;
}
