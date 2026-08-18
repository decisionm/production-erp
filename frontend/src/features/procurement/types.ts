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

/** One item/due-date delivery window mirrored from the Tally order. */
export interface PurchaseOrderSchedule {
    id: number;
    due_date: string;
    quantity: string;
    quantity_received: string;
    remaining: string;
    tally_reference: string | null;
}

export interface PurchaseOrderLine {
    id: number;
    item: Item;
    quantity: string;
    /**
     * Owner/Accounts only (FC-06): the server OMITS the key — never nulls it —
     * for anyone without finance access, so its presence on a row is the
     * server's own answer about whether this user may see the rate.
     */
    unit_price?: string;
    quantity_received: string;
    schedules?: PurchaseOrderSchedule[];
}

export interface PurchaseOrder {
    id: number;
    status: PurchaseOrderStatus;
    /** 'tally' = read-only mirror of the order living in Tally. */
    source: 'erp' | 'tally';
    tally_order_no: string | null;
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
    /** Same rule as PurchaseOrderLine.unit_price: absent (not null) without finance access. */
    unit_cost?: string;
    material_lots?: MaterialLot[];
}

export interface GoodsReceiptNote {
    id: number;
    receipt_key?: string;
    purchase_order_id: number;
    warehouse: Warehouse;
    reference: string | null;
    /** Recorded at physical arrival; defaults deterministically when blank. */
    receipt_note_reference?: string | null;
    tracking_number?: string | null;
    received_date: string;
    notes: string | null;
    lines: GoodsReceiptNoteLine[];
    material_lots?: MaterialLot[];
    created_at: string;
}
