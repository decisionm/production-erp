import type { Item, Warehouse } from '@/features/inventory/types';

export interface Customer {
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

export type SalesOrderStatus = 'draft' | 'confirmed' | 'partially_delivered' | 'completed' | 'cancelled';

export interface SalesOrderLine {
    id: number;
    item: Item;
    quantity: string;
    unit_price: string;
    quantity_delivered: string;
}

export interface SalesOrder {
    id: number;
    status: SalesOrderStatus;
    customer: Customer;
    order_date: string;
    expected_date: string | null;
    notes: string | null;
    lines: SalesOrderLine[];
    created_at: string;
}

export interface DeliveryLine {
    id: number;
    sales_order_line_id: number;
    item: Item;
    quantity: string;
}

export interface Delivery {
    id: number;
    sales_order_id: number;
    warehouse: Warehouse;
    reference: string | null;
    delivered_date: string;
    notes: string | null;
    lines: DeliveryLine[];
    created_at: string;
}

export type InvoiceStatus = 'draft' | 'issued' | 'paid';

export interface InvoiceLine {
    id: number;
    sales_order_line_id: number;
    item: Item;
    quantity: string;
    unit_price: string;
}

export interface Invoice {
    id: number;
    status: InvoiceStatus;
    sales_order_id: number;
    customer: Customer;
    invoice_date: string;
    due_date: string | null;
    notes: string | null;
    lines: InvoiceLine[];
    created_at: string;
}
