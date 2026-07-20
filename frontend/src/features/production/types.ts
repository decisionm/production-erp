import type { Employee } from '@/features/hrms/types';
import type { Item, Warehouse } from '@/features/inventory/types';
import type { Vendor } from '@/features/procurement/types';

export interface WorkCenter {
    id: number;
    code: string;
    name: string;
    capacity_hours_per_day: string | null;
    is_active: boolean;
    created_at: string;
}

export interface BomLine {
    id: number;
    component: Item;
    quantity_per: string;
}

export interface Bom {
    id: number;
    item: Item;
    name: string;
    version: string;
    is_active: boolean;
    lines: BomLine[];
    created_at: string;
}

export interface RoutingOperation {
    id: number;
    work_center: WorkCenter;
    sequence: number;
    name: string;
    standard_time_minutes: string | null;
}

export interface Routing {
    id: number;
    item: Item;
    name: string;
    is_active: boolean;
    operations: RoutingOperation[];
    created_at: string;
}

export type WorkOrderStatus = 'draft' | 'released' | 'completed';

export interface WorkOrderMaterial {
    id: number;
    component: Item;
    quantity_required: string;
    quantity_issued: string;
}

export interface ScrapReason {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    created_at: string;
}

export interface Shift {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    is_active: boolean;
}

export interface ShiftProductionEntry {
    id: number;
    shift: Shift;
    work_center: WorkCenter;
    item: Item;
    warehouse: Warehouse;
    production_date: string;
    quantity_produced: string;
    quantity_scrap: string;
    scrap_reason: ScrapReason | null;
    operator: Employee | null;
    notes: string | null;
    created_at: string;
}

export interface WorkOrderScrap {
    id: number;
    reason: ScrapReason;
    quantity: string;
    cost_impact: string;
    notes: string | null;
}

export interface WorkOrder {
    id: number;
    item: Item;
    bom_id: number;
    routing_id: number | null;
    warehouse: Warehouse;
    scheduled_date: string | null;
    quantity_planned: string;
    quantity_completed: string;
    material_cost: string;
    status: WorkOrderStatus;
    materials: WorkOrderMaterial[];
    scraps: WorkOrderScrap[];
    released_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export interface MrpNetRequirement {
    item_id: number;
    sku: string | null;
    name: string | null;
    gross_required: string;
    on_hand: string;
    net_required: string;
}

export interface CapacityDayLoad {
    date: string;
    load_hours: string;
    capacity_hours: string | null;
    utilization_percent: number | null;
    overloaded: boolean;
}

export interface CapacityWorkCenterLoad {
    work_center_id: number;
    work_center_code: string;
    work_center_name: string;
    capacity_hours_per_day: string | null;
    days: CapacityDayLoad[];
}

export type SubcontractOrderStatus = 'draft' | 'materials_sent' | 'completed';

export interface SubcontractOrderMaterial {
    id: number;
    component: Item;
    quantity_required: string;
    quantity_sent: string;
}

export interface SubcontractOrder {
    id: number;
    vendor: Vendor;
    item: Item;
    bom_id: number;
    warehouse: Warehouse;
    quantity_planned: string;
    quantity_received: string;
    materials_cost: string;
    service_cost: string;
    total_cost: string;
    status: SubcontractOrderStatus;
    materials: SubcontractOrderMaterial[];
    materials_sent_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export type ReworkOrderStatus = 'draft' | 'released' | 'completed';

export interface ReworkOrderMaterial {
    id: number;
    component: Item;
    quantity_required: string;
    quantity_issued: string;
}

export interface ReworkOrder {
    id: number;
    item: Item;
    source_work_order_id: number | null;
    bom_id: number | null;
    warehouse: Warehouse;
    quantity_input: string;
    quantity_recovered: string;
    material_cost: string;
    labor_cost: string;
    total_cost: string;
    status: ReworkOrderStatus;
    materials: ReworkOrderMaterial[];
    released_at: string | null;
    completed_at: string | null;
    created_at: string;
}
