import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    Customer,
    Delivery,
    Invoice,
    SalesOrder,
} from './types';

export async function listCustomers(): Promise<Paginated<Customer>> {
    const { data } = await api.get<Paginated<Customer>>('/sales/customers');
    return data;
}

export interface CreateCustomerPayload {
    code: string;
    name: string;
    email?: string;
    phone?: string;
    address?: string;
}

export async function createCustomer(payload: CreateCustomerPayload): Promise<Customer> {
    const { data } = await api.post<{ data: Customer }>('/sales/customers', payload);
    return data.data;
}

export async function listSalesOrders(): Promise<Paginated<SalesOrder>> {
    const { data } = await api.get<Paginated<SalesOrder>>('/sales/sales-orders');
    return data;
}

export interface CreateSalesOrderPayload {
    customer_id: number;
    order_date: string;
    expected_date?: string;
    notes?: string;
    lines: { item_id: number; quantity: number; unit_price: number }[];
}

export async function createSalesOrder(payload: CreateSalesOrderPayload): Promise<SalesOrder> {
    const { data } = await api.post<{ data: SalesOrder }>('/sales/sales-orders', payload);
    return data.data;
}

export async function confirmSalesOrder(id: number): Promise<SalesOrder> {
    const { data } = await api.post<{ data: SalesOrder }>(`/sales/sales-orders/${id}/confirm`);
    return data.data;
}

export async function listDeliveries(): Promise<Paginated<Delivery>> {
    const { data } = await api.get<Paginated<Delivery>>('/sales/deliveries');
    return data;
}

export interface CreateDeliveryPayload {
    sales_order_id: number;
    warehouse_id: number;
    reference?: string;
    notes?: string;
    lines: { sales_order_line_id: number; quantity: number }[];
}

export async function createDelivery(payload: CreateDeliveryPayload): Promise<Delivery> {
    const { data } = await api.post<{ data: Delivery }>('/sales/deliveries', payload);
    return data.data;
}

export async function listInvoices(): Promise<Paginated<Invoice>> {
    const { data } = await api.get<Paginated<Invoice>>('/sales/invoices');
    return data;
}

export interface CreateInvoicePayload {
    sales_order_id: number;
    invoice_date: string;
    due_date?: string;
    notes?: string;
    lines: { sales_order_line_id: number; quantity: number; unit_price: number }[];
}

export async function createInvoice(payload: CreateInvoicePayload): Promise<Invoice> {
    const { data } = await api.post<{ data: Invoice }>('/sales/invoices', payload);
    return data.data;
}

export async function issueInvoice(id: number): Promise<Invoice> {
    const { data } = await api.post<{ data: Invoice }>(`/sales/invoices/${id}/issue`);
    return data.data;
}
