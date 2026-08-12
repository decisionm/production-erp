import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    GoodsReceiptNote,
    PurchaseOrder,
    PurchaseRequisition,
    Vendor,
} from './types';

export async function listVendors(): Promise<Paginated<Vendor>> {
    const { data } = await api.get<Paginated<Vendor>>('/procurement/vendors');
    return data;
}

/**
 * Full reference list for a picker (all rows, not the default first page).
 *
 * A picker fed from the paged list silently offers only the newest 20 — the
 * vendor a buyer needs is simply absent, with nothing on screen saying so.
 * That is how the resin PO could not be raised on 12-Aug: the ITEM picker had
 * the same defect, and every picker in the app shared it.
 */
export async function listAllVendors(): Promise<Paginated<Vendor>> {
    const { data } = await api.get<Paginated<Vendor>>('/procurement/vendors', { params: { per_page: 1000 } });
    return data;
}

export interface CreateVendorPayload {
    code: string;
    name: string;
    email?: string;
    phone?: string;
    address?: string;
    gstin?: string;
    state_code?: string;
}

export async function createVendor(payload: CreateVendorPayload): Promise<Vendor> {
    const { data } = await api.post<{ data: Vendor }>('/procurement/vendors', payload);
    return data.data;
}

export type UpdateVendorPayload = Partial<CreateVendorPayload> & { is_active?: boolean };

export async function updateVendor(id: number, payload: UpdateVendorPayload): Promise<Vendor> {
    const { data } = await api.put<{ data: Vendor }>(`/procurement/vendors/${id}`, payload);
    return data.data;
}

export async function listPurchaseRequisitions(): Promise<Paginated<PurchaseRequisition>> {
    const { data } = await api.get<Paginated<PurchaseRequisition>>('/procurement/purchase-requisitions');
    return data;
}

export interface CreatePurchaseRequisitionPayload {
    needed_by_date?: string;
    notes?: string;
    lines: { item_id: number; quantity: number; notes?: string }[];
}

export async function createPurchaseRequisition(
    payload: CreatePurchaseRequisitionPayload,
): Promise<PurchaseRequisition> {
    const { data } = await api.post<{ data: PurchaseRequisition }>('/procurement/purchase-requisitions', payload);
    return data.data;
}

export async function approvePurchaseRequisition(id: number): Promise<PurchaseRequisition> {
    const { data } = await api.post<{ data: PurchaseRequisition }>(
        `/procurement/purchase-requisitions/${id}/approve`,
    );
    return data.data;
}

export async function rejectPurchaseRequisition(id: number): Promise<PurchaseRequisition> {
    const { data } = await api.post<{ data: PurchaseRequisition }>(
        `/procurement/purchase-requisitions/${id}/reject`,
    );
    return data.data;
}

/**
 * `per_page` exists so a page opened on a link to one specific older order
 * (`?po=7`) can load past the default first page of 20 and actually find it.
 */
export async function listPurchaseOrders(params?: { per_page?: number }): Promise<Paginated<PurchaseOrder>> {
    const { data } = await api.get<Paginated<PurchaseOrder>>('/procurement/purchase-orders', { params });
    return data;
}

export interface CreatePurchaseOrderPayload {
    vendor_id: number;
    purchase_requisition_id?: number;
    order_date: string;
    expected_date?: string;
    notes?: string;
    /** 'tally' records a read-only mirror of the order living in Tally. */
    source?: 'erp' | 'tally';
    tally_order_no?: string;
    lines: {
        item_id: number;
        quantity: number;
        unit_price: number;
        schedules?: { due_date: string; quantity: number; tally_reference?: string }[];
    }[];
}

export async function createPurchaseOrder(payload: CreatePurchaseOrderPayload): Promise<PurchaseOrder> {
    const { data } = await api.post<{ data: PurchaseOrder }>('/procurement/purchase-orders', payload);
    return data.data;
}

export async function sendPurchaseOrder(id: number): Promise<PurchaseOrder> {
    const { data } = await api.post<{ data: PurchaseOrder }>(`/procurement/purchase-orders/${id}/send`);
    return data.data;
}

/** Same reason as listPurchaseOrders: links point at one specific receipt. */
export async function listGoodsReceipts(params?: { per_page?: number }): Promise<Paginated<GoodsReceiptNote>> {
    const { data } = await api.get<Paginated<GoodsReceiptNote>>('/procurement/goods-receipts', { params });
    return data;
}

/**
 * One idempotent, atomic GRN payload. When a mass-material line carries
 * lots, Procurement records the aggregate receipt and Inventory fans out
 * its physical bags in the same transaction.
 */
export interface CreateGoodsReceiptPayload {
    /** Stable across retries: replay returns the original receipt without moving stock twice. */
    receipt_key: string;
    purchase_order_id: number;
    warehouse_id: number;
    reference?: string;
    /**
     * The real date AND time the material was received, as plain
     * `YYYY-MM-DD HH:mm` wall-clock text. Without it the backend stamps the
     * receipt (and its stock movement) with the moment the form was submitted.
     */
    received_date: string;
    notes?: string;
    /** Recorded at physical arrival; server defaults both when blank. */
    receipt_note_reference?: string;
    tracking_number?: string;
    lines: {
        purchase_order_line_id: number;
        quantity: number;
        unit_cost?: number;
        /** Edited oldest-due preview; omitted = server allocates oldest-due itself. */
        schedule_allocations?: { purchase_order_schedule_id: number; quantity: number }[];
        lots?: {
            supplier_lot_no?: string;
            bag_count: number;
            bag_weight_kg?: number;
            total_received_kg: number;
            barcodes?: string[];
            bag_weights?: number[];
            notes?: string;
        }[];
    }[];
}

export async function createGoodsReceipt(payload: CreateGoodsReceiptPayload): Promise<GoodsReceiptNote> {
    const { data } = await api.post<{ data: GoodsReceiptNote }>('/procurement/goods-receipts', payload);
    return data.data;
}
