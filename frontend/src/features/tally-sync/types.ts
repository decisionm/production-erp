export type TallySyncStatus = 'pending' | 'synced' | 'failed' | 'dismissed';

export interface TallySyncEntry {
    id: number;
    syncable_type: string;
    syncable_id: number;
    tally_voucher_type: string;
    payload: Record<string, unknown>;
    status: TallySyncStatus;
    attempts: number;
    error_message: string | null;
    synced_at: string | null;
    /**
     * When the agent last collected this voucher (TallySyncEntryResource has
     * always sent it). Set but not synced means the agent has it and has not
     * reported back — the signal that separates "the factory machine is off"
     * from "Tally rejected it".
     */
    delivered_at: string | null;
    /** When the accountant pressed "Release now" on a held shift voucher. */
    released_at: string | null;
    /**
     * Why a pending shift voucher is not with the agent yet — null once it
     * is deliverable, and always null for batch vouchers, which are never
     * held. 'collecting' = the shift is still running; 'quiet-period' = the
     * shift ended but an entry merged in less than the idle-hold ago.
     */
    hold?: {
        phase: 'collecting' | 'quiet-period';
        shift_ends_at: string | null;
        last_merged_at: string;
        releasable_at: string;
    } | null;
    created_at: string;
    /**
     * The voucher's whole story after a failure, in order: each retry
     * records the previous error and the regeneration; a dismissal records
     * the write-off ("will never be sent to Tally").
     */
    resolution_log?: { at: string; by: number | null; previous_error?: string | null; note: string }[];
    /** The exact place a recognised Tally refusal is fixed; null for unknown errors. */
    fix?: { sentence: string; path: string } | null;
}

export interface AgentToken {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string;
}

export interface LedgerRoleOption {
    value: string;
    label: string;
}

export interface AgentDownload {
    url: string;
    version: string | null;
    built_at: string | null;
    size: number;
}

export interface LedgerOption {
    name: string;
    group: string;
}

export interface TallySettings {
    company: string | null;
    companies: string[];
    roles: LedgerRoleOption[];
    mappings: Record<string, string | null>;
    ledgers: LedgerOption[];
    agent: AgentDownload | null;
}
