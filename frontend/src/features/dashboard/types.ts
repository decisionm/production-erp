export interface RecentWorkOrder {
    id: number;
    item: string;
    status: string;
    quantity_planned: string;
    quantity_completed: string;
}

export interface RecentSalesOrder {
    id: number;
    customer: string;
    status: string;
    order_date: string | null;
}

/** One sent/partially-received PO — stock on its way in. */
export interface IncomingStockRow {
    id: number;
    vendor: string;
    expected_date: string | null;
    status: string;
    items: string;
}

/**
 * One undelivered open sales-order line against the shelf. `hours_at_standard`
 * is an estimate at the product's standard rate and exists only when the
 * product carries exactly one usable (cycle time, cavities) pair — 'none'
 * and 'ambiguous' standards deliberately get no figure.
 */
export interface DemandRow {
    sales_order_id: number;
    customer: string;
    expected_date: string | null;
    item: string;
    ordered: string;
    delivered: string;
    remaining: string;
    on_hand: string;
    to_produce: string;
    standard: 'ok' | 'none' | 'ambiguous';
    hours_at_standard: number | null;
}

/**
 * Every block is optional: the server includes only the blocks the user's
 * role may see (DashboardService gates on `<module>.view`/`.manage`), so a
 * missing block means "not yours to see", not "zero".
 */
export interface DashboardSummary {
    inventory?: {
        total_items: number;
        total_warehouses: number;
        low_stock_items: number;
    };
    procurement?: {
        open_purchase_orders: number;
        pending_requisitions: number;
    };
    production?: {
        open_work_orders: number;
    };
    sales?: {
        open_sales_orders: number;
        /**
         * Open orders whose expected date the factory's calendar (IST) has
         * already passed. A WIDER set than open_sales_orders — drafts count
         * here — so it can read higher than the figure beside it.
         */
        overdue_sales_orders: number;
        orders_awaiting_delivery: number;
        receivables_outstanding: string;
    };
    quality?: {
        open_ncrs: number;
        open_capas: number;
    };
    hrms?: {
        pending_leave_requests: number;
    };
    crm?: {
        open_leads: number;
        open_opportunities: number;
    };
    maintenance?: {
        open_work_orders: number;
    };
    incoming_stock?: IncomingStockRow[];
    demand?: DemandRow[];
    recent_work_orders?: RecentWorkOrder[];
    recent_sales_orders?: RecentSalesOrder[];
}
