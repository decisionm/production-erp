export interface GstRate {
    id: number;
    hsn_sac_code: string;
    description: string | null;
    rate_percent: string;
    is_active: boolean;
    created_at: string;
}

export interface GstRegistration {
    id: number;
    gstin: string;
    state_code: string;
    state_name: string;
    is_primary: boolean;
    is_active: boolean;
    created_at: string;
}

export interface GstInvoiceLineBreakdown {
    item_id: number;
    hsn_sac_code: string;
    taxable_value: string;
    rate_percent: string;
    cgst: string;
    sgst: string;
    igst: string;
    total: string;
}

export interface GstInvoiceBreakdown {
    seller_gstin: string;
    seller_state_code: string;
    customer_gstin: string | null;
    customer_state_code: string;
    supply_type: 'intra_state' | 'inter_state';
    lines: GstInvoiceLineBreakdown[];
    totals: {
        taxable_value: string;
        cgst: string;
        sgst: string;
        igst: string;
        total_tax: string;
        grand_total: string;
    };
}

export interface Gstr1Row {
    invoice_id: number;
    invoice_date: string;
    customer_name: string;
    customer_gstin: string | null;
    supply_type: 'intra_state' | 'inter_state';
    taxable_value: string;
    cgst: string;
    sgst: string;
    igst: string;
    total_tax: string;
}

export interface Gstr1Error {
    invoice_id: number;
    message: string;
}

export interface Gstr1Report {
    b2b: Gstr1Row[];
    b2c: Gstr1Row[];
    errors: Gstr1Error[];
    totals: {
        taxable_value: string;
        cgst: string;
        sgst: string;
        igst: string;
        total_tax: string;
    };
}
