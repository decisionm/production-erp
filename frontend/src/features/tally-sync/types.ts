export type TallySyncStatus = 'pending' | 'synced' | 'failed';

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
    created_at: string;
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

export interface TallySettings {
    company: string | null;
    companies: string[];
    roles: LedgerRoleOption[];
    mappings: Record<string, string | null>;
    ledgers: string[];
    agent: AgentDownload | null;
}
