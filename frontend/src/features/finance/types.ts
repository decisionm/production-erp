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
