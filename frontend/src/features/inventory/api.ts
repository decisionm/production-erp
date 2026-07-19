import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { Batch, BatchLedger, Item, ItemTrackingType, SerialNumber, StockBalance, StockMovement, Warehouse } from './types';

export async function listItems(): Promise<Paginated<Item>> {
    const { data } = await api.get<Paginated<Item>>('/inventory/items');
    return data;
}

export interface CreateItemPayload {
    sku: string;
    name: string;
    description?: string;
    uom: string;
    hsn_sac_code?: string;
    reorder_level?: number;
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

export async function listWarehouses(): Promise<Paginated<Warehouse>> {
    const { data } = await api.get<Paginated<Warehouse>>('/inventory/warehouses');
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

export async function listStockBalances(): Promise<Paginated<StockBalance>> {
    const { data } = await api.get<Paginated<StockBalance>>('/inventory/stock-balances');
    return data;
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

export async function listBatches(itemId?: number): Promise<Paginated<Batch>> {
    const { data } = await api.get<Paginated<Batch>>('/inventory/batches', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
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

export async function listSerialNumbers(itemId?: number): Promise<Paginated<SerialNumber>> {
    const { data } = await api.get<Paginated<SerialNumber>>('/inventory/serial-numbers', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
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
