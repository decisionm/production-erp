import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { Item, StockBalance, StockMovement, Warehouse } from './types';

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
}

export async function createItem(payload: CreateItemPayload): Promise<Item> {
    const { data } = await api.post<{ data: Item }>('/inventory/items', payload);
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

export async function listStockBalances(): Promise<Paginated<StockBalance>> {
    const { data } = await api.get<Paginated<StockBalance>>('/inventory/stock-balances');
    return data;
}

export async function listStockMovements(): Promise<Paginated<StockMovement>> {
    const { data } = await api.get<Paginated<StockMovement>>('/inventory/stock-movements');
    return data;
}

export interface ReceiptPayload {
    item_id: number;
    warehouse_id: number;
    quantity: number;
    unit_cost: number;
    reference?: string;
    notes?: string;
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
}

export async function recordTransfer(payload: TransferPayload): Promise<StockMovement[]> {
    const { data } = await api.post<{ data: StockMovement[] }>('/inventory/stock-movements/transfers', payload);
    return data.data;
}
