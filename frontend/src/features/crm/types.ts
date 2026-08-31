import type { Item } from '@/features/inventory/types';
import type { Customer } from '@/features/sales/types';

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'disqualified' | 'converted';

export type LeadActivityType = 'call' | 'email' | 'meeting' | 'note';

export interface LeadActivity {
    id: number;
    lead_id: number;
    type: LeadActivityType;
    notes: string;
    activity_date: string;
    next_follow_up_date: string | null;
    created_by: string | null;
    created_at: string;
}

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
    latest_activity?: {
        type: LeadActivityType;
        activity_date: string;
        next_follow_up_date: string | null;
    } | null;
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

/**
 * THE CLIENT-OUTSTANDING POSITION, as mirrored out of the factory's Tally.
 *
 * Money arrives as DECIMAL STRINGS, never numbers, and is kept that way all
 * the way to the cell that renders it. These are the amounts somebody chases a
 * client for, and JSON numbers would round them on the way in.
 */

/** Days past due. `no_due_date` is its own answer, never folded into a band. */
export type AgeingBucket = 'current' | 'd1_30' | 'd31_60' | 'd61_90' | 'd90_plus' | 'no_due_date';

export type AgeingTotals = Record<AgeingBucket, string>;

export interface OutstandingBill {
    bill_reference: string | null;
    bill_date: string | null;
    due_date: string | null;
    closing_amount: string;
    opening_amount: string | null;
    /** Null when Tally states no due date — the column reads "—", never 0. */
    days_past_due: number | null;
    days_since_bill: number | null;
    bucket: AgeingBucket;
}

export interface PendingOrderLine {
    order_reference: string | null;
    order_date: string | null;
    due_date: string | null;
    stock_item_name: string | null;
    pending_quantity: string | null;
    quantity_unit: string | null;
    pending_amount: string | null;
    days_overdue: number | null;
}

export interface ClientOutstanding {
    /** Null where no ERP customer has been linked to this Tally ledger yet. */
    customer_id: number | null;
    customer_code: string | null;
    customer_name: string | null;
    party_ledger_name: string;
    party_ledger_guid: string | null;
    is_linked: boolean;
    outstanding_amount: string;
    overdue_amount: string;
    pending_order_amount: string;
    pending_order_count: number;
    /** Pending lines Tally priced no value for — counted, never invented. */
    pending_orders_without_value: number;
    bill_count: number;
    oldest_overdue_days: number | null;
    ageing: AgeingTotals;
    bills: OutstandingBill[];
    pending_orders: PendingOrderLine[];
}

export interface ClientOutstandingTotals {
    clients: number;
    outstanding_amount: string;
    overdue_amount: string;
    pending_order_amount: string;
    bill_count: number;
    pending_order_count: number;
    ageing: AgeingTotals;
}

export interface ClientOutstandingReport {
    /** The date the position was read AS AT — null when nothing has been pulled. */
    as_of: string | null;
    /** When the agent's pull ran, which is a different fact from `as_of`. */
    synced_at: string | null;
    company: string | null;
    clients: ClientOutstanding[];
    totals: ClientOutstandingTotals;
}
