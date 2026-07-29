import type { Item, Warehouse } from '@/features/inventory/types';
import type { MaterialLot } from '@/features/production/types';

export interface Vendor {
    id: number;
    code: string;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    gstin: string | null;
    state_code: string | null;
    is_active: boolean;
    created_at: string;
}

export type PurchaseRequisitionStatus = 'draft' | 'approved' | 'rejected';

export interface PurchaseRequisitionLine {
    id: number;
    item: Item;
    quantity: string;
    notes: string | null;
}

export interface PurchaseRequisition {
    id: number;
    status: PurchaseRequisitionStatus;
    requested_by: string | null;
    needed_by_date: string | null;
    notes: string | null;
    lines: PurchaseRequisitionLine[];
    created_at: string;
}

export type PurchaseOrderStatus = 'draft' | 'sent' | 'partially_received' | 'closed' | 'cancelled';

export interface PurchaseOrderLine {
    id: number;
    item: Item;
    quantity: string;
    unit_price: string;
    quantity_received: string;
}

export interface PurchaseOrder {
    id: number;
    status: PurchaseOrderStatus;
    vendor: Vendor;
    purchase_requisition_id: number | null;
    order_date: string;
    expected_date: string | null;
    notes: string | null;
    lines: PurchaseOrderLine[];
    created_at: string;
}

export interface GoodsReceiptNoteLine {
    id: number;
    purchase_order_line_id: number;
    item: Item;
    quantity: string;
    unit_cost: string;
    material_lots?: MaterialLot[];
}

export interface GoodsReceiptNote {
    id: number;
    receipt_key?: string;
    purchase_order_id: number;
    warehouse: Warehouse;
    reference: string | null;
    received_date: string;
    notes: string | null;
    lines: GoodsReceiptNoteLine[];
    material_lots?: MaterialLot[];
    created_at: string;
}
