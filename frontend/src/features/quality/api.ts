import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { ShiftProductionEntry } from '@/features/production/types';
import type { Capa, IncomingInspection, MeasuringInstrument, NonConformanceReport, PendingInspectionLine, SpcChart, SpcCharacteristic, SpcMeasurement } from './types';
import type { CreateIncomingInspectionPayload } from './incomingInspection';

export const INCOMING_INSPECTIONS_QUERY_KEY = ['quality', 'incoming-inspections'] as const;

/** The pending queue's key — exported so the mutation can invalidate the very key the query uses. */
export const PENDING_INSPECTION_LINES_QUERY_KEY = ['quality', 'incoming-inspection-pending-lines'] as const;

export async function listIncomingInspections(): Promise<Paginated<IncomingInspection>> {
    const { data } = await api.get<Paginated<IncomingInspection>>('/quality/incoming-inspections');
    return data;
}

/**
 * EVERY arrival line still waiting for an inspection, oldest first.
 *
 * NOT PAGINATED, AND THAT IS THE POINT. This picker used to be fed from
 * `listGoodsReceipts()` — page one of twenty goods receipts, under
 * `module:procurement`, which a quality-only login is refused outright. The
 * server now answers the question directly and answers all of it; the
 * response is a bare `{ data: [...] }` with no `meta`, so there is no page
 * size here that could quietly start truncating the day a 21st line arrives.
 */
export async function listPendingIncomingInspectionLines(): Promise<PendingInspectionLine[]> {
    const { data } = await api.get<{ data: PendingInspectionLine[] }>('/quality/incoming-inspections/pending');
    return data.data ?? [];
}

export type { CreateIncomingInspectionPayload } from './incomingInspection';

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
// (BatchQualityCheckController + StoreBatchQualityCheckRequest), not a guess.
// Two things about it are worth stating here, because both are easy to get
// wrong from the frontend side:
//
// 1. THE WRITE LIVES AT A PRODUCTION PATH BUT CARRIES QUALITY PERMISSION. The
//    route is registered outside the production group precisely so a QC
//    checker needs quality.manage and NOT production.manage.
//
// 2. THERE IS NO DEDICATED QUEUE ENDPOINT, AND NO NEW STATUS. A checked and an
//    unchecked batch are both status 'pending'; the queue is the ordinary
//    approval list filtered on `quality.checked`. That list is production-
//    gated, which is the one seam in this design — see listBatchQualityQueue.
// ---------------------------------------------------------------------------

/** POST the check for one entry. Returns the updated batch, net figure and all. */
export const qualityCheckPath = (entryId: number): string =>
    `/production/shift-production-entries/${entryId}/quality-check`;

/** The list the queue is derived from. Requires production.view. */
const PENDING_ENTRIES_PATH = '/production/shift-production-entries';

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

export interface BatchQualityQueue {
    /** Unchecked, gate-on batches — what the desk actually works. */
    rows: BatchQualityQueueRow[];
    /**
     * Does the gate apply at all? False when pending batches exist but none
     * carries `stage_enabled` — i.e. the factory has stood the stage down.
     * Null when there were no pending batches to tell either way.
     */
    stageEnabled: boolean | null;
    /** Pending batches examined, gate or no gate. Distinguishes "none at all". */
    pendingCount: number;
}

/**
 * Completed batches still awaiting their quality check.
 *
 * WHY THIS PAGES RATHER THAN TAKING THE FIRST RESPONSE. The list is paginated
 * at a fixed 20 and ordered NEWEST first server-side, while this queue is
 * worked OLDEST first — so reading only page 1 would show the twenty most
 * recent batches and hide exactly the ones that have been waiting longest.
 * The page walks to the end and sorts afterwards. `meta.last_page` bounds the
 * loop, with a hard cap as a second bound so a malformed meta cannot spin.
 *
 * Unchecked-only is decided HERE rather than by a query param because the
 * backend has no such filter: `quality.checked` is the only thing separating a
 * queued batch from a done one, both being status 'pending'.
 *
 * THE `stage_enabled` HALF OF THE FILTER IS LOAD-BEARING, not tidiness. With
 * the stage stood down (PROD_QUALITY_STAGE_ENABLED=false) every pending batch
 * still reports `checked: false`, so filtering on that alone would fill this
 * queue with batches nobody is meant to check — every one of which would be
 * refused on submit, since recordQualityCheck() turns away a check outright
 * while the stage is off. With the stage off the queue must be empty, which is
 * what "the chain is exactly what it was before this stage existed" means. The
 * server guard is the real one; this filter is what stops the screen offering
 * work that cannot be done.
 */
export async function listBatchQualityQueue(): Promise<BatchQualityQueue> {
    const all: BatchQualityQueueRow[] = [];
    let page = 1;
    let lastPage = 1;

    do {
        const { data } = await api.get<Paginated<BatchQualityQueueRow>>(PENDING_ENTRIES_PATH, {
            params: { status: 'pending', page },
        });
        all.push(...(data?.data ?? []));
        lastPage = data?.meta?.last_page ?? 1;
        page += 1;
    } while (page <= lastPage && page <= 50);

    const gated = all.filter((row) => row.quality?.stage_enabled === true);

    return {
        // `=== false`, not merely falsy: a backend without the gate sends no
        // `quality` block, and those batches are not "awaiting quality" — they
        // are batches from a world where this queue does not exist.
        //
        // AND NOT ONE THIS DESK ALREADY SENT BACK. returnToProduction() clears
        // every quality_* column, on purpose — the batch has to be checked
        // fresh once the floor has corrected it. That leaves it reading
        // `checked: false`, indistinguishable here from a batch waiting for
        // its first check, so without this second test a returned batch would
        // sit in the queue asking to be checked again while production is
        // still fixing it. `awaiting_correction` is the server's own answer to
        // exactly that question (a return exists, no check since, still
        // pending and completed) and it drops out of the queue the moment the
        // floor amends it back.
        rows: gated.filter(
            (row) => row.quality?.checked === false && row.correction?.awaiting_correction !== true,
        ),
        stageEnabled: all.length === 0 ? null : gated.length > 0,
        pendingCount: all.length,
    };
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
