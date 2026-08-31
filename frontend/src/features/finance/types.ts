export type GLAccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';

export interface GLAccount {
    id: number;
    code: string;
    name: string;
    type: GLAccountType;
    is_active: boolean;
    created_at: string;
}

export type JournalEntryStatus = 'draft' | 'posted';

export interface JournalEntryLine {
    id: number;
    gl_account: GLAccount;
    debit: string;
    credit: string;
    memo: string | null;
}

export interface JournalEntry {
    id: number;
    status: JournalEntryStatus;
    entry_date: string;
    reference: string | null;
    memo: string | null;
    lines: JournalEntryLine[];
    created_at: string;
}

export interface TrialBalanceRow {
    account_id: number;
    code: string;
    name: string;
    type: GLAccountType;
    total_debit: string;
    total_credit: string;
    balance: string;
}

export interface ProfitAndLossLine {
    account_id: number;
    code: string;
    name: string;
    amount: string;
}

export interface ProfitAndLoss {
    revenue: ProfitAndLossLine[];
    expense: ProfitAndLossLine[];
    total_revenue: string;
    total_expense: string;
    net_income: string;
}

export interface BalanceSheet {
    assets: ProfitAndLossLine[];
    liabilities: ProfitAndLossLine[];
    equity: ProfitAndLossLine[];
    total_assets: string;
    total_liabilities: string;
    total_equity: string;
    net_income: string;
}

export interface Receivable {
    invoice_id: number;
    customer: { id: number; code: string; name: string };
    invoice_date: string;
    due_date: string | null;
    status: string;
    amount: string;
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
