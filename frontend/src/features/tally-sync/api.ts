import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { TallySyncEntry } from './types';

export async function listTallySyncEntries(): Promise<Paginated<TallySyncEntry>> {
    const { data } = await api.get<Paginated<TallySyncEntry>>('/tally-sync/entries');
    return data;
}

export async function retryTallySyncEntry(id: number): Promise<TallySyncEntry> {
    const { data } = await api.post<{ data: TallySyncEntry }>(`/tally-sync/entries/${id}/retry`);
    return data.data;
}
