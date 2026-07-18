import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type {
    BalanceSheet,
    GLAccount,
    JournalEntry,
    ProfitAndLoss,
    Receivable,
    TrialBalanceRow,
} from './types';

export async function listGLAccounts(): Promise<Paginated<GLAccount>> {
    const { data } = await api.get<Paginated<GLAccount>>('/finance/gl-accounts');
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

export async function listJournalEntries(): Promise<Paginated<JournalEntry>> {
    const { data } = await api.get<Paginated<JournalEntry>>('/finance/journal-entries');
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
