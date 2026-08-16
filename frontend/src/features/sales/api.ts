import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import { unwrapMirrorResponse, unwrapShowResponse } from './drawer';
import { buildSalesQuery } from './filters';
import type {
    Customer,
    Delivery,
    Invoice,
    SalesCostInsight,
    SalesListFilters,
    SalesOrder,
    TallyMirror,
} from './types';

/**
 * The server paginates this at 20 by default. The page used to send no page
 * param and render with pagination disabled, so it silently showed the first
 * 20 customers and nothing indicated there were more — harmless while the
 * list was a handful of demo rows, actively misleading once the ledger import
 * puts hundreds of real customers behind it.
 */
export async function listCustomers(page = 1, perPage = 50): Promise<Paginated<Customer>> {
    const { data } = await api.get<Paginated<Customer>>('/sales/customers', {
        params: { page, per_page: perPage },
    });
    return data;
}

export interface CreateCustomerPayload {
    code: string;
    name: string;
    email?: string;
    phone?: string;
    address?: string;
    gstin?: string;
    state_code?: string;
}

export async function createCustomer(payload: CreateCustomerPayload): Promise<Customer> {
    const { data } = await api.post<{ data: Customer }>('/sales/customers', payload);
    return data.data;
}

export type UpdateCustomerPayload = Partial<CreateCustomerPayload> & { is_active?: boolean };

export async function updateCustomer(id: number, payload: UpdateCustomerPayload): Promise<Customer> {
    const { data } = await api.put<{ data: Customer }>(`/sales/customers/${id}`, payload);
    return data.data;
}

/**
 * One page of orders, server-filtered (GET /sales/sales-orders).
 *
 * Declared as an overload pair rather than one signature with an optional
 * argument: the zero-argument form is what lets the Deliveries / Invoices
 * pages hand this straight to useQuery as their order-picker queryFn, where
 * TanStack calls it with its context object as the first argument. That
 * object is a weak-type mismatch for SalesListFilters at compile time and
 * would be garbage on the wire at run time — the overload keeps the
 * type-check honest, and buildSalesQuery()'s allowlist keeps the URL clean.
 */
export function listSalesOrders(): Promise<Paginated<SalesOrder>>;
export function listSalesOrders(filters: SalesListFilters): Promise<Paginated<SalesOrder>>;
export async function listSalesOrders(filters: SalesListFilters = {}): Promise<Paginated<SalesOrder>> {
    const { data } = await api.get<Paginated<SalesOrder>>('/sales/sales-orders', {
        params: buildSalesQuery('sales_order', filters),
    });
    return data;
}

/** One order with its trace — deliveries (with cartons) and invoices, each with its Tally state. */
export async function getSalesOrder(id: number): Promise<SalesOrder> {
    const { data } = await api.get<{ data: SalesOrder; trace?: SalesOrder['trace'] }>(`/sales/sales-orders/${id}`);
    return unwrapShowResponse(data) as SalesOrder;
}

/**
 * Cancel an order. The server allows it ONLY from draft or confirmed with
 * nothing delivered and no invoices (422 otherwise — the message is the
 * answer, show it). Touches no stock, fires no Tally event.
 */
export async function cancelSalesOrder(id: number): Promise<SalesOrder> {
    const { data } = await api.post<{ data: SalesOrder }>(`/sales/sales-orders/${id}/cancel`);
    return data.data;
}

/**
 * WHAT THESE PAGES ARE NOT (GET /sales/tally-mirror): real sales are
 * invoiced in Tally and nothing Tally-side is mirrored here. The panel
 * renders the server's sentences — never its own.
 */
export async function getTallyMirror(): Promise<TallyMirror> {
    const { data } = await api.get<TallyMirror | { data: TallyMirror }>('/sales/tally-mirror');
    return unwrapMirrorResponse(data);
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

/**
 * What one order costs, estimate and actual kept apart.
 *
 * READ-ONLY and deliberately NOT part of the orders list: costing a line reads
 * the common resin pool plus several moving-average lookups per distinct
 * product, so this is fetched for ONE order when its drawer opens rather than
 * N times to fill a column nobody has asked to sort by.
 */
export async function getSalesOrderCostInsight(id: number): Promise<SalesCostInsight> {
    const { data } = await api.get<{ data: SalesCostInsight }>(`/sales/sales-orders/${id}/cost-insight`);
    return data.data;
}

/** One page of deliveries, server-filtered (GET /sales/deliveries). Same overload pair as listSalesOrders, same reason. */
export function listDeliveries(): Promise<Paginated<Delivery>>;
export function listDeliveries(filters: SalesListFilters): Promise<Paginated<Delivery>>;
export async function listDeliveries(filters: SalesListFilters = {}): Promise<Paginated<Delivery>> {
    const { data } = await api.get<Paginated<Delivery>>('/sales/deliveries', {
        params: buildSalesQuery('delivery', filters),
    });
    return data;
}

/** One delivery with its trace — the order it fulfils, its cartons, its Delivery Note's Tally state. */
export async function getDelivery(id: number): Promise<Delivery> {
    const { data } = await api.get<{ data: Delivery; trace?: Delivery['trace'] }>(`/sales/deliveries/${id}`);
    return unwrapShowResponse(data) as Delivery;
}

export interface CreateDeliveryPayload {
    sales_order_id: number;
    warehouse_id: number;
    reference?: string;
    notes?: string;
    /** Typed quantities — the path without carton barcodes. */
    lines?: { sales_order_line_id: number; quantity: number }[];
    /**
     * Dispatch by scan: the carton barcodes that physically left. The server
     * derives the delivery lines from these boxes and refuses any carton that
     * is unknown, already dispatched, or off this order — send INSTEAD of
     * `lines`, never both.
     */
    carton_codes?: string[];
}

export async function createDelivery(payload: CreateDeliveryPayload): Promise<Delivery> {
    const { data } = await api.post<{ data: Delivery }>('/sales/deliveries', payload);
    return data.data;
}

/** One page of invoices, server-filtered (GET /sales/invoices). Same overload pair as listSalesOrders, same reason. */
export function listInvoices(): Promise<Paginated<Invoice>>;
export function listInvoices(filters: SalesListFilters): Promise<Paginated<Invoice>>;
export async function listInvoices(filters: SalesListFilters = {}): Promise<Paginated<Invoice>> {
    const { data } = await api.get<Paginated<Invoice>>('/sales/invoices', {
        params: buildSalesQuery('invoice', filters),
    });
    return data;
}

/** One invoice with its trace — the order it bills and its Sales voucher's Tally state. */
export async function getInvoice(id: number): Promise<Invoice> {
    const { data } = await api.get<{ data: Invoice; trace?: Invoice['trace'] }>(`/sales/invoices/${id}`);
    return unwrapShowResponse(data) as Invoice;
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
