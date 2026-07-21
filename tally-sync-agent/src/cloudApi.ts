import axios, { type AxiosInstance } from 'axios';
import { getConfig } from './config';

/**
 * Matches App\Modules\TallySync\Http\Controllers\TallySyncAgentController
 * exactly: GET /pending, POST /entries/{id}/ack, POST /entries/{id}/fail.
 * Auth is a Sanctum bearer token scoped to tally-sync:poll (for pending)
 * and tally-sync:report (for ack/fail) — see README for how to issue one.
 */
export interface TallySyncEntry {
    id: number;
    syncable_type: string;
    syncable_id: number;
    tally_voucher_type: 'Sales' | 'Journal' | string;
    payload: Record<string, unknown>;
    status: 'pending' | 'synced' | 'failed';
    attempts: number;
    error_message: string | null;
    synced_at: string | null;
    created_at: string;
}

function client(): AxiosInstance {
    const cfg = getConfig();
    return axios.create({
        baseURL: `${cfg.cloudApiBaseUrl.replace(/\/$/, '')}/tally-sync`,
        headers: {
            Authorization: `Bearer ${cfg.cloudApiToken}`,
            Accept: 'application/json',
        },
        timeout: 15000,
    });
}

export async function fetchPending(): Promise<TallySyncEntry[]> {
    const { data } = await client().get<{ data: TallySyncEntry[] }>('/pending');
    return data.data;
}

export async function acknowledge(entryId: number): Promise<void> {
    await client().post(`/entries/${entryId}/ack`);
}

export async function reportFailure(entryId: number, errorMessage: string): Promise<void> {
    await client().post(`/entries/${entryId}/fail`, { error_message: errorMessage });
}
