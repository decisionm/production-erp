import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { Capa, IncomingInspection, MeasuringInstrument, NonConformanceReport, SpcChart, SpcCharacteristic, SpcMeasurement } from './types';

export async function listIncomingInspections(): Promise<Paginated<IncomingInspection>> {
    const { data } = await api.get<Paginated<IncomingInspection>>('/quality/incoming-inspections');
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
