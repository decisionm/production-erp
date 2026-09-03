/**
 * THE STOCK LEDGER'S READING RULES — the pure half of the Stock Movements
 * page, kept out of the component so it can be pinned by a test.
 *
 * Nothing here fetches, writes or derives a factory value. It turns the two
 * enums the server sends (`StockMovementType`, `StockMovementPurpose`) into
 * what a person reads, and it says which filters the ledger endpoint actually
 * honours.
 */
import type { ListParams, ListParamsSpec } from '@/lib/listParams';

/**
 * WHAT `GET /inventory/stock-movements` ACTUALLY FILTERS ON.
 *
 * `StockMovementController::index` reads item_id, warehouse_id, purpose, the
 * reference needle `q` (ListStockMovementsRequest) and the page size, and
 * `StockMovementService::paginateMovements` applies exactly those. There is
 * no type or date-range filter on that endpoint, and the list is
 * SERVER-PAGED, so a control for one of those would filter the twenty rows
 * that happen to be on screen and quietly hide every matching row on every
 * other page.
 *
 * That is why the page offers three controls and not six. When the endpoint
 * grows the missing ones, widen this type and the params builder below
 * together — the builder is the only thing that decides what leaves the
 * browser.
 */
export interface StockLedgerFilters {
    itemId?: number | null;
    warehouseId?: number | null;
    /** A substring of the movement's reference — "PO-4", "MR-12". */
    q?: string | null;
    /** One of STOCK_LEDGER_SORT_FIELDS, bare or "-" prefixed; absent is the default. */
    sort?: string | null;
    page?: number;
    perPage?: number;
}

/** Exactly what listStockMovements is asked for — and the query key. */
export interface StockLedgerRequest {
    item_id?: number;
    warehouse_id?: number;
    q?: string;
    sort?: string;
    page?: number;
    per_page?: number;
}

/**
 * The columns the SERVER orders the ledger by (ListStockMovementsRequest::
 * SORTABLE, 03-Sep-2026). Sorting happens there because the ledger is paged:
 * a column sorter here would order the twenty rows on screen and present
 * that as the order of the factory's history.
 */
export const STOCK_LEDGER_SORT_FIELDS: readonly string[] = ['movement_date', 'type', 'purpose', 'quantity'];

/** StockMovementService's order when no sort is asked for: newest movement first. */
export const STOCK_LEDGER_DEFAULT_SORT = '-movement_date';

/** The Stock Movements page's URL keys beyond q / page / per_page. */
export const STOCK_LEDGER_SPEC: ListParamsSpec = {
    numbers: ['item_id', 'warehouse_id'],
    strings: ['sort'],
    allowed: { sort: STOCK_LEDGER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type StockLedgerListParams = ListParams & {
    item_id?: number;
    warehouse_id?: number;
    sort?: string;
};

/**
 * The query the ledger asks for. Empty selections are DROPPED rather than sent
 * as null/'' — `?item_id=` reaches Laravel as the string "", which `(int)`
 * turns into 0, and a falsy id is ignored by `when()` today but is not
 * something to depend on from here. The needle is trimmed, and an empty one
 * is not sent.
 */
export function stockLedgerParams(filters: StockLedgerFilters): StockLedgerRequest {
    const params: StockLedgerRequest = {};
    if (typeof filters.itemId === 'number') params.item_id = filters.itemId;
    if (typeof filters.warehouseId === 'number') params.warehouse_id = filters.warehouseId;
    const q = (filters.q ?? '').trim();
    if (q !== '') params.q = q;
    // The default order is the bare request: the server's own, and one
    // query key for "unsorted" and "sorted the default way".
    const sort = (filters.sort ?? '').trim();
    if (sort !== '' && sort !== STOCK_LEDGER_DEFAULT_SORT && (STOCK_LEDGER_SPEC.allowed?.sort ?? []).includes(sort)) {
        params.sort = sort;
    }
    if (typeof filters.page === 'number' && filters.page > 1) params.page = filters.page;
    if (typeof filters.perPage === 'number' && filters.perPage > 0) params.per_page = filters.perPage;

    return params;
}

/**
 * What an empty NARROWED ledger says. The term is repeated so the reader sees
 * what was looked for rather than concluding nothing ever moved.
 */
export function ledgerNoMatchLine(q: string | undefined, narrowedByPickers: boolean): string {
    const term = (q ?? '').trim();

    if (term !== '' && narrowedByPickers) return `No movements match “${term}” for this item and warehouse.`;
    if (term !== '') return `No movements match “${term}”.`;

    return 'No movements match these filters.';
}

/** Which way the quantity went — `StockMovementType`. */
const TYPE_TONE: Record<string, string> = {
    receipt: 'green',
    issue: 'red',
    transfer_in: 'blue',
    transfer_out: 'orange',
};

export function movementTypeTone(type: string): string {
    return TYPE_TONE[type] ?? 'default';
}

/**
 * WHY IT MOVED — `StockMovementPurpose`, rendered without flattening its two
 * different kinds of "we do not know".
 *
 * The enum's own docblock is explicit that `unknown` is a REAL value: it is
 * what every writer that predates the column records, and it means the writer
 * did not say. A `null` purpose is a different fact — the column exists but
 * the backfill has not reached that row. Rendering both as an em dash would
 * merge a stated answer with a missing one, so they get separate cells: the
 * stated-but-empty answer is named, the absent one is a dash.
 *
 * `null` is returned for the absent case so the caller renders a plain dash
 * rather than a tag advertising nothing.
 */
export const PURPOSE_TEXT: Record<string, string> = {
    opening: 'Opening balance',
    receipt: 'Receipt',
    issue_to_production: 'Issued to production',
    return_from_production: 'Returned from production',
    consumption: 'Consumption',
    output: 'Output',
    dispatch: 'Dispatch',
    adjustment: 'Adjustment',
    scrap: 'Scrap',
    quality_release: 'Released by Quality',
    reconcile: 'Reconcile',
    unknown: 'Not stated',
};

export const PURPOSE_TONE: Record<string, string> = {
    opening: 'default',
    receipt: 'green',
    issue_to_production: 'geekblue',
    return_from_production: 'purple',
    consumption: 'volcano',
    output: 'cyan',
    dispatch: 'magenta',
    adjustment: 'gold',
    scrap: 'red',
    // GREEN like a receipt, not red like its sibling: material Quality cleared
    // is back on the shelf and issuable. The two outcomes of one inspection
    // must not read alike at a glance on the ledger.
    quality_release: 'green',
    reconcile: 'blue',
    unknown: 'default',
};

export interface PurposeLabel {
    text: string;
    tone: string;
}

export function movementPurposeLabel(purpose: string | null | undefined): PurposeLabel | null {
    if (purpose === null || purpose === undefined || purpose === '') return null;

    return {
        // A purpose this build has not heard of still reads as itself rather
        // than as a blank tag — the enum is added to, and an ERP served by a
        // newer backend must not lose the answer it was given.
        text: PURPOSE_TEXT[purpose] ?? purpose.replaceAll('_', ' '),
        tone: PURPOSE_TONE[purpose] ?? 'default',
    };
}

/**
 * The purchase order a goods-receipt reference names, if it names one.
 *
 * Every automatic movement carries a server-generated reference prefix from
 * whichever module caused it, and a GRN's says which order it was received
 * against ("GRN for PO #4"). That number is the only handle back to the
 * document, so the ledger links it.
 *
 * Stated once HERE, with a test, because the pattern has to track a string the
 * SERVER writes — a second copy is a second place to forget when the GRN
 * reference format changes. ItemDetailPage's `ReferenceCell` imports it.
 */
const PURCHASE_ORDER_REFERENCE = /\bPO #(\d+)/;

export function purchaseOrderIdIn(reference: string | null | undefined): string | null {
    if (!reference) return null;
    const match = reference.match(PURCHASE_ORDER_REFERENCE);

    return match ? match[1] : null;
}
