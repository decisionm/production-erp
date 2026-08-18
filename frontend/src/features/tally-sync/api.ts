import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import { buildEntryQuery } from './filters';
import type { AgentToken, TallySettings, TallySyncEntry, TallySyncEntryFilters, TallySyncSummary } from './types';

/**
 * One page of the queue, server-filtered.
 *
 * Declared as an overload pair rather than one signature with optional
 * arguments: the zero-argument form is what lets other features hand this
 * function straight to useQuery as a queryFn (Live Monitor does), where
 * TanStack calls it with its context object as the first argument. That
 * object is a weak-type mismatch for TallySyncEntryFilters at compile time
 * and would be garbage on the wire at run time — the overload keeps the
 * type-check honest, and buildEntryQuery()'s allowlist keeps the URL clean.
 */
export function listTallySyncEntries(): Promise<Paginated<TallySyncEntry>>;
export function listTallySyncEntries(
    filters: TallySyncEntryFilters,
    page?: number,
    perPage?: number,
): Promise<Paginated<TallySyncEntry>>;
export async function listTallySyncEntries(
    filters: TallySyncEntryFilters = {},
    page?: number,
    perPage?: number,
): Promise<Paginated<TallySyncEntry>> {
    const { data } = await api.get<Paginated<TallySyncEntry>>('/tally-sync/entries', {
        params: {
            ...buildEntryQuery(filters),
            ...(page !== undefined ? { page } : {}),
            ...(perPage !== undefined ? { per_page: perPage } : {}),
        },
    });
    return data;
}

/** One entry with its full event history (GET /tally-sync/entries/{id}). */
export async function getTallySyncEntry(id: number): Promise<TallySyncEntry> {
    const { data } = await api.get<{ data: TallySyncEntry }>(`/tally-sync/entries/${id}`);
    return data.data;
}

/**
 * The header numbers: today's and all-time counts, one count per catalogue
 * category (null — never 0 — for anything not mirrored), last agent
 * contact. The server applies the same filters as the list to the counts
 * when given; the page calls this UNFILTERED on purpose, because "today:
 * N failed" is a fact about today, not about whatever the table is
 * narrowed to, and the liveness fields are global either way.
 */
export async function getTallySyncSummary(filters?: TallySyncEntryFilters): Promise<TallySyncSummary> {
    const { data } = await api.get<{ data: TallySyncSummary }>('/tally-sync/summary', {
        params: buildEntryQuery(filters),
    });
    return data.data;
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
 * The whole sync queue (as filtered), not the newest page of it.
 *
 * The dashboard counts failures and wants them at the top, and both of
 * those are lies if it only ever sees page one: a Tally rejection from
 * yesterday would sit off the end of the list and be reported by nobody.
 * So we page through — one request in practice, since 200 covers a long
 * while.
 *
 * Order is the SERVER's `sort=status_rank` (failed → pending → synced →
 * dismissed, newest first within each), asked for by default here so every
 * caller reads the same order — the client no longer re-sorts. `total` is
 * the server's count for the same filters and is returned separately and
 * deliberately: past MAX_ENTRY_PAGES we stop, and the page must be able to
 * notice that it is holding a subset and say so, rather than quietly
 * announcing an all-clear it cannot vouch for.
 *
 * Same overload pair as listTallySyncEntries(), for the same reason: the
 * Dashboard hands this to useQuery as a bare queryFn.
 */
export function listAllTallySyncEntries(): Promise<TallySyncQueue>;
export function listAllTallySyncEntries(filters: TallySyncEntryFilters): Promise<TallySyncQueue>;
export async function listAllTallySyncEntries(filters: TallySyncEntryFilters = {}): Promise<TallySyncQueue> {
    const query: TallySyncEntryFilters = { sort: 'status_rank', ...filters };
    const first = await listTallySyncEntries(query, undefined, ENTRY_PAGE_SIZE);
    const entries = [...first.data];
    const lastPage = Math.min(first.meta.last_page, MAX_ENTRY_PAGES);

    // Sequential, not Promise.all: this runs against the same box the floor
    // is typing into, and the queue is one request deep on any normal day.
    for (let page = 2; page <= lastPage; page += 1) {
        const next = await listTallySyncEntries(query, page, ENTRY_PAGE_SIZE);
        entries.push(...next.data);
    }

    return { entries, total: first.meta.total };
}

export async function retryTallySyncEntry(id: number): Promise<TallySyncEntry> {
    const { data } = await api.post<{ data: TallySyncEntry }>(`/tally-sync/entries/${id}/retry`);
    return data.data;
}

/** Write a dead voucher off — it will never be sent to Tally. 422 unless it is failed and never synced. */
export async function dismissTallySyncEntry(id: number): Promise<TallySyncEntry> {
    const { data } = await api.post<{ data: TallySyncEntry }>(`/tally-sync/entries/${id}/dismiss`);
    return data.data;
}

/** The accountant's "Release now" on a held shift voucher (DEC-20260807-011). */
export async function releaseTallySyncEntry(id: number): Promise<TallySyncEntry> {
    const { data } = await api.post<{ data: TallySyncEntry }>(`/tally-sync/entries/${id}/release`);
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
