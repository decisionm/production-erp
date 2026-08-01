import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { AgentToken, TallySettings, TallySyncEntry } from './types';

export async function listTallySyncEntries(): Promise<Paginated<TallySyncEntry>> {
    const { data } = await api.get<Paginated<TallySyncEntry>>('/tally-sync/entries');
    return data;
}

/**
 * One explicit page of the queue. Kept separate from listTallySyncEntries()
 * above rather than adding an optional argument to it: that function is
 * handed straight to useQuery as a queryFn in places, which would call it
 * with TanStack's context object and post the whole thing as query params.
 */
async function listTallySyncEntriesPage(params: {
    page?: number
    per_page?: number
}): Promise<Paginated<TallySyncEntry>> {
    const { data } = await api.get<Paginated<TallySyncEntry>>('/tally-sync/entries', { params });
    return data;
}

/** One request's worth of queue, and the ceiling on how many we'll ask for. */
const ENTRY_PAGE_SIZE = 200;
const MAX_ENTRY_PAGES = 20;

export interface TallySyncQueue {
    entries: TallySyncEntry[];
    /** What the server says exists — NOT entries.length. See below. */
    total: number;
}

/**
 * The whole sync queue, not the newest page of it.
 *
 * The dashboard counts failures and sorts them to the top, and both of those
 * are lies if it only ever sees page one: a Tally rejection from yesterday
 * would sit off the end of the list and be reported by nobody. So we page
 * through — one request in practice, since 200 covers a long while.
 *
 * `total` is returned separately and deliberately: past MAX_ENTRY_PAGES we
 * stop, and the page must be able to notice that it is holding a subset and
 * say so, rather than quietly announcing an all-clear it cannot vouch for.
 */
export async function listAllTallySyncEntries(): Promise<TallySyncQueue> {
    const first = await listTallySyncEntriesPage({ per_page: ENTRY_PAGE_SIZE });
    const entries = [...first.data];
    const lastPage = Math.min(first.meta.last_page, MAX_ENTRY_PAGES);

    // Sequential, not Promise.all: this runs against the same box the floor
    // is typing into, and the queue is one request deep on any normal day.
    for (let page = 2; page <= lastPage; page += 1) {
        const next = await listTallySyncEntriesPage({ page, per_page: ENTRY_PAGE_SIZE });
        entries.push(...next.data);
    }

    return { entries, total: first.meta.total };
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
