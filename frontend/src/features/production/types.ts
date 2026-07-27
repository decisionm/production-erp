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

export type BatchStatus = 'in_progress' | 'completed';
export type ShiftProductionEntryStatus =
    | 'pending'
    | 'pm_approved'
    | 'accountant_approved'
    | 'approved'
    | 'rejected'
    | 'synced'
    | 'failed';
export type ShiftScrapType = 'rejected_finished_good' | 'lumps';

export interface ShiftMaterialConsumption {
    id: number;
    item: Item;
    warehouse: Warehouse;
    quantity_issued_kg: string;
}

export interface ShiftScrap {
    id: number;
    type: ShiftScrapType;
    quantity_nos: string | null;
    quantity_kg: string | null;
    scrap_reason: ScrapReason | null;
}

export type ConsumptionNormSource = 'bom' | 'item_weight';

export interface ConsumptionVariance {
    /** How expected_kg was derived; null = no norm available. */
    norm_source: ConsumptionNormSource | null;
    /** Numeric string, e.g. "20.0000"; null when no norm or quantity_produced is null/0. */
    expected_kg: string | null;
    /** Sum of material consumption quantity_issued_kg, "0" if none. */
    actual_kg: string;
    /** actual - expected; null when expected_kg is null. */
    variance_kg: string | null;
    /** (actual-expected)/expected*100 rounded to 1 decimal; null when expected null or 0. */
    variance_pct: number | null;
    /** Entry quantity_rejection_kg or "0". */
    rejection_kg: string;
    /** Sum of scraps quantity_kg, "0". */
    scrap_kg: string;
    /** actual - expected - rejection - scrap; null when expected_kg null. */
    unaccounted_kg: string | null;
}

export interface ShiftProductionEntry {
    id: number;
    shift: Shift;
    work_center: WorkCenter;
    item: Item;
    warehouse: Warehouse;
    production_date: string;
    batch_status: BatchStatus;
    batch_number: string | null;
    quantity_produced: string | null;
    quantity_produced_kg: string | null;
    quantity_scrap: string;
    quantity_rejection_kg: string | null;
    scrap_reason: ScrapReason | null;
    nos_per_tray: number | null;
    no_of_trays: number | null;
    nos_per_box: number | null;
    no_of_box: number | null;
    material_consumptions: ShiftMaterialConsumption[];
    scraps: ShiftScrap[];
    /** Null when batch_status is not completed (no consumption yet). */
    variance: ConsumptionVariance | null;
    /** Latest Tally sync error — present only when status is "failed". */
    sync_error?: string | null;
    status: ShiftProductionEntryStatus;
    rejection_reason: string | null;
    plant_manager_signed_by?: { id: number; name: string } | null;
    plant_manager_signed_at?: string | null;
    accountant_signed_by?: { id: number; name: string } | null;
    accountant_signed_at?: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    operator: Employee | null;
    helper_name: string | null;
    notes: string | null;
    created_at: string;
}

export interface ShiftSummary {
    id: number;
    shift: Shift;
    production_date: string;
    supervisor: Employee | null;
    target_production_kg: string | null;
    power_consumption_units: string | null;
    remarks: string | null;
    created_at: string;
}

export type LogStatus = 'open' | 'closed';

export interface MachineDowntimeLog {
    id: number;
    work_center: WorkCenter;
    shift: Shift;
    production_date: string;
    nature_of_problem: string;
    remedy: string | null;
    parts_changed: string | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export type MoldStatus = 'active' | 'under_repair' | 'retired';

export interface Mold {
    id: number;
    code: string;
    name: string;
    cavity_count: number | null;
    status: MoldStatus;
    notes: string | null;
    created_at: string;
}

export interface MoldChangeLog {
    id: number;
    work_center: WorkCenter;
    shift: Shift;
    production_date: string;
    changed_from_item: Item | null;
    changed_from_mold: Mold | null;
    changed_to_item: Item;
    changed_to_mold: Mold | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export interface PowerInterruptionLog {
    id: number;
    shift: Shift;
    production_date: string;
    from_time: string;
    to_time: string;
    idle_hours: string;
}

export interface ShiftStockCount {
    id: number;
    shift: Shift;
    production_date: string;
    location_label: string;
    item: Item;
    quantity_kg: string;
}

export interface ShiftKpiItemBreakdown {
    item: { id: number; sku: string; name: string };
    batches: number;
    quantity_produced: string;
    quantity_produced_kg: string;
    quantity_rejected: string;
    quantity_rejection_kg: string;
}

export interface ShiftKpiDowntimeLog {
    id: number;
    work_center: string;
    nature_of_problem: string;
    remedy: string | null;
    parts_changed: string | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export interface ShiftKpiMoldChangeLog {
    id: number;
    work_center: string;
    changed_from: string | null;
    changed_from_mold: string | null;
    changed_to: string;
    changed_to_mold: string | null;
    from_time: string;
    to_time: string | null;
    total_minutes: string | null;
    status: LogStatus;
}

export interface ShiftKpiPowerInterruptionLog {
    id: number;
    from_time: string;
    to_time: string;
    idle_hours: string;
}

export interface ShiftKpiStockCount {
    id: number;
    location_label: string;
    item: { id: number; sku: string; name: string };
    quantity_kg: string;
}

export interface ShiftKpiReport {
    shift_id: number | null;
    production_date: string;
    target_production_kg: string | null;
    actual_production_kg: string;
    rejection_kg: string;
    net_good_output_kg: string;
    efficiency_percent: number | null;
    rejection_percent: number | null;
    machines_running: number;
    machines_down: number;
    idle_time_hours: string;
    no_of_mold_changes: number;
    power_consumption_units: string | null;
    unit_per_kg: number | null;
    power_interruption_hours: string;
    remarks: string | null;
    supervisor: Employee | null;
    items_manufactured: ShiftKpiItemBreakdown[];
    downtime_logs: ShiftKpiDowntimeLog[];
    mold_change_logs: ShiftKpiMoldChangeLog[];
    power_interruption_logs: ShiftKpiPowerInterruptionLog[];
    stock_counts: ShiftKpiStockCount[];
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
