import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { AgentToken, TallySettings, TallySyncEntry } from './types';

export async function listTallySyncEntries(): Promise<Paginated<TallySyncEntry>> {
    const { data } = await api.get<Paginated<TallySyncEntry>>('/tally-sync/entries');
    return data;
}

export async function retryTallySyncEntry(id: number): Promise<TallySyncEntry> {
    const { data } = await api.post<{ data: TallySyncEntry }>(`/tally-sync/entries/${id}/retry`);
    return data.data;
}

export async function listAgentTokens(): Promise<Paginated<AgentToken>> {
    const { data } = await api.get<Paginated<AgentToken>>('/tally-sync/agent-tokens');
    return data;
}

export interface CreateAgentTokenResult {
    data: AgentToken;
    plain_text_token: string;
}

export async function createAgentToken(name: string): Promise<CreateAgentTokenResult> {
    const { data } = await api.post<CreateAgentTokenResult>('/tally-sync/agent-tokens', { name });
    return data;
}

export async function revokeAgentToken(id: number): Promise<void> {
    await api.delete(`/tally-sync/agent-tokens/${id}`);
}

export async function getTallySettings(): Promise<TallySettings> {
    const { data } = await api.get<{ data: TallySettings }>('/tally-sync/settings');
    return data.data;
}

export async function updateTallyCompany(company: string | null): Promise<string | null> {
    const { data } = await api.put<{ data: { company: string | null } }>('/tally-sync/settings/company', { company });
    return data.data.company;
}

export async function updateLedgerMappings(
    mappings: Record<string, string | null>,
): Promise<Record<string, string | null>> {
    const { data } = await api.put<{ data: { mappings: Record<string, string | null> } }>(
        '/tally-sync/settings/ledger-mappings',
        { mappings },
    );
    return data.data.mappings;
}
