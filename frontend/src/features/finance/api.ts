import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { GLAccountListFilters } from './glAccountList';
import type { JournalEntryListFilters } from './journalEntryList';
import type { BalanceSheet, ClientOutstandingImportResult, ClientOutstandingReport, GLAccount, JournalEntry, ProfitAndLoss, Receivable, TrialBalanceRow } from './types';

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

/**
 * FILL THE POSITION FROM A TALLY XML EXPORT, when the agent cannot deliver one.
 *
 * The factory PC's Tally Sync Agent is the normal road and stays the normal
 * road; this is the hand path for the days it is not answering — the owner
 * exports Group Outstandings › Sundry Debtors › Pending Bills from Tally and
 * uploads the file here.
 *
 * The XML IS NOT PARSED IN THE BROWSER. The whole file goes to the server as
 * `file`, multipart, exactly as `attachSupplierBillFile` does, and the server
 * is the only thing that reads Tally's shape — the ERP has one reader for that
 * export and this must not become a second one that drifts from it.
 */
export async function importClientOutstanding(file: File, asOf: string = todayInFactoryTime()): Promise<ClientOutstandingImportResult> {
    const form = new FormData();
    form.append('file', file);
    // REQUIRED BY THE SERVER, AND NOT DEFAULTED THERE. `as_of` is the date the
    // position was read as at, and it is what the page prints as "position as
    // at". Defaulting it server-side would let any stale file be filed as
    // current with nothing recording that choice; supplying it here makes the
    // caller state it.
    //
    // Today, matching the agent. receivablesSync.ts stamps `asOf = today()` at
    // the moment of the pull rather than reading a period out of the report, so
    // an imported position and a pulled one mean the same thing by the same
    // rule. An export taken on an earlier day is therefore labelled the day it
    // was imported — the same way a pull run this morning is labelled today.
    form.append('as_of', asOf);
    const { data } = await api.post<{ data: ClientOutstandingImportResult }>('/finance/client-outstanding/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data.data;
}

/**
 * Today as the factory reckons it.
 *
 * Built from the LOCAL calendar parts, never `toISOString()`: that converts to
 * UTC first, so any evening in IST becomes the previous day's date and a
 * position imported after 5:30pm would be filed as yesterday's.
 */
function todayInFactoryTime(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
