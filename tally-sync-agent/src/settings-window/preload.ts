import { contextBridge, ipcRenderer } from 'electron';
import type { AgentConfig } from '../config';

export interface TestResult {
    ok: boolean;
    error?: string;
}

export interface TallyTestResult extends TestResult {
    companies?: string[];
}

export interface MastersRunUiResult extends TestResult {
    result?: {
        companies: string[];
        pulled: Record<string, number>;
        posted: Record<string, { created: number; updated: number; total: number }>;
    } | null;
}

/**
 * The only bridge into the renderer (contextIsolation stays on). Exposes config
 * read/write plus the setup-UI probes: test the Tally connection (and list its
 * companies), test the cloud API, and run a live bidirectional sync check.
 */
contextBridge.exposeInMainWorld('settingsApi', {
    getConfig: (): Promise<AgentConfig> => ipcRenderer.invoke('settings:get'),
    saveConfig: (config: AgentConfig): Promise<void> => ipcRenderer.invoke('settings:save', config),
    testTally: (host: string, port: number): Promise<TallyTestResult> => ipcRenderer.invoke('tally:test', host, port),
    testCloud: (baseUrl: string, token: string): Promise<TestResult> => ipcRenderer.invoke('cloud:test', baseUrl, token),
    runMasters: (): Promise<MastersRunUiResult> => ipcRenderer.invoke('masters:run'),
});
