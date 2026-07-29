import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    BatchPreview,
    Bom,
    DowntimeReason,
    FactorySetting,
    ImportResult,
    ProductionConfiguration,
    CapacityWorkCenterLoad,
    DayBinMovement,
    DayBinState,
    EntryDayBinSummary,
    MachineDowntimeLog,
    MaterialBag,
    MaterialBagStatus,
    MaterialLot,
    Mold,
    MoldChangeLog,
    MoldStatus,
    MrpNetRequirement,
    PowerInterruptionLog,
    ProductionReport,
    ReconciliationReportRow,
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
    TraceabilityReportRow,
    VoucherPreview,
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

/**
 * Every machine's currently-running batch across ALL shifts/dates, never
 * paginated — the authoritative source for the Shift Floor's machine state
 * (matches the backend's global one-in-progress-per-machine guard).
 */
export async function listActiveBatches(): Promise<{ data: ShiftProductionEntry[] }> {
    const { data } = await api.get<{ data: ShiftProductionEntry[] }>('/production/shift-production-entries/active');
    return data;
}

export interface StartBatchPayload {
    shift_id: number;
    work_center_id: number;
    item_id: number;
    warehouse_id: number;
    production_date?: string;
    operator_id?: number;
    // Backend defaults active cavities to the item's standard at Start Batch;
    // sent when the supervisor overrides it up front (e.g. blocked cavity).
    // Complete Batch re-sends it, so a backend that ignores this still gets
    // the corrected value at completion.
    active_cavities?: number;
}

export interface BatchPreviewParams {
    item_id: number;
    work_center_id?: number;
    warehouse_id?: number;
    shift_id?: number;
    planned_hours?: number;
    active_cavities?: number;
}

/**
 * Readiness + estimation for an intended run, before it starts. Read-only,
 * so it is safe to call on every product/machine change while the
 * supervisor fills the form.
 */
export async function getBatchPreview(params: BatchPreviewParams): Promise<BatchPreview> {
    const { data } = await api.get<{ data: BatchPreview }>('/production/shift-production-entries/preview', { params });
    return data.data;
}

/** What Tally is about to receive for an entry, resolved against real masters. */
export async function getVoucherPreview(entryId: number): Promise<VoucherPreview> {
    const { data } = await api.get<{ data: VoucherPreview }>(
        `/production/shift-production-entries/${entryId}/voucher-preview`,
    );
    return data.data;
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
    // null allowed: a cleared InputNumber submits null (backend rules are nullable)
    nos_per_tray?: number | null;
    no_of_trays?: number | null;
    nos_per_box?: number | null;
    no_of_box?: number | null;
    no_of_pouches?: number | null;
    // Persisted since Wave A packaging (was a frontend-only derivation helper).
    loose_pieces?: number | null;
    running_hours?: number;
    qc_rejection_kg?: number;
    actual_cycle_time?: number;
    active_cavities?: number;
    helper_name?: string;
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

// The 4-stage approval chain: PM verifies → Accountant reconciles → MD final
// approval (which is what makes the entry eligible to sync to Tally).
export async function pmApproveShiftProductionEntry(id: number): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/pm-approve`);
    return data.data;
}

export async function accountantApproveShiftProductionEntry(id: number): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/accountant-approve`);
    return data.data;
}

export async function mdApproveShiftProductionEntry(id: number): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(`/production/shift-production-entries/${id}/md-approve`);
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

// ---------------------------------------------------------------------------
// Lot/barcode traceability (Phase 6). Every endpoint below exists ONLY when
// config('production.traceability_enabled') is on — with it off the backend
// 404s and the UI never calls these (gated on settings.traceability_enabled).
// Lots/bags are Inventory's surface (/inventory/*); the day-bin ledger and
// the aggregates are Production's.
// ---------------------------------------------------------------------------

export async function listMaterialLots(itemId?: number): Promise<Paginated<MaterialLot>> {
    const { data } = await api.get<Paginated<MaterialLot>>('/inventory/material-lots', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
}

/** Register one supplier lot (backend fans out one barcoded bag row per bag). */
export interface CreateMaterialLotPayload {
    grn_id?: number;
    item_id: number;
    supplier_lot_no?: string;
    received_date: string;
    bag_count: number;
    /** Nominal kg per bag; omitted = total / count. */
    bag_weight_kg?: number;
    total_received_kg: number;
    warehouse_id?: number;
    notes?: string;
    /** Supplier barcodes, one per bag; omitted = app-generated LOT{lot}-B{seq}. */
    barcodes?: string[];
    /** Individually weighed bags; omitted = nominal bag_weight_kg. */
    bag_weights?: number[];
}

export async function createMaterialLot(payload: CreateMaterialLotPayload): Promise<MaterialLot> {
    const { data } = await api.post<{ data: MaterialLot }>('/inventory/material-lots', payload);
    return data.data;
}

export async function listMaterialBags(params?: {
    item_id?: number;
    status?: MaterialBagStatus;
}): Promise<Paginated<MaterialBag>> {
    const { data } = await api.get<Paginated<MaterialBag>>('/inventory/material-bags', { params });
    return data;
}

/** FIFO pick list: open in-store bags for the item, oldest lot first. */
export async function getMaterialBagPickList(itemId: number): Promise<MaterialBag[]> {
    const { data } = await api.get<{ data: MaterialBag[] }>('/inventory/material-bags/pick-list', {
        params: { item_id: itemId },
    });
    return data.data;
}

/** Live day-bin state (per-material balance + bags currently at the machine). */
export async function getDayBin(workCenterId: number): Promise<DayBinState> {
    const { data } = await api.get<{ data: DayBinState }>(`/production/work-centers/${workCenterId}/day-bin`);
    return data.data;
}

export interface LoadDayBinPayload {
    work_center_id: number;
    /** Scanned bag barcode — the scanner-gun path. Exactly one of barcode / material_bag_id. */
    barcode?: string;
    /** Alternative when the bag id is already known (pick-list UI). */
    material_bag_id?: number;
    /** Omit for a full-bag load (the bag's whole remaining_kg); set for a weighed partial. */
    quantity_kg?: number;
    /** The running segment the load belongs to. */
    shift_production_entry_id?: number;
    /**
     * Re-send after a FIFO refusal (422 with code 'fifo_order') — requires
     * the `production.override-fifo` permission and records who overrode.
     */
    override_fifo?: boolean;
}

export async function loadDayBin(payload: LoadDayBinPayload): Promise<DayBinMovement> {
    const { data } = await api.post<{ data: DayBinMovement }>('/production/day-bin/load', payload);
    return data.data;
}

export interface ReturnDayBinPayload {
    work_center_id: number;
    item_id: number;
    quantity_kg: number;
    /** Named bag: the kg flows back into it; absent = ledger row only (Vincent Q4 open). */
    material_bag_id?: number;
    shift_production_entry_id?: number;
}

export async function returnDayBin(payload: ReturnDayBinPayload): Promise<DayBinMovement> {
    const { data } = await api.post<{ data: DayBinMovement }>('/production/day-bin/return', payload);
    return data.data;
}

export interface CountDayBinPayload {
    work_center_id: number;
    item_id: number;
    /** The weighed/estimated absolute figure — an observation, not a delta. */
    quantity_kg: number;
    shift_production_entry_id?: number;
}

export async function countDayBin(payload: CountDayBinPayload): Promise<DayBinMovement> {
    const { data } = await api.post<{ data: DayBinMovement }>('/production/day-bin/count', payload);
    return data.data;
}

/**
 * Backend-computed consumption per material for one entry (segment):
 * opening + Σ loaded − closing − Σ returned. Null (rather than throwing) on a
 * 404 so the Complete Batch drawer degrades gracefully when the backend
 * doesn't serve traceability yet.
 */
export async function getEntryDayBinSummary(entryId: number): Promise<EntryDayBinSummary | null> {
    try {
        const { data } = await api.get<{ data: EntryDayBinSummary }>(
            `/production/shift-production-entries/${entryId}/day-bin`,
        );
        return data.data;
    } catch (error: any) {
        if (error?.response?.status === 404) return null;
        throw error;
    }
}

// ---------------------------------------------------------------------------
// Read-only reports (feat/reports-wave). Envelope rule shared with the
// backend: production = {data: {rows, totals}}; reconciliation =
// {data: {rows}}; traceability = {data: {lots}}.
// ---------------------------------------------------------------------------

export interface ProductionReportParams {
    /** Production date (YYYY-MM-DD). */
    date: string;
    shift_id?: number;
    work_center_id?: number;
}

export async function getProductionReport(params: ProductionReportParams): Promise<ProductionReport> {
    const { data } = await api.get<{ data: ProductionReport }>('/production/reports/production', { params });
    return data.data;
}

export interface ReconciliationReportParams {
    date_from: string;
    date_to: string;
    shift_id?: number;
}

/** Rows come back worst-unaccounted-first — the UI keeps the server order. */
export async function getReconciliationReport(params: ReconciliationReportParams): Promise<ReconciliationReportRow[]> {
    const { data } = await api.get<{ data: { rows: ReconciliationReportRow[] } }>(
        '/production/reports/reconciliation',
        { params },
    );
    return data.data.rows;
}

export interface TraceabilityReportParams {
    /** Lot received_date window — required (same ≤92-day cap as reconciliation). */
    date_from: string;
    date_to: string;
    lot_id?: number;
    item_id?: number;
}

/**
 * Lot → bags → fed machine/segment drill-down. 404s while
 * config('production.traceability_enabled') is off (same flag/middleware as
 * the day-bin routes) — callers only run this with the flag on, but a null
 * degrade keeps a stale tab from crashing on a freshly-disabled backend.
 */
export async function getTraceabilityReport(params: TraceabilityReportParams): Promise<TraceabilityReportRow[] | null> {
    try {
        const { data } = await api.get<{ data: { lots: TraceabilityReportRow[] } }>(
            '/production/reports/traceability',
            { params },
        );
        return data.data.lots;
    } catch (error: any) {
        if (error?.response?.status === 404) return null;
        throw error;
    }
}

export interface HandoverPayload {
    /** The incoming shift taking the machine over. */
    shift_id: number;
    /** The incoming shift's production date (shift-aware, may differ across midnight). */
    production_date?: string;
    /** The incoming operator, when known. */
    operator_id?: number;
    /** Closing day-bin weighments for the OUTGOING segment, one per material. */
    closing_day_bin?: { item_id: number; quantity_kg: number }[];
    /** The outgoing segment's completion figures — mirrors CompleteBatchRequest. */
    completion: CompleteBatchPayload;
}

/**
 * Shift handover: records the outgoing segment's closing day-bin counts,
 * completes it with the given figures, and opens a new entry with the same
 * batch number, product, mold standards and machine; the closing balance
 * carries in as the new segment's opening. Returns the NEW running segment.
 */
export async function handoverShiftProductionEntry(id: number, payload: HandoverPayload): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(
        `/production/shift-production-entries/${id}/handover`,
        payload,
    );
    return data.data;
}

// ---------------------------------------------------------------------
// Configurable production
// ---------------------------------------------------------------------

export async function listProductionConfigurations(params?: {
    work_center_id?: number;
    item_id?: number;
    status?: string;
    search?: string;
}): Promise<Paginated<ProductionConfiguration>> {
    const { data } = await api.get<Paginated<ProductionConfiguration>>('/production/configurations', { params });
    return data;
}

export async function listMachineConfigurations(workCenterId: number): Promise<{ data: ProductionConfiguration[] }> {
    const { data } = await api.get<{ data: ProductionConfiguration[] }>(
        `/production/work-centers/${workCenterId}/configurations`,
    );
    return data;
}

export interface ProductionConfigurationPayload {
    work_center_id: number;
    item_id: number;
    mold_id?: number | null;
    colour?: string | null;
    unit_weight_grams?: number | null;
    default_cycle_time?: number | null;
    cycle_time_min?: number | null;
    cycle_time_max?: number | null;
    default_cavities?: number | null;
    permitted_cavities?: number[] | null;
    effective_from?: string | null;
    notes?: string | null;
}

export async function createProductionConfiguration(payload: ProductionConfigurationPayload): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>('/production/configurations', payload);
    return data.data;
}

export async function updateProductionConfiguration(
    id: number,
    payload: ProductionConfigurationPayload,
): Promise<ProductionConfiguration> {
    const { data } = await api.put<{ data: ProductionConfiguration }>(`/production/configurations/${id}`, payload);
    return data.data;
}

/** Approval is an act with an actor, not a status field — hence its own call. */
export async function approveProductionConfiguration(id: number): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>(`/production/configurations/${id}/approve`);
    return data.data;
}

export async function deactivateProductionConfiguration(id: number): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>(`/production/configurations/${id}/deactivate`);
    return data.data;
}

export async function copyProductionConfiguration(id: number): Promise<ProductionConfiguration> {
    const { data } = await api.post<{ data: ProductionConfiguration }>(`/production/configurations/${id}/copy`);
    return data.data;
}

export async function importProductionConfigurations(
    rows: Record<string, unknown>[],
    dryRun: boolean,
): Promise<ImportResult> {
    const { data } = await api.post<{ data: ImportResult }>('/production/configurations/import', {
        rows,
        dry_run: dryRun,
    });
    return data.data;
}

export async function listDowntimeReasons(selectableAtStart?: boolean): Promise<{ data: DowntimeReason[] }> {
    const { data } = await api.get<{ data: DowntimeReason[] }>('/production/downtime-reasons', {
        params: selectableAtStart ? { selectable_at_start: 1 } : undefined,
    });
    return data;
}

export async function saveDowntimeReason(
    payload: Partial<DowntimeReason> & { code: string; description: string; planning_type: string },
    id?: number,
): Promise<DowntimeReason> {
    const { data } = id
        ? await api.put<{ data: DowntimeReason }>(`/production/downtime-reasons/${id}`, payload)
        : await api.post<{ data: DowntimeReason }>('/production/downtime-reasons', payload);
    return data.data;
}

export async function listFactorySettings(): Promise<{ data: FactorySetting[] }> {
    const { data } = await api.get<{ data: FactorySetting[] }>('/production/factory-settings');
    return data;
}

export async function saveFactorySetting(payload: {
    key: string;
    value: string | null;
    change_reason?: string;
}): Promise<FactorySetting> {
    const { data } = await api.post<{ data: FactorySetting }>('/production/factory-settings', payload);
    return data.data;
}

export async function updateWorkCenterCapability(
    id: number,
    payload: {
        name?: string;
        capacity_class?: string | null;
        min_cavities?: number | null;
        max_cavities?: number | null;
        permitted_cavities?: number[] | null;
        cycle_time_min?: number | null;
        cycle_time_max?: number | null;
        default_shift_hours?: number | null;
    },
): Promise<WorkCenter> {
    const { data } = await api.put<{ data: WorkCenter }>(`/production/work-centers/${id}`, payload);
    return data.data;
}
