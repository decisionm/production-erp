import { api } from '@/lib/api';
import { type ListParams, compactParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import type { ShiftProductionEntry } from '@/features/production/types';
import type { Capa, IncomingInspection, InspectionResult, MeasuringInstrument, NonConformanceReport, SpcChart, SpcCharacteristic, SpcMeasurement } from './types';

export interface IncomingInspectionListParams extends ListParams {
    /** The verdict to narrow to; absent is every result. */
    result?: InspectionResult;
}

/**
 * The register, one page at a time. `q`, `result`, `page` and `per_page`
 * go to the server (ListIncomingInspectionsRequest), which cuts the page
 * AFTER the filter — so the total is the matching set's and nothing is
 * filtered here. Bare, it is the first twenty, newest first, exactly as
 * before.
 */
export async function listIncomingInspections(
    params: IncomingInspectionListParams = {},
): Promise<Paginated<IncomingInspection>> {
    const { data } = await api.get<Paginated<IncomingInspection>>('/quality/incoming-inspections', {
        params: compactParams(params),
    });
    return data;
}

export interface CreateIncomingInspectionPayload {
    goods_receipt_note_line_id: number;
    inspected_quantity: number;
    accepted_quantity: number;
    rejected_quantity: number;
    inspection_date: string;
    notes?: string;
}

export async function createIncomingInspection(
    payload: CreateIncomingInspectionPayload,
): Promise<IncomingInspection> {
    const { data } = await api.post<{ data: IncomingInspection }>('/quality/incoming-inspections', payload);
    return data.data;
}

export async function listNonConformanceReports(): Promise<Paginated<NonConformanceReport>> {
    const { data } = await api.get<Paginated<NonConformanceReport>>('/quality/ncrs');
    return data;
}

export interface CreateNonConformanceReportPayload {
    incoming_inspection_id?: number;
    item_id?: number;
    description: string;
    severity: NonConformanceReport['severity'];
    quantity_affected?: number;
    raised_date: string;
}

export async function createNonConformanceReport(
    payload: CreateNonConformanceReportPayload,
): Promise<NonConformanceReport> {
    const { data } = await api.post<{ data: NonConformanceReport }>('/quality/ncrs', payload);
    return data.data;
}

export async function closeNonConformanceReport(id: number, resolution: string): Promise<NonConformanceReport> {
    const { data } = await api.post<{ data: NonConformanceReport }>(`/quality/ncrs/${id}/close`, { resolution });
    return data.data;
}

export async function listCapas(): Promise<Paginated<Capa>> {
    const { data } = await api.get<Paginated<Capa>>('/quality/capas');
    return data;
}

export interface CreateCapaPayload {
    non_conformance_report_id?: number;
    title: string;
    problem_statement: string;
    owner?: number;
    due_date?: string;
}

export async function createCapa(payload: CreateCapaPayload): Promise<Capa> {
    const { data } = await api.post<{ data: Capa }>('/quality/capas', payload);
    return data.data;
}

export interface UpdateCapaPayload {
    root_cause?: string;
    corrective_action?: string;
    preventive_action?: string;
    owner?: number;
    due_date?: string;
}

export async function updateCapa(id: number, payload: UpdateCapaPayload): Promise<Capa> {
    const { data } = await api.put<{ data: Capa }>(`/quality/capas/${id}`, payload);
    return data.data;
}

export async function startCapa(id: number): Promise<Capa> {
    const { data } = await api.post<{ data: Capa }>(`/quality/capas/${id}/start`);
    return data.data;
}

export async function closeCapa(id: number, verifiedEffective: boolean): Promise<Capa> {
    const { data } = await api.post<{ data: Capa }>(`/quality/capas/${id}/close`, { verified_effective: verifiedEffective });
    return data.data;
}

export async function listMeasuringInstruments(dueOnly?: boolean): Promise<Paginated<MeasuringInstrument>> {
    const { data } = await api.get<Paginated<MeasuringInstrument>>('/quality/instruments', {
        params: dueOnly ? { due: 1 } : undefined,
    });
    return data;
}

export interface CreateMeasuringInstrumentPayload {
    code: string;
    name: string;
    location?: string;
    calibration_frequency_days: number;
    next_calibration_due: string;
}

export async function createMeasuringInstrument(payload: CreateMeasuringInstrumentPayload): Promise<MeasuringInstrument> {
    const { data } = await api.post<{ data: MeasuringInstrument }>('/quality/instruments', payload);
    return data.data;
}

export interface RecordCalibrationPayload {
    calibrated_date: string;
    certificate_number?: string;
    result: MeasuringInstrument['calibration_records'][number]['result'];
    performed_by?: string;
    notes?: string;
}

export async function recordCalibration(instrumentId: number, payload: RecordCalibrationPayload): Promise<MeasuringInstrument> {
    const { data } = await api.post<{ data: MeasuringInstrument }>(`/quality/instruments/${instrumentId}/calibrations`, payload);
    return data.data;
}

export async function listSpcCharacteristics(itemId?: number): Promise<Paginated<SpcCharacteristic>> {
    const { data } = await api.get<Paginated<SpcCharacteristic>>('/quality/spc-characteristics', {
        params: itemId ? { item_id: itemId } : undefined,
    });
    return data;
}

export interface CreateSpcCharacteristicPayload {
    item_id: number;
    name: string;
    unit_of_measure?: string;
    target_value?: number;
    lower_spec_limit?: number;
    upper_spec_limit?: number;
}

export async function createSpcCharacteristic(payload: CreateSpcCharacteristicPayload): Promise<SpcCharacteristic> {
    const { data } = await api.post<{ data: SpcCharacteristic }>('/quality/spc-characteristics', payload);
    return data.data;
}

export async function listSpcMeasurements(characteristicId: number): Promise<Paginated<SpcMeasurement>> {
    const { data } = await api.get<Paginated<SpcMeasurement>>(`/quality/spc-characteristics/${characteristicId}/measurements`);
    return data;
}

export interface RecordSpcMeasurementPayload {
    value: number;
    measured_at?: string;
    notes?: string;
}

export async function recordSpcMeasurement(
    characteristicId: number,
    payload: RecordSpcMeasurementPayload,
): Promise<SpcMeasurement> {
    const { data } = await api.post<{ data: SpcMeasurement }>(
        `/quality/spc-characteristics/${characteristicId}/measurements`,
        payload,
    );
    return data.data;
}

export async function getSpcChart(characteristicId: number): Promise<SpcChart> {
    const { data } = await api.get<{ data: SpcChart }>(`/quality/spc-characteristics/${characteristicId}/chart`);
    return data.data;
}

// ---------------------------------------------------------------------------
// Batch quality check — the gate between Complete Batch and PM approval.
//
// The paths and field names below are the backend's ACTUAL contract
// (BatchQualityQueueController, BatchQualityCheckController +
// StoreBatchQualityCheckRequest), not a guess. Two things about it are worth
// stating here, because both are easy to get wrong from the frontend side:
//
// 1. THE WRITES LIVE AT A PRODUCTION PATH BUT CARRY QUALITY PERMISSION. The
//    routes are registered outside the production group precisely so a QC
//    checker needs quality.manage and NOT production.manage.
//
// 2. THE QUEUE IS THE SERVER'S. There is no new status — a checked and an
//    unchecked batch are both 'pending' — but /quality/batch-quality-queue
//    asks the database the queue's three questions (pending · completed ·
//    unchecked · not sent back) and cuts the page after them. Reading it
//    needs quality.view AND production.view, exactly what the screen needed
//    when it built the queue from the production list itself; that seam is
//    preserved, not widened, until the owner says otherwise.
// ---------------------------------------------------------------------------

/** POST the check for one entry. Returns the updated batch, net figure and all. */
export const qualityCheckPath = (entryId: number): string =>
    `/production/shift-production-entries/${entryId}/quality-check`;

/**
 * Counts are whole BOTTLES, and the server means it: the request validates
 * `integer`, not `numeric`, so that a decimal — which would mean a client had
 * sent kilograms — is refused rather than quietly booked. The kg the books
 * need is derived server-side from the run's frozen unit weight.
 *
 * The server re-checks `reviewed === ok + rejected` and `rejected <= produced`
 * itself; the drawer enforces both before submit so the operator finds out at
 * the keyboard rather than through a 422.
 */
export interface CreateBatchQualityCheckPayload {
    reviewed_nos: number;
    ok_nos: number;
    rejected_nos: number;
    note?: string;
}

/** The queue row IS a shift production entry — same resource, filtered. */
export type BatchQualityQueueRow = ShiftProductionEntry;

/** What the queue's `meta` carries beside the page, because no row can. */
export interface BatchQualityQueueMeta {
    /**
     * Is the gate switched on at all (PROD_QUALITY_STAGE_ENABLED)? While it
     * is off the queue is EMPTY by contract — the check endpoint refuses,
     * so a queue offering that work would be a queue of refusals.
     */
    stage_enabled: boolean;
    /** Only while the stage is off: how many completed batches are going straight to the Plant Manager. */
    pending_count: number | null;
}

export type BatchQualityQueue = Omit<Paginated<BatchQualityQueueRow>, 'meta'> & {
    meta: Paginated<BatchQualityQueueRow>['meta'] & BatchQualityQueueMeta;
};

export const BATCH_QUALITY_QUEUE_PATH = '/quality/batch-quality-queue';

/**
 * Completed batches still awaiting their quality check — ONE PAGE, oldest
 * first, as the server orders it.
 *
 * This used to walk every page of the production list's `status=pending`,
 * keep the rows whose `quality` block said unchecked-and-gated and whose
 * `correction` block said not-sent-back, and re-sort them oldest first —
 * two hundred waiting batches was two hundred rows in memory, no pager and
 * no search. The three questions are now the server's
 * (ShiftProductionEntryService::whereAwaitingQualityCheck), asked in SQL
 * before the page is cut, so a page is a slice of the queue and the total
 * is the queue's. `q` narrows on the batch number, the product and the
 * machine. Nothing is filtered or sorted here.
 */
export async function listBatchQualityQueue(params: ListParams = {}): Promise<BatchQualityQueue> {
    const { data } = await api.get<BatchQualityQueue>(BATCH_QUALITY_QUEUE_PATH, { params: compactParams(params) });
    return data;
}

export async function createBatchQualityCheck(
    entryId: number,
    payload: CreateBatchQualityCheckPayload,
): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(qualityCheckPath(entryId), payload);
    return data.data;
}

/** The desk's other write: send the batch back to the floor instead of certifying it. */
export const returnToProductionPath = (entryId: number): string =>
    `/production/shift-production-entries/${entryId}/return-to-production`;

/** The reason the backend enforces at 5 characters — mirrored so the box says so first. */
export const RETURN_REASON_MIN_LENGTH = 5;

/**
 * Hand a completed batch back to production for correction.
 *
 * SAME BOUNDARY AS THE CHECK: a production path carrying QUALITY permission,
 * registered outside the production group precisely so this desk needs
 * quality.manage and not production.manage.
 *
 * THE REASON IS THE WHOLE PAYLOAD AND IT IS REQUIRED (min 5 characters,
 * server-enforced). It is the only instruction the supervisor gets — a batch
 * that reappears on the floor with no explanation is a batch that gets
 * re-submitted unchanged.
 *
 * What it undoes depends on whether a check had been recorded: before one,
 * only the batch's place in the queue changes; after one, the rejected bottles
 * go back into finished goods, the scrap weight comes back out, and the
 * figures are restored — the server reverses precisely what its own check
 * booked, and this call carries none of that arithmetic.
 */
export async function returnBatchToProduction(
    entryId: number,
    reason: string,
): Promise<ShiftProductionEntry> {
    const { data } = await api.post<{ data: ShiftProductionEntry }>(returnToProductionPath(entryId), {
        reason,
    });
    return data.data;
}

/**
 * MATERIAL THAT CAME BACK FROM PRODUCTION DAMAGED (DEC-20260901-003).
 *
 * The store marks a return damaged; the server puts it in quality hold rather
 * than into issuable stock, and these three calls are this desk's end of it —
 * see what is waiting, confirm the damage (it becomes Scrap and leaves stock),
 * or release it back to a store because it is not damaged after all.
 *
 * Registered under /quality and gated on quality.manage: the store may mark a
 * line damaged, and only this desk may say what becomes of it.
 */
export interface ReturnedMaterialHold {
    item_id: number;
    item_name: string | null;
    item_sku: string | null;
    uom: string | null;
    item_is_active: boolean;
    quantity: string;
    warehouse_id: number;
}

export async function listReturnedMaterialHolds(): Promise<ReturnedMaterialHold[]> {
    const { data } = await api.get<{ data: ReturnedMaterialHold[] }>('/quality/returned-material-holds');
    return data.data;
}

export interface ReturnedMaterialDispositionLine {
    item_id: number;
    quantity: number;
}

export async function confirmReturnedMaterialDamage(payload: {
    lines: ReturnedMaterialDispositionLine[];
    notes?: string | null;
}): Promise<void> {
    await api.post('/quality/returned-material-holds/confirm-damage', payload);
}

export async function releaseReturnedMaterial(payload: {
    to_warehouse_id: number;
    lines: ReturnedMaterialDispositionLine[];
    notes?: string | null;
}): Promise<void> {
    await api.post('/quality/returned-material-holds/release', payload);
}
