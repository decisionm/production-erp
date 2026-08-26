import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { Batch, BatchLedger, Item, ItemTrackingType, SerialNumber, StockBalance, StockMovement, Warehouse } from './types';

/**
 * What every inventory list accepts. `search` is answered by the SERVER, so a
 * needle past the current page still finds its row — the whole point of these
 * being here rather than a `.filter()` over `data`.
 */
export interface ListParams {
    page?: number;
    per_page?: number;
    search?: string;
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

export async function listWarehouses(params?: ListParams): Promise<Paginated<Warehouse>> {
    const { data } = await api.get<Paginated<Warehouse>>('/inventory/warehouses', { params });
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
export async function listAllWarehouses(): Promise<Paginated<Warehouse>> {
    const { data } = await api.get<Paginated<Warehouse>>('/inventory/warehouses', {
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

export async function listStockBalances(
    params?: ListParams & { item_id?: number },
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

export async function listStockMovements(params?: {
    item_id?: number;
    warehouse_id?: number;
    per_page?: number;
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
