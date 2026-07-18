import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { IncomingInspection, NonConformanceReport } from './types';

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
