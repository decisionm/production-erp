import type { Item } from '@/features/inventory/types';
import type { Customer } from '@/features/sales/types';

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'disqualified' | 'converted';

export interface Lead {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    company: string | null;
    source: string | null;
    status: LeadStatus;
    notes: string | null;
    assigned_to: string | null;
    converted_customer_id: number | null;
    converted_customer?: Customer;
    created_at: string;
}

export type OpportunityStage = 'prospecting' | 'qualification' | 'proposal' | 'negotiation' | 'won' | 'lost';

export interface Opportunity {
    id: number;
    name: string;
    customer: Customer;
    lead_id: number | null;
    stage: OpportunityStage;
    estimated_value: string;
    probability: string;
    expected_close_date: string | null;
    notes: string | null;
    assigned_to: string | null;
    created_at: string;
}

export type QuotationStatus = 'draft' | 'sent' | 'accepted' | 'rejected' | 'expired';

export interface QuotationLine {
    id: number;
    item: Item;
    quantity: string;
    unit_price: string;
}

export interface Quotation {
    id: number;
    status: QuotationStatus;
    opportunity_id: number;
    customer: Customer;
    quotation_date: string;
    valid_until: string | null;
    notes: string | null;
    lines: QuotationLine[];
    created_at: string;
}
