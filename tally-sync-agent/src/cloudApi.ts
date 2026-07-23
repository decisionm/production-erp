import axios, { type AxiosInstance } from 'axios';
import { getConfig } from './config';
import type { MastersPayload } from './tally/masters';

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

export type MastersSyncSummary = Record<string, { created: number; updated: number; total: number }>;

/**
 * Push the masters pulled from Tally up to the cloud (inbound direction).
 * Matches App\Modules\TallySync\Http\Controllers\TallySyncAgentController::masters
 * — POST /tally-sync/masters, requiring a token with the tally-sync:masters ability.
 */
export async function syncMasters(payload: MastersPayload): Promise<MastersSyncSummary> {
    const { data } = await client().post<{ data: MastersSyncSummary }>('/masters', payload);
    return data.data;
}

/** Report the companies found in the local Tally so Settings can offer them. */
export async function reportCompanies(companies: string[]): Promise<string[]> {
    const { data } = await client().post<{ data: { companies: string[] } }>('/companies', { companies });
    return data.data.companies;
}

/**
 * Connectivity probe for the setup UI — checks the given cloud URL + token can
 * reach the API (uses the pending-vouchers endpoint as a cheap authenticated
 * GET). Uses the passed-in values, not saved config, so it can validate what
 * the user just typed. Throws on failure.
 */
export async function testCloudConnection(baseUrl: string, token: string): Promise<void> {
    const url = `${baseUrl.replace(/\/$/, '')}/tally-sync/pending`;
    await axios.get(url, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        timeout: 15000,
    });
}
