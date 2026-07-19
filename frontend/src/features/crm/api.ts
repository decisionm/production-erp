import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { SalesOrder } from '@/features/sales/types';
import type { Lead, LeadActivity, LeadActivityType, LeadStatus, Opportunity, Quotation } from './types';

export async function listLeads(): Promise<Paginated<Lead>> {
    const { data } = await api.get<Paginated<Lead>>('/crm/leads');
    return data;
}

export interface CreateLeadPayload {
    name: string;
    email?: string;
    phone?: string;
    company?: string;
    source?: string;
    notes?: string;
}

export async function createLead(payload: CreateLeadPayload): Promise<Lead> {
    const { data } = await api.post<{ data: Lead }>('/crm/leads', payload);
    return data.data;
}

export async function updateLeadStatus(id: number, status: LeadStatus): Promise<Lead> {
    const { data } = await api.put<{ data: Lead }>(`/crm/leads/${id}`, { status });
    return data.data;
}

export async function updateLeadNotes(id: number, notes: string): Promise<Lead> {
    const { data } = await api.put<{ data: Lead }>(`/crm/leads/${id}`, { notes });
    return data.data;
}

export async function convertLead(id: number, code: string): Promise<Lead> {
    const { data } = await api.post<{ data: Lead }>(`/crm/leads/${id}/convert`, { code });
    return data.data;
}

export async function listLeadActivities(leadId: number): Promise<LeadActivity[]> {
    const { data } = await api.get<{ data: LeadActivity[] }>(`/crm/leads/${leadId}/activities`);
    return data.data;
}

export interface CreateLeadActivityPayload {
    type: LeadActivityType;
    notes: string;
    activity_date?: string;
    next_follow_up_date?: string;
}

export async function createLeadActivity(leadId: number, payload: CreateLeadActivityPayload): Promise<LeadActivity> {
    const { data } = await api.post<{ data: LeadActivity }>(`/crm/leads/${leadId}/activities`, payload);
    return data.data;
}

export async function listOpportunities(): Promise<Paginated<Opportunity>> {
    const { data } = await api.get<Paginated<Opportunity>>('/crm/opportunities');
    return data;
}

export interface CreateOpportunityPayload {
    name: string;
    customer_id: number;
    lead_id?: number;
    estimated_value?: number;
    probability?: number;
    expected_close_date?: string;
    notes?: string;
}

export async function createOpportunity(payload: CreateOpportunityPayload): Promise<Opportunity> {
    const { data } = await api.post<{ data: Opportunity }>('/crm/opportunities', payload);
    return data.data;
}

export async function updateOpportunityStage(id: number, stage: Opportunity['stage']): Promise<Opportunity> {
    const { data } = await api.put<{ data: Opportunity }>(`/crm/opportunities/${id}`, { stage });
    return data.data;
}

export type UpdateOpportunityPayload = Partial<CreateOpportunityPayload>;

export async function updateOpportunity(id: number, payload: UpdateOpportunityPayload): Promise<Opportunity> {
    const { data } = await api.put<{ data: Opportunity }>(`/crm/opportunities/${id}`, payload);
    return data.data;
}

export async function listQuotations(): Promise<Paginated<Quotation>> {
    const { data } = await api.get<Paginated<Quotation>>('/crm/quotations');
    return data;
}

export interface CreateQuotationPayload {
    opportunity_id: number;
    quotation_date: string;
    valid_until?: string;
    notes?: string;
    lines: { item_id: number; quantity: number; unit_price: number }[];
}

export async function createQuotation(payload: CreateQuotationPayload): Promise<Quotation> {
    const { data } = await api.post<{ data: Quotation }>('/crm/quotations', payload);
    return data.data;
}

export async function sendQuotation(id: number): Promise<Quotation> {
    const { data } = await api.post<{ data: Quotation }>(`/crm/quotations/${id}/send`);
    return data.data;
}

export async function acceptQuotation(id: number): Promise<{ quotation: Quotation; sales_order: SalesOrder }> {
    const { data } = await api.post<{ data: { quotation: Quotation; sales_order: SalesOrder } }>(
        `/crm/quotations/${id}/accept`,
    );
    return data.data;
}

export async function rejectQuotation(id: number): Promise<Quotation> {
    const { data } = await api.post<{ data: Quotation }>(`/crm/quotations/${id}/reject`);
    return data.data;
}
