import axios, { type AxiosInstance } from 'axios';
import { getConfig } from './config';
import type { SnapshotBody } from './snapshot';
import type { MastersPayload } from './tally/masters';
import type { PurchaseRateLine } from './tally/purchaseRates';
import type { StockSummaryPayload } from './tally/stockSummary';

/**
 * Matches App\Modules\TallySync\Http\Controllers\TallySyncAgentController
 * exactly: GET /pending, POST /entries/{id}/ack, POST /entries/{id}/fail,
 * POST /entries/{id}/snapshot. Auth is a Sanctum bearer token scoped to
 * tally-sync:poll (for pending) and tally-sync:report (for ack/fail/snapshot)
 * — see README for how to issue one.
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
    /**
     * When the cloud first handed this entry to an agent, as of THIS poll —
     * null the first time, set on every re-poll (TallySyncService::pending()
     * reads the rows before it stamps them). A non-null value therefore
     * means "you have been given this voucher before", which is the line
     * sync.ts will not cross: it may have been posted to Tally already, so
     * the voucher is never rebuilt for it. A dashboard Retry clears the
     * stamp, and that is what re-authorises a post.
     */
    delivered_at: string | null;
    created_at: string;
    /**
     * sha256 the cloud stamps over the payload it handed us (Phase 4). Echoed
     * back untouched on the snapshot so the cloud can say whether the XML was
     * built from the payload it holds NOW or from an earlier one. Optional:
     * an older cloud does not send it, and this agent never computes it.
     */
    payload_hash?: string;
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

/**
 * Upload the post-Tally snapshot — {xml, sha256, what Tally answered} — for
 * one entry (Phase 4). POST /entries/{id}/snapshot, tally-sync:report ability.
 *
 * A RECORD, not a report: it runs after ack/fail and its failure changes
 * nothing about the entry — sync.ts wraps it so it can never throw into the
 * loop. The body is built by snapshot.ts (raw capped at 64 KB, xml omitted
 * over 2 MB with the hash still sent). The 15 s client timeout applies.
 */
export async function uploadSnapshot(entryId: number, body: SnapshotBody): Promise<void> {
    await client().post(`/entries/${entryId}/snapshot`, body);
}

export type MastersSyncSummary = Record<string, { created: number; updated: number; total: number }>;

/**
 * Push the masters pulled from Tally up to the cloud (inbound direction).
 * Matches App\Modules\TallySync\Http\Controllers\TallySyncAgentController::masters
 * — POST /tally-sync/masters, requiring a token with the tally-sync:masters ability.
 */
export async function syncMasters(payload: MastersPayload, company: string): Promise<MastersSyncSummary> {
    // `company` binds the pull to one Tally company on the cloud side — the
    // server refuses masters from a different company than the instance is
    // bound to, preventing cross-company data corruption.
    const { data } = await client().post<{ data: MastersSyncSummary }>('/masters', { ...payload, company });
    return data.data;
}

export interface PurchaseRatesSyncSummary {
    created: number;
    updated: number;
    deleted: number;
    total: number;
}

/**
 * Push the purchase-order and purchase-invoice RATE LINES read out of the Day
 * Book (inbound direction). POST /tally-sync/purchase-rates, requiring the
 * same tally-sync:masters ability the masters pull needs.
 *
 * The cloud writes one table that nothing posts from — no voucher, no stock,
 * no master. `company` is guarded there exactly as it is for masters, so a
 * misconfigured agent cannot quote one company's rates against another's
 * vendors.
 */
export async function syncPurchaseRates(lines: PurchaseRateLine[], company: string): Promise<PurchaseRatesSyncSummary> {
    const { data } = await client().post<{ data: PurchaseRatesSyncSummary }>('/purchase-rates', { lines, company });
    return data.data;
}

export interface StockSummaryPreview {
    company: string;
    as_of: string;
    totals: { lines: number; mapped: number; unmapped: number; foreign_godown: number };
    lines: Array<Record<string, unknown>>;
}

/**
 * Send a Stock Summary to the cloud for PREVIEW ONLY.
 *
 * Writes nothing on either side: the server matches each line to an ERP item by
 * Tally GUID, reports what it found, and returns. Importing it as opening stock
 * is a separate, explicitly-approved call — deliberately not something a sync
 * loop can trigger, because an opening balance posted twice is not a mistake
 * anyone spots by looking at a screen.
 */
export async function previewStockSummary(payload: StockSummaryPayload): Promise<StockSummaryPreview> {
    const { data } = await client().post<{ data: StockSummaryPreview }>('/stock-summary/preview', payload);
    return data.data;
}

/** Report the companies found in the local Tally so Settings can offer them. */
export async function reportCompanies(companies: string[]): Promise<string[]> {
    const { data } = await client().post<{ data: { companies: string[] } }>('/companies', { companies });
    return data.data.companies;
}

/**
 * Connectivity probe for the setup UI. POSTs an EMPTY masters payload — a no-op
 * upsert that still requires the tally-sync:masters ability — so the test
 * validates both reachability AND that the token can actually do the masters
 * pull (catching the "token missing the masters ability" case here, up front,
 * instead of only at the bidirectional sync test). Uses the passed-in values,
 * not saved config. Throws on failure.
 */
export async function testCloudConnection(baseUrl: string, token: string): Promise<void> {
    const url = `${baseUrl.replace(/\/$/, '')}/tally-sync/masters`;
    await axios.post(url, {}, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        timeout: 15000,
    });
}
