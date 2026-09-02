import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { GstRateListFilters } from './gstRateList';
import type { GstRegistrationListFilters } from './gstRegistrationList';
import type { GstInvoiceBreakdown, Gstr1Report, GstRate, GstRegistration } from './types';

/** ONE page of rates, sorted and paged on the SERVER (ListGstRatesRequest). No argument is the HSN/SAC-ordered first page. */
export async function listGstRates(filters: GstRateListFilters = {}): Promise<Paginated<GstRate>> {
    const { data } = await api.get<Paginated<GstRate>>('/compliance/gst-rates', { params: filters });
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

export type UpdateGstRatePayload = Partial<CreateGstRatePayload> & { is_active?: boolean };

export async function updateGstRate(id: number, payload: UpdateGstRatePayload): Promise<GstRate> {
    const { data } = await api.put<{ data: GstRate }>(`/compliance/gst-rates/${id}`, payload);
    return data.data;
}

/** ONE page of registrations, sorted and paged on the SERVER (ListGstRegistrationsRequest). No argument is the primary-first first page. */
export async function listGstRegistrations(filters: GstRegistrationListFilters = {}): Promise<Paginated<GstRegistration>> {
    const { data } = await api.get<Paginated<GstRegistration>>('/compliance/gst-registrations', { params: filters });
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

export type UpdateGstRegistrationPayload = Partial<CreateGstRegistrationPayload> & { is_active?: boolean };

export async function updateGstRegistration(
    id: number,
    payload: UpdateGstRegistrationPayload,
): Promise<GstRegistration> {
    const { data } = await api.put<{ data: GstRegistration }>(`/compliance/gst-registrations/${id}`, payload);
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
