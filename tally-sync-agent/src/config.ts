import Store from 'electron-store';

/**
 * Everything the agent needs to run, entered once via the Settings window
 * and persisted by electron-store in the OS's per-user app-data folder
 * (never in this repo — see .gitignore). cloudApiToken is a Sanctum
 * personal access token scoped to exactly tally-sync:poll and
 * tally-sync:report (see README "Getting a token") — never a full-access
 * token, per docs/archive/TALLY-SYNC-MASTER-PLAN.md §5.
 */
export interface AgentConfig {
    cloudApiBaseUrl: string;
    cloudApiToken: string;
    tallyHost: string;
    tallyPort: number;
    tallyCompanyName: string;
    pollIntervalSeconds: number;
    // Masters (items, groups, godowns, ledgers) change far less often than
    // vouchers, so they pull on their own, slower interval — hourly by default.
    mastersPollIntervalSeconds: number;
    /**
     * The closing date the Stock Summary preview reads, ISO. A stored setting
     * rather than a prompt: the cutover date is a decision the office makes
     * once, and re-typing it per run is how a snapshot ends up dated a day out.
     */
    stockSummaryAsOf: string;
}

const defaults: AgentConfig = {
    // Prefilled with this instance's ERP so the operator only pastes a token.
    cloudApiBaseUrl: 'https://erp.actech.co.in/api/v1',
    cloudApiToken: '',
    tallyHost: '127.0.0.1',
    tallyPort: 9000,
    tallyCompanyName: '',
    pollIntervalSeconds: 90,
    mastersPollIntervalSeconds: 3600,
    stockSummaryAsOf: '2026-08-02',
};

const store = new Store<AgentConfig>({ defaults });

export function getConfig(): AgentConfig {
    return {
        cloudApiBaseUrl: store.get('cloudApiBaseUrl'),
        cloudApiToken: store.get('cloudApiToken'),
        tallyHost: store.get('tallyHost'),
        tallyPort: store.get('tallyPort'),
        tallyCompanyName: store.get('tallyCompanyName'),
        pollIntervalSeconds: store.get('pollIntervalSeconds'),
        stockSummaryAsOf: store.get('stockSummaryAsOf'),
        mastersPollIntervalSeconds: store.get('mastersPollIntervalSeconds'),
    };
}

export function setConfig(next: Partial<AgentConfig>): void {
    for (const [key, value] of Object.entries(next)) {
        store.set(key as keyof AgentConfig, value as never);
    }
}

export function isConfigured(): boolean {
    const cfg = getConfig();
    return cfg.cloudApiBaseUrl.length > 0 && cfg.cloudApiToken.length > 0 && cfg.tallyCompanyName.length > 0;
}

export function configFilePath(): string {
    return store.path;
}
