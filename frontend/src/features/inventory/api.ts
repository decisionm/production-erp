import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
// Production's own types for the paper the store raises and for the physical
// lots and bags: the send-to-production endpoint answers with a production
// request, and MaterialLot/MaterialBag are already declared there and shared by
// the floor's scan screens. This module states those shapes rather than shaping
// second, thinner copies of them.
import type { MaterialBag, MaterialLot, ProductionRequest } from '@/features/production/types';
import { plainDecimal } from './fulfilment';
import type {
    Batch,
    BatchLedger,
    FulfilmentPlanningResult,
    FulfilmentQueueFilters,
    FulfilmentQueueRow,
    IdentityItem,
    Item,
    ItemCategoryValue,
    ItemIdentityFilters,
    ItemIdentityHealth,
    ItemTrackingType,
    SerialNumber,
    StockBalance,
    StockMovement,
    StockReservation,
    Warehouse,
} from './types';

/**
 * What every inventory list accepts. `search` is answered by the SERVER, so a
 * needle past the current page still finds its row — the whole point of these
 * being here rather than a `.filter()` over `data`.
 *
 * `code` is a different question, not a stricter `search`: it matches the
 * WHOLE batch number or serial number. A substring search is still served a
 * page at a time, so a scanned `LOT-4` behind sixty newer numbers that merely
 * contain it is not on the page that comes back. Only the batch and serial
 * lists answer it — see findBatchesByCode / findSerialNumbersByCode.
 */
export interface ListParams {
    page?: number;
    per_page?: number;
    search?: string;
    code?: string;
}

/**
 * The page size a picker asks for when it needs the COMPLETE set rather than a
 * screenful. The server clamps at 1,000 (App\Http\Controllers\Controller::perPage),
 * so this is the real ceiling and not a wish — a bounded read, never "all rows".
 */
export const FULL_LIST_PER_PAGE = 1000;

export async function listItems(): Promise<Paginated<Item>> {
    const { data } = await api.get<Paginated<Item>>('/inventory/items');
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
export async function listAllItems(): Promise<Paginated<Item>> {
    const { data } = await api.get<Paginated<Item>>('/inventory/items', { params: { per_page: 1000 } });
    return data;
}

/**
 * One item by id — what the item detail page needs. Never resolve an item out
 * of listItems(): that returns the first page only, so with 600+ items in the
 * master most items simply aren't in it.
 */
export async function getItem(id: number): Promise<Item> {
    const { data } = await api.get<{ data: Item }>(`/inventory/items/${id}`);
    return data.data;
}

export interface CreateItemPayload {
    sku: string;
    name: string;
    description?: string;
    uom: string;
    hsn_sac_code?: string;
    reorder_level?: number;
    nominal_weight_grams?: number;
    // Product standards — accepted as 'sometimes','nullable' on store/update.
    colour?: string | null;
    standard_cycle_time?: number | null;
    standard_cavities?: number | null;
    tracking_type?: ItemTrackingType;
    // Identity (Phase 2). `name` stays the Tally wire key and is not one of
    // these — display_name is the ERP-facing label beside it, and the variant
    // link is the DEC-20260821-001 relation between separate pack masters.
    display_name?: string | null;
    variant_of_item_id?: number | null;
    variant_label?: string | null;
    /**
     * The owner's classification. Sent only when the screen actually had one
     * to send — `undefined` is dropped by JSON.stringify, which is how a save
     * from a screen that never saw a category cannot blank one.
     */
    category?: ItemCategoryValue | null;
}

export async function createItem(payload: CreateItemPayload): Promise<Item> {
    const { data } = await api.post<{ data: Item }>('/inventory/items', payload);
    return data.data;
}

export type UpdateItemPayload = Partial<CreateItemPayload> & { is_active?: boolean };

export async function updateItem(id: number, payload: UpdateItemPayload): Promise<Item> {
    const { data } = await api.put<{ data: Item }>(`/inventory/items/${id}`, payload);
    return data.data;
}

// ------------------------------------------------------- item identity ----

/**
 * WHAT IS WRONG WITH THE ITEM MASTER, counted per warning class.
 *
 * A READ. It classifies nothing, merges nothing and writes nothing — Q43 and
 * Q59 are open, and even the mapping the owner has settled
 * (DEC-20260827-001) is applied elsewhere, so this endpoint's whole job is to
 * say where a person should look. Every class comes back, zeros included.
 */
export async function getIdentityHealth(): Promise<ItemIdentityHealth> {
    const { data } = await api.get<{ data: ItemIdentityHealth }>('/inventory/identity/health');
    return data.data;
}

/**
 * One page of items carrying a warning, or — with no class named — every item
 * tripping any of them, which is what the review opens on.
 *
 * Server-side, and server-paged: the whole point of the badge filter is that
 * it reaches rows nowhere near the page a browser-side filter can see. A class
 * the server does not know is a 422 rather than an empty table, so the filter
 * value must be one of the stable keys or absent — never a sentinel.
 */
export async function listIdentityItems(
    filters: ItemIdentityFilters = {},
): Promise<Paginated<IdentityItem>> {
    const { data } = await api.get<Paginated<IdentityItem>>('/inventory/identity/items', {
        params: { warning: filters.warning, page: filters.page, per_page: filters.per_page },
    });
    return data;
}

export async function listWarehouses(params?: ListParams): Promise<Paginated<Warehouse>> {
    const { data } = await api.get<Paginated<Warehouse>>('/inventory/warehouses', { params });
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
/** The warehouses index's page — Paginated plus the WIP identity the receiving form reads. */
export type WarehousesPage = Paginated<Warehouse> & {
    meta: Paginated<Warehouse>['meta'] & {
        /**
         * Which row is Production/WIP (DEC-20260817-001), resolved by the
         * server — null/absent when nothing resolves. The goods-receipt form
         * keeps this row out of its warehouse picker; the server refuses it
         * anyway (a purchase has not been "issued to production").
         */
        production_wip_warehouse_id?: number | null;
    };
};

export async function listAllWarehouses(): Promise<WarehousesPage> {
    const { data } = await api.get<WarehousesPage>('/inventory/warehouses', {
        params: { per_page: FULL_LIST_PER_PAGE },
    });
    return data;
}

export interface CreateWarehousePayload {
    code: string;
    name: string;
}

export async function createWarehouse(payload: CreateWarehousePayload): Promise<Warehouse> {
    const { data } = await api.post<{ data: Warehouse }>('/inventory/warehouses', payload);
    return data.data;
}

export type UpdateWarehousePayload = Partial<CreateWarehousePayload> & { is_active?: boolean };

export async function updateWarehouse(id: number, payload: UpdateWarehousePayload): Promise<Warehouse> {
    const { data } = await api.put<{ data: Warehouse }>(`/inventory/warehouses/${id}`, payload);
    return data.data;
}

/**
 * `sort`/`direction` are SERVER-side on purpose: this list is paginated, so a
 * column sorter in the table would order the rows already on screen and show
 * it as the order of the factory's stock.
 */
export async function listStockBalances(
    params?: ListParams & {
        item_id?: number;
        warehouse_id?: number;
        sort?: 'item' | 'warehouse' | 'quantity';
        direction?: 'asc' | 'desc';
    },
): Promise<Paginated<StockBalance>> {
    const { data } = await api.get<Paginated<StockBalance>>('/inventory/stock-balances', { params });
    return data;
}

/**
 * Every balance for ONE item, asked for by id — what an item's own page shows.
 * Never filter the general list client-side for this: that list is paged, so
 * past twenty balances the item's own stock simply is not in the response.
 */
export async function listItemStockBalances(itemId: number): Promise<Paginated<StockBalance>> {
    return listStockBalances({ item_id: itemId, per_page: FULL_LIST_PER_PAGE });
}

/**
 * THE STOCK LEDGER, a page at a time.
 *
 * `page` is here for the first-class Stock Movements screen: the drawer and
 * the item detail tab ask for one big slice of ONE item and never turn a page,
 * but a ledger over the whole factory has to. Everything this endpoint filters
 * on is in this signature — `StockMovementController::index` reads item_id,
 * warehouse_id and the page size and nothing else, so a caller wanting a type,
 * a purpose or a date range has to widen the endpoint first, not the query.
 */
export async function listStockMovements(params?: {
    item_id?: number;
    warehouse_id?: number;
    per_page?: number;
    page?: number;
}): Promise<Paginated<StockMovement>> {
    const { data } = await api.get<Paginated<StockMovement>>('/inventory/stock-movements', { params });
    return data;
}

export interface ReceiptPayload {
    item_id: number;
    warehouse_id: number;
    quantity: number;
    unit_cost: number;
    reference?: string;
    notes?: string;
    batch_id?: number;
    serial_number_id?: number;
}

export async function recordReceipt(payload: ReceiptPayload): Promise<StockMovement> {
    const { data } = await api.post<{ data: StockMovement }>('/inventory/stock-movements/receipts', payload);
    return data.data;
}

export interface IssuePayload {
    item_id: number;
    warehouse_id: number;
    quantity: number;
    reference?: string;
    notes?: string;
    batch_id?: number;
    serial_number_id?: number;
}

export async function recordIssue(payload: IssuePayload): Promise<StockMovement> {
    const { data } = await api.post<{ data: StockMovement }>('/inventory/stock-movements/issues', payload);
    return data.data;
}

export interface TransferPayload {
    item_id: number;
    from_warehouse_id: number;
    to_warehouse_id: number;
    quantity: number;
    reference?: string;
    notes?: string;
    batch_id?: number;
    serial_number_id?: number;
}

export async function recordTransfer(payload: TransferPayload): Promise<StockMovement[]> {
    const { data } = await api.post<{ data: StockMovement[] }>('/inventory/stock-movements/transfers', payload);
    return data.data;
}

export async function listBatches(params?: ListParams & { item_id?: number }): Promise<Paginated<Batch>> {
    const { data } = await api.get<Paginated<Batch>>('/inventory/batches', { params });
    return data;
}

/**
 * Every batch of ONE item — what the Stock page's Batch picker offers.
 *
 * The picker used to read the default first page of the WHOLE batch list and
 * then filter it by item in the browser, so a batch that was not among the
 * newest twenty could not be selected at all, and nothing said a row was
 * missing. Scoped and bounded: complete for the item, never "every batch".
 */
export async function listAllBatches(itemId: number): Promise<Paginated<Batch>> {
    return listBatches({ item_id: itemId, per_page: FULL_LIST_PER_PAGE });
}

/**
 * ONE SCANNED BATCH NUMBER, RESOLVED BY THE SERVER.
 *
 * The scanner used to ask `?search=<code>&per_page=50` and pick the exact
 * match out of the reply, which quietly made the answer depend on how many
 * OTHER numbers contain the scanned one. Sixty of them and the real row is on
 * page two: "no batch matches", for a barcode this system printed.
 *
 * `code` matches the whole value, so a handful of rows is the most this can
 * return; the page ceiling is passed anyway so no cap is left to reason about.
 */
export async function findBatchesByCode(code: string): Promise<Paginated<Batch>> {
    return listBatches({ code, per_page: FULL_LIST_PER_PAGE });
}

export interface CreateBatchPayload {
    item_id: number;
    batch_number: string;
    manufactured_date?: string;
    expiry_date?: string;
    notes?: string;
}

export async function createBatch(payload: CreateBatchPayload): Promise<Batch> {
    const { data } = await api.post<{ data: Batch }>('/inventory/batches', payload);
    return data.data;
}

export async function getBatchLedger(batchId: number): Promise<BatchLedger> {
    const { data } = await api.get<{ data: BatchLedger }>(`/inventory/batches/${batchId}/ledger`);
    return data.data;
}

export async function listSerialNumbers(
    params?: ListParams & { item_id?: number },
): Promise<Paginated<SerialNumber>> {
    const { data } = await api.get<Paginated<SerialNumber>>('/inventory/serial-numbers', { params });
    return data;
}

/** Every serial number of ONE item — the Stock page's Serial picker, same rule as above. */
export async function listAllSerialNumbers(itemId: number): Promise<Paginated<SerialNumber>> {
    return listSerialNumbers({ item_id: itemId, per_page: FULL_LIST_PER_PAGE });
}

/** One scanned serial number, resolved by the server — same rule as above. */
export async function findSerialNumbersByCode(code: string): Promise<Paginated<SerialNumber>> {
    return listSerialNumbers({ code, per_page: FULL_LIST_PER_PAGE });
}

export interface CreateSerialNumberPayload {
    item_id: number;
    serial_number: string;
}

export async function createSerialNumber(payload: CreateSerialNumberPayload): Promise<SerialNumber> {
    const { data } = await api.post<{ data: SerialNumber }>('/inventory/serial-numbers', payload);
    return data.data;
}

export async function getSerialNumberHistory(id: number): Promise<SerialNumber> {
    const { data } = await api.get<{ data: SerialNumber }>(`/inventory/serial-numbers/${id}/history`);
    return data.data;
}

// -------------------------------------------------- lots, bags and labels --

/**
 * THE BAG REGISTER, a page at a time — what the Barcode & Labels bench lists.
 *
 * The endpoint is `/inventory/material-bags`, and it is Inventory's own
 * surface, which is why the reader for it lives here — and, since 27-Aug,
 * ONLY here. `features/production/api.ts` carried a second declaration of this
 * name against the same endpoint, unpaged and with no caller; it is gone, and
 * the paging is why this is the survivor: the bag list is ordered OLDEST
 * FIRST, so without a page number the newest bags — the ones a label is
 * usually reprinted for — are unreachable. The Shift Floor's scan and
 * pick-list reads are genuinely different queries and keep their own functions.
 *
 * `status` is a plain string on purpose: the backend enum carries six cases and
 * the exported union in production/types.ts names four (see bagStatus.ts).
 *
 * The whole surface 404s while `production.traceability_enabled` is off — with
 * the flag down the feature does not exist, and the caller shows that rather
 * than an empty table.
 */
export async function listMaterialBags(params?: {
    item_id?: number;
    status?: string;
    page?: number;
}): Promise<Paginated<MaterialBag>> {
    const { data } = await api.get<Paginated<MaterialBag>>('/inventory/material-bags', { params });
    return data;
}

/**
 * ONE SUPPLIER LOT, with its bags — what a reprint needs.
 *
 * A bag row cannot print its own label from itself. MaterialBagLabels numbers a
 * label "Bag N of M" from the bag's position in its LOT, so handing it a lot
 * carrying a single bag would print "Bag 1 of M" onto physical labels — a
 * factory value invented by the screen. The lot is fetched whole and the bag is
 * named with `bagId`, which is how MaterialLotsPage keeps the true sequence.
 *
 * `MaterialLotController::show` loads `bags` (TraceabilityService::loadLot), so
 * one call is the whole answer.
 */
export async function getMaterialLot(id: number): Promise<MaterialLot> {
    const { data } = await api.get<{ data: MaterialLot }>(`/inventory/material-lots/${id}`);
    return data.data;
}

// ------------------------------------------------------- store fulfilment --

/**
 * THE STORE'S FULFILMENT QUEUE — order lines waiting on stock, and the four
 * things the store can do about one.
 *
 * NONE OF THESE MOVES STOCK (invariant 1). A hold changes who stock is spoken
 * for and nothing else; only a Delivery moves it, and when it does it SPENDS
 * the hold on the way past. Nothing here creates, starts or cancels a batch
 * (invariant 2) — sending a line to production writes a piece of paper.
 *
 * Every write posts its quantity through `plainDecimal`: `App\Rules\
 * PlainDecimal` refuses exponential notation, and JavaScript reaches for it
 * unprompted on small numbers.
 */

/**
 * One page of the queue.
 *
 * `state` NARROWS and its absence is not "everything" — with no state the
 * server hides `fully_allocated` lines (S16), and naming the state is how they
 * are asked for. Over-reserved rows come first, across the whole queue rather
 * than the page (S8), so this list arrives ORDERED and must not be re-sorted.
 */
export async function listFulfilmentQueue(
    filters: FulfilmentQueueFilters = {},
): Promise<Paginated<FulfilmentQueueRow>> {
    const { data } = await api.get<Paginated<FulfilmentQueueRow>>('/inventory/fulfilment/queue', {
        params: {
            state: filters.state,
            page: filters.page,
            per_page: filters.per_page,
        },
    });
    return data;
}

/**
 * WHEN THE FACTORY COULD HAVE IT — every open request with its ETA or its
 * refusal, the basis behind those numbers, and today's targets.
 *
 * A bare object, not a paginated collection: the payload is three things at
 * once and the server shapes it whole. No ETA is stored anywhere (S11).
 */
export async function getFulfilmentPlanning(): Promise<FulfilmentPlanningResult> {
    const { data } = await api.get<FulfilmentPlanningResult>('/inventory/fulfilment/planning');
    return data;
}

/** HOLD free finished goods for this line. Moves no stock; returns the new hold. */
export async function reserveForLine(lineId: number, quantity: string | number): Promise<StockReservation> {
    const { data } = await api.post<{ data: StockReservation }>(
        `/inventory/fulfilment/lines/${lineId}/reserve`,
        { quantity: plainDecimal(quantity) },
    );
    return data.data;
}

/**
 * ASK THE FLOOR for what the store cannot cover. The server caps the quantity
 * at the line's real shortfall recomputed under a lock (S14) rather than
 * refusing a round number, so what comes back may be less than what was sent.
 */
export async function sendLineToProduction(lineId: number, quantity: string | number): Promise<ProductionRequest> {
    const { data } = await api.post<{ data: ProductionRequest }>(
        `/inventory/fulfilment/lines/${lineId}/send-to-production`,
        { quantity: plainDecimal(quantity) },
    );
    return data.data;
}

/**
 * Give a hold up — the stock stays exactly where it is and stops being spoken
 * for. A reason is required and is kept on the row: a hold is never deleted
 * and never edited, only given up.
 */
export async function releaseReservation(reservationId: number, reason: string): Promise<StockReservation> {
    const { data } = await api.post<{ data: StockReservation }>(
        `/inventory/reservations/${reservationId}/release`,
        { reason },
    );
    return data.data;
}

/**
 * Move a hold (or part of it) to another line — release + reserve in ONE
 * transaction under ONE balance lock (S4). The server refuses a target of a
 * different product, the hold's own line, and more than the hold outstanding.
 *
 * Returns the NEW hold on the target line, not the remainder of the old one.
 */
export async function repointReservation(
    reservationId: number,
    payload: { sales_order_line_id: number; quantity: string | number; reason: string },
): Promise<StockReservation> {
    const { data } = await api.post<{ data: StockReservation }>(
        `/inventory/reservations/${reservationId}/repoint`,
        {
            sales_order_line_id: payload.sales_order_line_id,
            quantity: plainDecimal(payload.quantity),
            reason: payload.reason,
        },
    );
    return data.data;
}
