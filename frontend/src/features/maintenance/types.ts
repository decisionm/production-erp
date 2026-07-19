import type { Item, Warehouse } from '@/features/inventory/types';

export type AssetStatus = 'active' | 'under_maintenance' | 'retired';

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
    unit_cost: string;
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
    parts_cost: string;
    total_cost: string;
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
