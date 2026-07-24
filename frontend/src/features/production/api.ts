import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    Bom,
    CapacityWorkCenterLoad,
    MachineDowntimeLog,
    Mold,
    MoldChangeLog,
    MoldStatus,
    MrpNetRequirement,
    PowerInterruptionLog,
    ReworkOrder,
    Routing,
    ScrapReason,
    Shift,
    ShiftKpiReport,
    ShiftProductionEntry,
    ShiftProductionEntryStatus,
    ShiftStockCount,
    ShiftSummary,
    SubcontractOrder,
    WorkCenter,
    WorkOrder,
} from './types';

export async function listWorkCenters(): Promise<Paginated<WorkCenter>> {
    const { data } = await api.get<Paginated<WorkCenter>>('/production/work-centers');
    return data;
}

export interface CreateWorkCenterPayload {
    code: string;
    name: string;
    capacity_hours_per_day?: number;
}

export async function createWorkCenter(payload: CreateWorkCenterPayload): Promise<WorkCenter> {
    const { data } = await api.post<{ data: WorkCenter }>('/production/work-centers', payload);
    return data.data;
}

export type UpdateWorkCenterPayload = Partial<CreateWorkCenterPayload> & { is_active?: boolean };

export async function updateWorkCenter(id: number, payload: UpdateWorkCenterPayload): Promise<WorkCenter> {
    const { data } = await api.put<{ data: WorkCenter }>(`/production/work-centers/${id}`, payload);
    return data.data;
}

export async function listBoms(itemId?: number): Promise<Paginated<Bom>> {
    const { data } = await api.get<Paginated<Bom>>('/production/boms', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
}

export interface CreateBomPayload {
    item_id: number;
    name: string;
    version?: string;
    is_active?: boolean;
    lines: { component_item_id: number; quantity_per: number }[];
}

export async function createBom(payload: CreateBomPayload): Promise<Bom> {
    const { data } = await api.post<{ data: Bom }>('/production/boms', payload);
    return data.data;
}

export async function listRoutings(itemId?: number): Promise<Paginated<Routing>> {
    const { data } = await api.get<Paginated<Routing>>('/production/routings', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
}

export interface CreateRoutingPayload {
    item_id: number;
    name: string;
    is_active?: boolean;
    operations: { work_center_id: number; sequence: number; name: string; standard_time_minutes?: number }[];
}

export async function createRouting(payload: CreateRoutingPayload): Promise<Routing> {
    const { data } = await api.post<{ data: Routing }>('/production/routings', payload);
    return data.data;
}

export async function listWorkOrders(): Promise<Paginated<WorkOrder>> {
    const { data } = await api.get<Paginated<WorkOrder>>('/production/work-orders');
    return data;
}

export interface CreateWorkOrderPayload {
    item_id: number;
    bom_id?: number;
    routing_id?: number;
    warehouse_id: number;
    scheduled_date?: string;
    quantity_planned: number;
}

export async function createWorkOrder(payload: CreateWorkOrderPayload): Promise<WorkOrder> {
    const { data } = await api.post<{ data: WorkOrder }>('/production/work-orders', payload);
    return data.data;
}

export async function releaseWorkOrder(id: number): Promise<WorkOrder> {
    const { data } = await api.post<{ data: WorkOrder }>(`/production/work-orders/${id}/release`);
    return data.data;
}

export interface WorkOrderScrapEntry {
    scrap_reason_id: number;
    quantity: number;
    notes?: string;
}

export async function completeWorkOrder(
    id: number,
    quantityCompleted: number,
    batchNumber?: string,
    scrap?: WorkOrderScrapEntry[],
): Promise<WorkOrder> {
    const { data } = await api.post<{ data: WorkOrder }>(`/production/work-orders/${id}/complete`, {
        quantity_completed: quantityCompleted,
        batch_number: batchNumber,
        scrap,
    });
    return data.data;
}

export async function getMrpNetRequirements(itemId: number, quantity: number): Promise<MrpNetRequirement[]> {
    const { data } = await api.get<{ data: MrpNetRequirement[] }>('/production/mrp/net-requirements', {
        params: { item_id: itemId, quantity },
    });
    return data.data;
}

export async function getCapacityLoadReport(startDate: string, endDate: string): Promise<CapacityWorkCenterLoad[]> {
    const { data } = await api.get<{ data: CapacityWorkCenterLoad[] }>('/production/capacity/load-report', {
        params: { start_date: startDate, end_date: endDate },
    });
    return data.data;
}

export async function listSubcontractOrders(): Promise<Paginated<SubcontractOrder>> {
    const { data } = await api.get<Paginated<SubcontractOrder>>('/production/subcontract-orders');
    return data;
}

export interface CreateSubcontractOrderPayload {
    vendor_id: number;
    item_id: number;
    bom_id?: number;
    warehouse_id: number;
    quantity_planned: number;
}

export async function createSubcontractOrder(payload: CreateSubcontractOrderPayload): Promise<SubcontractOrder> {
    const { data } = await api.post<{ data: SubcontractOrder }>('/production/subcontract-orders', payload);
    return data.data;
}

export async function sendSubcontractOrderMaterials(id: number): Promise<SubcontractOrder> {
    const { data } = await api.post<{ data: SubcontractOrder }>(`/production/subcontract-orders/${id}/send-materials`);
    return data.data;
}

export async function receiveSubcontractOrder(
    id: number,
    payload: { quantity_received: number; service_cost: number },
): Promise<SubcontractOrder> {
    const { data } = await api.post<{ data: SubcontractOrder }>(`/production/subcontract-orders/${id}/receive`, payload);
    return data.data;
}

export async function listScrapReasons(): Promise<Paginated<ScrapReason>> {
    const { data } = await api.get<Paginated<ScrapReason>>('/production/scrap-reasons');
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
export async function listAllScrapReasons(): Promise<Paginated<ScrapReason>> {
    const { data } = await api.get<Paginated<ScrapReason>>('/production/scrap-reasons', { params: { per_page: 1000 } });
    return data;
}

export interface CreateScrapReasonPayload {
    code: string;
    name: string;
}

export async function createScrapReason(payload: CreateScrapReasonPayload): Promise<ScrapReason> {
    const { data } = await api.post<{ data: ScrapReason }>('/production/scrap-reasons', payload);
    return data.data;
}

export async function listShifts(): Promise<Paginated<Shift>> {
    const { data } = await api.get<Paginated<Shift>>('/production/shifts');
    return data;
}

export interface CreateShiftPayload {
    name: string;
    start_time: string;
    end_time: string;
}

export async function createShift(payload: CreateShiftPayload): Promise<Shift> {
    const { data } = await api.post<{ data: Shift }>('/production/shifts', payload);
    return data.data;
}

export async function listShiftProductionEntries(status?: ShiftProductionEntryStatus): Promise<Paginated<ShiftProductionEntry>> {
    const { data } = await api.get<Paginated<ShiftProductionEntry>>('/production/shift-production-entries', {
        params: status ? { status } : undefined,
    });
    return data;
}

export interface StartBatchPayload {
    shift_id: number;
    work_center_id: number;
    item_id: number;
    warehouse_id: number;
    production_date?: string;
    operator_id?: number;
}

export async function startBatch(payload: StartBatchPayload): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>('/production/shift-production-entries', payload);
    return data.data;
}

export interface CompleteBatchPayload {
    batch_number?: string;
    quantity_produced: number;
    quantity_scrap?: number;
    scrap_reason_id?: number;
    nos_per_tray?: number;
    no_of_trays?: number;
    nos_per_box?: number;
    no_of_box?: number;
    notes?: string;
    material_consumptions?: { item_id: number; warehouse_id: number; quantity_issued_kg: number }[];
    scraps?: { type: 'rejected_finished_good' | 'lumps'; quantity_nos?: number; quantity_kg?: number; scrap_reason_id?: number }[];
}

export async function completeBatch(id: number, payload: CompleteBatchPayload): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(
        `/production/shift-production-entries/${id}/complete`,
        payload,
    );
    return data.data;
}

export async function approveShiftProductionEntry(id: number): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/approve`);
    return data.data;
}

export async function rejectShiftProductionEntry(id: number, reason?: string): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/reject`, {
        reason,
    });
    return data.data;
}

export interface SaveShiftSummaryPayload {
    shift_id: number;
    production_date?: string;
    supervisor_id?: number;
    target_production_kg?: number;
    power_consumption_units?: number;
    remarks?: string;
}

export async function saveShiftSummary(payload: SaveShiftSummaryPayload): Promise<ShiftSummary> {
    const { data } = await api.post<{ data: ShiftSummary }>('/production/shift-summaries', payload);
    return data.data;
}

// shiftId omitted means "every shift that ran this date" — the day-wide rollup.
export async function getShiftKpiReport(shiftId: number | undefined, productionDate: string): Promise<ShiftKpiReport> {
    const { data } = await api.get<{ data: ShiftKpiReport }>('/production/shift-summaries/report', {
        params: { shift_id: shiftId, production_date: productionDate },
    });
    return data.data;
}

export async function listReworkOrders(): Promise<Paginated<ReworkOrder>> {
    const { data } = await api.get<Paginated<ReworkOrder>>('/production/rework-orders');
    return data;
}

export interface CreateReworkOrderPayload {
    item_id: number;
    source_work_order_id?: number;
    bom_id?: number;
    warehouse_id: number;
    quantity_input: number;
}

export async function createReworkOrder(payload: CreateReworkOrderPayload): Promise<ReworkOrder> {
    const { data } = await api.post<{ data: ReworkOrder }>('/production/rework-orders', payload);
    return data.data;
}

export async function releaseReworkOrder(id: number): Promise<ReworkOrder> {
    const { data } = await api.post<{ data: ReworkOrder }>(`/production/rework-orders/${id}/release`);
    return data.data;
}

export async function completeReworkOrder(
    id: number,
    payload: { quantity_recovered: number; labor_cost: number },
): Promise<ReworkOrder> {
    const { data } = await api.post<{ data: ReworkOrder }>(`/production/rework-orders/${id}/complete`, payload);
    return data.data;
}

export async function listMachineDowntimeLogs(): Promise<Paginated<MachineDowntimeLog>> {
    const { data } = await api.get<Paginated<MachineDowntimeLog>>('/production/machine-downtime-logs');
    return data;
}

export interface OpenDowntimeLogPayload {
    work_center_id: number;
    shift_id: number;
    production_date?: string;
    nature_of_problem: string;
    from_time?: string;
}

export async function openDowntimeLog(payload: OpenDowntimeLogPayload): Promise<MachineDowntimeLog> {
    const { data } = await api.post<{ data: MachineDowntimeLog }>('/production/machine-downtime-logs', payload);
    return data.data;
}

export async function closeDowntimeLog(
    id: number,
    payload: { remedy?: string; parts_changed?: string; to_time?: string },
): Promise<MachineDowntimeLog> {
    const { data } = await api.post<{ data: MachineDowntimeLog }>(`/production/machine-downtime-logs/${id}/close`, payload);
    return data.data;
}

export async function listMoldChangeLogs(): Promise<Paginated<MoldChangeLog>> {
    const { data } = await api.get<Paginated<MoldChangeLog>>('/production/mold-change-logs');
    return data;
}

export interface OpenMoldChangeLogPayload {
    work_center_id: number;
    shift_id: number;
    production_date?: string;
    changed_from_item_id?: number;
    changed_from_mold_id?: number;
    changed_to_item_id: number;
    changed_to_mold_id: number;
    from_time?: string;
    // Given alongside from_time, the change is logged as already complete
    // in one step instead of needing a separate "Finish Mold Change" call.
    to_time?: string;
}

export async function openMoldChangeLog(payload: OpenMoldChangeLogPayload): Promise<MoldChangeLog> {
    const { data } = await api.post<{ data: MoldChangeLog }>('/production/mold-change-logs', payload);
    return data.data;
}

export async function closeMoldChangeLog(id: number, toTime?: string): Promise<MoldChangeLog> {
    const { data } = await api.post<{ data: MoldChangeLog }>(`/production/mold-change-logs/${id}/close`, {
        to_time: toTime,
    });
    return data.data;
}

export async function listMolds(): Promise<Paginated<Mold>> {
    const { data } = await api.get<Paginated<Mold>>('/production/molds');
    return data;
}

/** Full reference list for a picker (all rows, not the default first page). */
export async function listAllMolds(): Promise<Paginated<Mold>> {
    const { data } = await api.get<Paginated<Mold>>('/production/molds', { params: { per_page: 1000 } });
    return data;
}

export interface CreateMoldPayload {
    code: string;
    name: string;
    cavity_count?: number;
    status?: MoldStatus;
    notes?: string;
}

export async function createMold(payload: CreateMoldPayload): Promise<Mold> {
    const { data } = await api.post<{ data: Mold }>('/production/molds', payload);
    return data.data;
}

export type UpdateMoldPayload = Partial<CreateMoldPayload>;

export async function updateMold(id: number, payload: UpdateMoldPayload): Promise<Mold> {
    const { data } = await api.put<{ data: Mold }>(`/production/molds/${id}`, payload);
    return data.data;
}

export async function listPowerInterruptionLogs(): Promise<Paginated<PowerInterruptionLog>> {
    const { data } = await api.get<Paginated<PowerInterruptionLog>>('/production/power-interruption-logs');
    return data;
}

export interface CreatePowerInterruptionLogPayload {
    shift_id: number;
    production_date?: string;
    from_time: string;
    to_time: string;
}

export async function createPowerInterruptionLog(payload: CreatePowerInterruptionLogPayload): Promise<PowerInterruptionLog> {
    const { data } = await api.post<{ data: PowerInterruptionLog }>('/production/power-interruption-logs', payload);
    return data.data;
}

export async function listShiftStockCounts(): Promise<Paginated<ShiftStockCount>> {
    const { data } = await api.get<Paginated<ShiftStockCount>>('/production/shift-stock-counts');
    return data;
}

export interface CreateShiftStockCountPayload {
    shift_id: number;
    production_date?: string;
    location_label: string;
    item_id: number;
    quantity_kg: number;
}

export async function createShiftStockCount(payload: CreateShiftStockCountPayload): Promise<ShiftStockCount> {
    const { data } = await api.post<{ data: ShiftStockCount }>('/production/shift-stock-counts', payload);
    return data.data;
}
