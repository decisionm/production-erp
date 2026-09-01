/**
 * THE STOCK LEDGER'S READING RULES — the pure half of the Stock Movements
 * page, kept out of the component so it can be pinned by a test.
 *
 * Nothing here fetches, writes or derives a factory value. It turns the two
 * enums the server sends (`StockMovementType`, `StockMovementPurpose`) into
 * what a person reads, and it says which filters the ledger endpoint actually
 * honours.
 */

/**
 * WHAT `GET /inventory/stock-movements` ACTUALLY FILTERS ON.
 *
 * `StockMovementController::index` reads exactly three things — item_id,
 * warehouse_id and the page size — and `StockMovementService::paginateMovements`
 * applies exactly the first two. There is no type, purpose, date-range or
 * free-text filter on that endpoint, and the list is SERVER-PAGED, so a
 * control for one of those would filter the twenty rows that happen to be on
 * screen and quietly hide every matching row on every other page.
 *
 * That is why the page offers two filters and not six. When the endpoint grows
 * the missing ones, widen this type and the params builder below together —
 * the builder is the only thing that decides what leaves the browser.
 */
export interface StockLedgerFilters {
    itemId?: number | null;
    warehouseId?: number | null;
    page?: number;
}

/**
 * The query the ledger asks for. Empty selections are DROPPED rather than sent
 * as null/'' — `?item_id=` reaches Laravel as the string "", which `(int)`
 * turns into 0, and a falsy id is ignored by `when()` today but is not
 * something to depend on from here.
 */
export function stockLedgerParams(filters: StockLedgerFilters): Record<string, number> {
    const params: Record<string, number> = {};
    if (typeof filters.itemId === 'number') params.item_id = filters.itemId;
    if (typeof filters.warehouseId === 'number') params.warehouse_id = filters.warehouseId;
    if (typeof filters.page === 'number' && filters.page > 1) params.page = filters.page;

    return params;
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
const PURPOSE_TEXT: Record<string, string> = {
    opening: 'Opening balance',
    receipt: 'Receipt',
    issue_to_production: 'Issued to production',
    return_from_production: 'Returned from production',
    consumption: 'Consumption',
    output: 'Output',
    dispatch: 'Dispatch',
    adjustment: 'Adjustment',
    scrap: 'Scrap',
    reconcile: 'Reconcile',
    unknown: 'Not stated',
};

const PURPOSE_TONE: Record<string, string> = {
    opening: 'default',
    receipt: 'green',
    issue_to_production: 'geekblue',
    return_from_production: 'purple',
    consumption: 'volcano',
    output: 'cyan',
    dispatch: 'magenta',
    adjustment: 'gold',
    scrap: 'red',
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
