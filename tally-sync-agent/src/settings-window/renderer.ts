import type { AgentConfig } from '../config';

declare global {
    interface Window {
        settingsApi: {
            getConfig: () => Promise<AgentConfig>;
            saveConfig: (config: AgentConfig) => Promise<void>;
        };
    }
}

const fieldIds: (keyof AgentConfig)[] = [
    'cloudApiBaseUrl',
    'cloudApiToken',
    'tallyHost',
    'tallyPort',
    'tallyCompanyName',
    'pollIntervalSeconds',
    'mastersPollIntervalSeconds',
];

function input(id: string): HTMLInputElement {
    return document.getElementById(id) as HTMLInputElement;
}

async function load(): Promise<void> {
    const config = await window.settingsApi.getConfig();
    for (const id of fieldIds) {
        input(id).value = String(config[id] ?? '');
    }
}

async function save(): Promise<void> {
    const statusEl = document.getElementById('status')!;
    const config = {
        cloudApiBaseUrl: input('cloudApiBaseUrl').value.trim(),
        cloudApiToken: input('cloudApiToken').value.trim(),
        tallyHost: input('tallyHost').value.trim() || '127.0.0.1',
        tallyPort: Number(input('tallyPort').value) || 9000,
        tallyCompanyName: input('tallyCompanyName').value.trim(),
        pollIntervalSeconds: Number(input('pollIntervalSeconds').value) || 90,
        mastersPollIntervalSeconds: Number(input('mastersPollIntervalSeconds').value) || 3600,
    };

    try {
        await window.settingsApi.saveConfig(config);
        statusEl.textContent = 'Saved.';
        statusEl.style.color = '#2c8';
    } catch (err) {
        statusEl.textContent = `Could not save: ${err instanceof Error ? err.message : String(err)}`;
        statusEl.style.color = '#d33';
    }
}

document.getElementById('save')!.addEventListener('click', () => void save());
void load();
