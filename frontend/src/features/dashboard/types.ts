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

export interface DashboardSummary {
    inventory: {
        total_items: number;
        total_warehouses: number;
        low_stock_items: number;
    };
    procurement: {
        open_purchase_orders: number;
        pending_requisitions: number;
    };
    production: {
        open_work_orders: number;
    };
    sales: {
        open_sales_orders: number;
        orders_awaiting_delivery: number;
        receivables_outstanding: string;
    };
    quality: {
        open_ncrs: number;
        open_capas: number;
    };
    hrms: {
        pending_leave_requests: number;
    };
    crm: {
        open_leads: number;
        open_opportunities: number;
    };
    maintenance: {
        open_work_orders: number;
    };
    recent_work_orders: RecentWorkOrder[];
    recent_sales_orders: RecentSalesOrder[];
}
