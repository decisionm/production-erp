import type { InvoiceStatus, SalesDocumentKind, SalesListFilters, SalesOrderStatus } from './types';

/**
 * Pure helpers behind the Sales list pages' filter bars — orders,
 * deliveries, invoices. No React, no axios: everything here is a function
 * of its arguments so it can be tested without a DOM (filters.test.ts).
 * Mirrors tally-sync/filters.ts on purpose; the two bars should feel like
 * one bar.
 */

/** What buildSalesQuery() hands to axios as `params`. */
export type SalesQuery = Record<string, string | number>;

/**
 * The filter keys EACH endpoint knows. buildSalesQuery() reads ONLY these
 * off its argument: the Deliveries / Invoices pages hand listSalesOrders
 * straight to useQuery as a queryFn for their order pickers, so TanStack's
 * context object arrives as the first argument — an allowlist means that
 * object contributes nothing to the URL. Per document, because a delivery
 * has no status and an order has no sales_order_id: sending either where
 * it does not belong is a key the server ignores today and might 422
 * tomorrow.
 */
const FILTER_KEYS: Record<SalesDocumentKind, readonly (keyof SalesListFilters)[]> = {
    sales_order: ['customer_id', 'status', 'from', 'to', 'item_id', 'q', 'sort', 'page', 'per_page'],
    delivery: ['customer_id', 'sales_order_id', 'from', 'to', 'item_id', 'q', 'sort', 'page', 'per_page'],
    invoice: ['customer_id', 'sales_order_id', 'status', 'from', 'to', 'item_id', 'q', 'sort', 'page', 'per_page'],
};

/** Keys that change ORDER or PAGE, not membership — never "a filter" for the honesty copy. */
const NON_NARROWING_KEYS: readonly (keyof SalesListFilters)[] = ['sort', 'page', 'per_page'];

/** The integer-valued keys, for reading a URL back. */
const NUMBER_KEYS: readonly (keyof SalesListFilters)[] = ['customer_id', 'sales_order_id', 'item_id', 'page', 'per_page'];

/** The server's per_page ceiling (1..100, default 20). */
export const MAX_PER_PAGE = 100;

/**
 * The columns each document can be sorted on — the server's list, `-`
 * prefixed for descending. Anything else the server answers with a 422, so
 * buildSalesQuery() drops it instead of sending it.
 */
const SORT_FIELDS: Record<SalesDocumentKind, readonly string[]> = {
    sales_order: ['id', 'order_date', 'expected_date'],
    delivery: ['id', 'delivered_date'],
    invoice: ['id', 'invoice_date'],
};

const SORT_LABELS: Record<string, string> = {
    id: 'Number',
    order_date: 'Order date',
    expected_date: 'Expected date',
    delivered_date: 'Delivered date',
    invoice_date: 'Invoice date',
};

const SALES_ORDER_STATUSES: readonly SalesOrderStatus[] = ['draft', 'confirmed', 'partially_delivered', 'completed', 'cancelled'];
const INVOICE_STATUSES: readonly InvoiceStatus[] = ['draft', 'issued', 'paid'];

/**
 * A calendar day on the wire is YYYY-MM-DD, full stop. The RangePicker
 * already emits that; anything ISO-shaped with a time part is cut to its
 * date so a caller passing an instant does not send a string the server's
 * date validation refuses.
 */
function calendarDay(value: string): string {
    return /^\d{4}-\d{2}-\d{2}/.test(value) ? value.slice(0, 10) : value;
}

function sortAllowed(kind: SalesDocumentKind, sort: string): boolean {
    return SORT_FIELDS[kind].includes(sort.replace(/^-/, ''));
}

/**
 * Filters → axios `params`, with everything empty left out and only THIS
 * document's keys read. Numbers go as numbers, text trimmed, dates cut to
 * the day, an unknown sort dropped.
 */
export function buildSalesQuery(kind: SalesDocumentKind, filters: SalesListFilters | null | undefined): SalesQuery {
    const query: SalesQuery = {};
    if (!filters || typeof filters !== 'object') {
        return query;
    }

    for (const key of FILTER_KEYS[kind]) {
        const value = filters[key];

        if (value === undefined || value === null) continue;

        if (typeof value === 'number') {
            if (Number.isFinite(value)) query[key] = value;
            continue;
        }

        if (typeof value !== 'string') continue;

        const text = value.trim();
        if (text === '') continue;

        if (key === 'sort') {
            if (sortAllowed(kind, text)) query[key] = text;
            continue;
        }

        query[key] = key === 'from' || key === 'to' ? calendarDay(text) : text;
    }

    return query;
}

/**
 * True when the user has NARROWED the list — the rows on screen are a
 * subset the empty-state copy must not describe as "no orders at all".
 * Sort and paging change order and position, not membership.
 */
export function hasActiveFilters(kind: SalesDocumentKind, filters: SalesListFilters | null | undefined): boolean {
    const query = buildSalesQuery(kind, filters);
    for (const key of NON_NARROWING_KEYS) delete query[key];

    return Object.keys(query).length > 0;
}

/**
 * Filters → the page's search params, so the view lives in the URL (a
 * pasted link reopens the same list). Same allowlist and the same
 * empties-dropped rule as the wire query, minus one thing: page 1 is the
 * default and is not written.
 */
export function searchParamsFromFilters(kind: SalesDocumentKind, filters: SalesListFilters | null | undefined): URLSearchParams {
    const params = new URLSearchParams();
    const query = buildSalesQuery(kind, filters);

    for (const key of FILTER_KEYS[kind]) {
        const value = query[key];
        if (value === undefined) continue;
        if (key === 'page' && value === 1) continue;
        params.set(key, String(value));
    }

    return params;
}

/**
 * The page's search params → filters. Numbers must be positive integers
 * (`customer_id=abc` or `page=0` is not a filter, it is a typo, and it is
 * dropped rather than sent to a 422); per_page above the server's ceiling
 * is dropped for the same reason. Only THIS document's keys are read.
 */
export function filtersFromSearchParams(kind: SalesDocumentKind, params: URLSearchParams): SalesListFilters {
    const filters: SalesListFilters = {};

    for (const key of FILTER_KEYS[kind]) {
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

        if (key === 'sort' && !sortAllowed(kind, text)) continue;

        (filters as Record<string, string>)[key] = key === 'from' || key === 'to' ? calendarDay(text) : text;
    }

    return filters;
}

// -------------------------------------------------------- document numbers --

const PREFIX: Record<SalesDocumentKind, string> = {
    sales_order: 'SO',
    delivery: 'DN',
    invoice: 'INV',
};

const KIND_BY_PREFIX: Record<string, SalesDocumentKind> = {
    SO: 'sales_order',
    DN: 'delivery',
    INV: 'invoice',
};

const LIST_PATH: Record<SalesDocumentKind, string> = {
    sales_order: '/sales/sales-orders',
    delivery: '/sales/deliveries',
    invoice: '/sales/invoices',
};

/**
 * The document number as the server spells it — "SO-12", "DN-5", "INV-3".
 * These ARE the voucher_number the Delivery Note / Sales payloads carry, so
 * what a row says here is what the accountant searches for in Tally. Used
 * only where a response has not already said `document_number` for us.
 */
export function documentNumber(kind: SalesDocumentKind, id: number): string {
    return `${PREFIX[kind]}-${id}`;
}

/** The list page with this document's drawer open ("/sales/deliveries?open=DN-5"). */
export function documentPath(kind: SalesDocumentKind, id: number): string {
    return `${LIST_PATH[kind]}?open=${documentNumber(kind, id)}`;
}

/**
 * A document reference in any of the spellings the server's `q` accepts —
 * "SO-12", "so 12", "SO#12", "so12", "DN-5", "INV-3" — read back to its kind
 * and id.
 * A bare number is that page's own document only when the caller says which
 * page it is on; from nowhere it is nothing. Anything else is null: no
 * guessing at what "PO-4" or "SO-0" might have meant.
 */
export function parseDocumentRef(
    value: string | null | undefined,
    defaultKind?: SalesDocumentKind,
): { kind: SalesDocumentKind; id: number } | null {
    if (typeof value !== 'string') return null;
    const text = value.trim();
    if (text === '') return null;

    // Same grammar as the server's SalesDocumentQuery::documentId(): a
    // separator (space, dash, hash) is a separator only AFTER a prefix, so
    // "SO#12" is order 12 but a bare "-12" or "#12" is nothing.
    const match = /^(?:([A-Za-z]+)[\s\-#]*)?(\d+)$/.exec(text);
    if (!match) return null;

    const [, prefix, digits] = match;
    const id = Number(digits);
    if (!Number.isInteger(id) || id < 1) return null;

    if (prefix === undefined) {
        return defaultKind ? { kind: defaultKind, id } : null;
    }

    const kind = KIND_BY_PREFIX[prefix.toUpperCase()];

    return kind ? { kind, id } : null;
}

// ------------------------------------------------------------- bar options --

/** The sort dropdown's choices for one document, newest first as the first (default) choice. */
export function sortOptions(kind: SalesDocumentKind): { value: string; label: string }[] {
    return SORT_FIELDS[kind].flatMap((field) => [
        { value: `-${field}`, label: `${SORT_LABELS[field] ?? field} ↓` },
        { value: field, label: `${SORT_LABELS[field] ?? field} ↑` },
    ]);
}

/** The status dropdown's choices — empty for a delivery, which carries no status. */
export function statusOptions(kind: SalesDocumentKind): { value: string; label: string }[] {
    const statuses: readonly string[] = kind === 'sales_order' ? SALES_ORDER_STATUSES : kind === 'invoice' ? INVOICE_STATUSES : [];

    return statuses.map((status) => ({ value: status, label: status.replaceAll('_', ' ') }));
}
