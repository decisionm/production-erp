import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { GLAccountListFilters } from './glAccountList';
import type { JournalEntryListFilters } from './journalEntryList';
import type { BalanceSheet, ClientOutstandingReport, GLAccount, JournalEntry, ProfitAndLoss, Receivable, TrialBalanceRow } from './types';

/** ONE page of the chart, sorted and paged on the SERVER (ListGlAccountsRequest). No argument is the code-ordered first page. */
export async function listGLAccounts(filters: GLAccountListFilters = {}): Promise<Paginated<GLAccount>> {
    const { data } = await api.get<Paginated<GLAccount>>('/finance/gl-accounts', { params: filters });
    return data;
}

/**
 * Full reference list for a PICKER (all rows, not the default first page).
 * A dropdown that offers active rows only must be given every row to filter,
 * or it hides part of a list that was already truncated.
 */
export async function listAllGLAccounts(): Promise<Paginated<GLAccount>> {
    const { data } = await api.get<Paginated<GLAccount>>('/finance/gl-accounts', { params: { per_page: 1000 } });
    return data;
}

export interface CreateGLAccountPayload {
    code: string;
    name: string;
    type: GLAccount['type'];
}

export async function createGLAccount(payload: CreateGLAccountPayload): Promise<GLAccount> {
    const { data } = await api.post<{ data: GLAccount }>('/finance/gl-accounts', payload);
    return data.data;
}

export type UpdateGLAccountPayload = Partial<CreateGLAccountPayload> & { is_active?: boolean };

export async function updateGLAccount(id: number, payload: UpdateGLAccountPayload): Promise<GLAccount> {
    const { data } = await api.put<{ data: GLAccount }>(`/finance/gl-accounts/${id}`, payload);
    return data.data;
}

/** ONE page of the register, sorted and paged on the SERVER (ListJournalEntriesRequest). No argument is the newest-first first page. */
export async function listJournalEntries(filters: JournalEntryListFilters = {}): Promise<Paginated<JournalEntry>> {
    const { data } = await api.get<Paginated<JournalEntry>>('/finance/journal-entries', { params: filters });
    return data;
}

export interface CreateJournalEntryPayload {
    entry_date: string;
    reference?: string;
    memo?: string;
    lines: { gl_account_id: number; debit: number; credit: number; memo?: string }[];
}

export async function createJournalEntry(payload: CreateJournalEntryPayload): Promise<JournalEntry> {
    const { data } = await api.post<{ data: JournalEntry }>('/finance/journal-entries', payload);
    return data.data;
}

export async function postJournalEntry(id: number): Promise<JournalEntry> {
    const { data } = await api.post<{ data: JournalEntry }>(`/finance/journal-entries/${id}/post`);
    return data.data;
}

export async function getTrialBalance(): Promise<TrialBalanceRow[]> {
    const { data } = await api.get<{ data: TrialBalanceRow[] }>('/finance/reports/trial-balance');
    return data.data;
}

export async function getProfitAndLoss(): Promise<ProfitAndLoss> {
    const { data } = await api.get<{ data: ProfitAndLoss }>('/finance/reports/profit-and-loss');
    return data.data;
}

export async function getBalanceSheet(): Promise<BalanceSheet> {
    const { data } = await api.get<{ data: BalanceSheet }>('/finance/reports/balance-sheet');
    return data.data;
}

export async function getReceivables(): Promise<Receivable[]> {
    const { data } = await api.get<{ data: Receivable[] }>('/finance/reports/receivables');
    return data.data;
}

/**
 * The whole client-outstanding position in one read.
 *
 * NOT PAGINATED, deliberately: the page's header totals and its ageing columns
 * have to agree with the rows underneath them, and a paginated client list
 * would make the header sum a different set from the table.
 */
export async function getClientOutstanding(): Promise<ClientOutstandingReport> {
    const { data } = await api.get<{ data: ClientOutstandingReport }>('/finance/client-outstanding');
    return data.data;
}
