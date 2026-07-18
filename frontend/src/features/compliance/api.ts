import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { GstInvoiceBreakdown, Gstr1Report, GstRate, GstRegistration } from './types';

export async function listGstRates(): Promise<Paginated<GstRate>> {
    const { data } = await api.get<Paginated<GstRate>>('/compliance/gst-rates');
    return data;
}

export interface CreateGstRatePayload {
    hsn_sac_code: string;
    description?: string;
    rate_percent: number;
}

export async function createGstRate(payload: CreateGstRatePayload): Promise<GstRate> {
    const { data } = await api.post<{ data: GstRate }>('/compliance/gst-rates', payload);
    return data.data;
}

export async function listGstRegistrations(): Promise<Paginated<GstRegistration>> {
    const { data } = await api.get<Paginated<GstRegistration>>('/compliance/gst-registrations');
    return data;
}

export interface CreateGstRegistrationPayload {
    gstin: string;
    state_code: string;
    state_name: string;
    is_primary?: boolean;
}

export async function createGstRegistration(payload: CreateGstRegistrationPayload): Promise<GstRegistration> {
    const { data } = await api.post<{ data: GstRegistration }>('/compliance/gst-registrations', payload);
    return data.data;
}

export async function getInvoiceGstBreakdown(invoiceId: number): Promise<GstInvoiceBreakdown> {
    const { data } = await api.get<{ data: GstInvoiceBreakdown }>(`/compliance/invoices/${invoiceId}/gst-breakdown`);
    return data.data;
}

export async function getGstr1Report(): Promise<Gstr1Report> {
    const { data } = await api.get<{ data: Gstr1Report }>('/compliance/reports/gstr1');
    return data.data;
}
