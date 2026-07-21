import { contextBridge, ipcRenderer } from 'electron';
import type { AgentConfig } from '../config';

/**
 * Renderer processes never get direct Node/Electron API access (contextIsolation
 * stays on) — this is the only bridge, and it exposes exactly two operations,
 * nothing broader.
 */
contextBridge.exposeInMainWorld('settingsApi', {
    getConfig: (): Promise<AgentConfig> => ipcRenderer.invoke('settings:get'),
    saveConfig: (config: AgentConfig): Promise<void> => ipcRenderer.invoke('settings:save', config),
});
