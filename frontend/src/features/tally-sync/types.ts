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
    /**
     * When the agent last collected this voucher (TallySyncEntryResource has
     * always sent it). Set but not synced means the agent has it and has not
     * reported back — the signal that separates "the factory machine is off"
     * from "Tally rejected it".
     */
    delivered_at: string | null;
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
