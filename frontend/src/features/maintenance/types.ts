import type { Item, Warehouse } from '@/features/inventory/types';
import type { ListParams } from '@/lib/listParams';

export type AssetStatus = 'active' | 'under_maintenance' | 'retired';

/** GET /maintenance/assets (ListAssetsRequest): sort / page / per_page. */
export interface AssetListFilters extends ListParams {
    /** id / code / name / category / location / status, bare or "-" prefixed; absent is the server's default (name). */
    sort?: string;
}

/** GET /maintenance/schedules (ListMaintenanceSchedulesRequest): asset_id plus sort / page / per_page. */
export interface MaintenanceScheduleListFilters extends ListParams {
    asset_id?: number;
    /** id / name / frequency_days / next_due_date, bare or "-" prefixed; absent is the server's default (next_due_date). */
    sort?: string;
}

/** GET /maintenance/work-orders (ListMaintenanceWorkOrdersRequest): asset_id plus sort / page / per_page. */
export interface MaintenanceWorkOrderListFilters extends ListParams {
    asset_id?: number;
    /**
     * id / type / status / reported_date / labor_cost, bare or "-" prefixed;
     * parts_cost / total_cost only for finance eyes (FC-06). Absent is the
     * server's default (-id).
     */
    sort?: string;
}

export interface Asset {
    id: number;
    code: string;
    name: string;
    category: string | null;
    location: string | null;
    purchase_date: string | null;
    purchase_cost: string | null;
    status: AssetStatus;
    created_at: string;
}

export interface MaintenanceSchedule {
    id: number;
    asset: Asset;
    name: string;
    frequency_days: number;
    next_due_date: string;
    is_active: boolean;
    created_at: string;
}

export type MaintenanceWorkOrderType = 'preventive' | 'corrective';
export type MaintenanceWorkOrderStatus = 'open' | 'in_progress' | 'completed' | 'cancelled';

export interface MaintenanceWorkOrderPart {
    id: number;
    item: Item;
    warehouse: Warehouse;
    quantity: string;
    /**
     * The purchase rate the part was drawn from stock at — served only to
     * finance.view/manage eyes (FC-06); the key is ABSENT, never null, for
     * everyone else. Presence is the server's ruling.
     */
    unit_cost?: string;
}

export interface MaintenanceWorkOrder {
    id: number;
    asset: Asset;
    maintenance_schedule_id: number | null;
    type: MaintenanceWorkOrderType;
    status: MaintenanceWorkOrderStatus;
    description: string | null;
    reported_date: string;
    started_at: string | null;
    completed_at: string | null;
    assignee?: { id: number; name: string };
    labor_cost: string;
    // Absent (not null) unless the caller holds finance.view/manage —
    // parts_cost / quantity would hand a maintenance login the purchase
    // rate (FC-06). labor_cost is always present.
    parts_cost?: string;
    total_cost?: string;
    parts: MaintenanceWorkOrderPart[];
    created_at: string;
}

export interface ReliabilityReport {
    asset_id: number;
    completed_work_orders: number;
    breakdown_count: number;
    mttr_hours: number | null;
    mtbf_hours: number | null;
}
