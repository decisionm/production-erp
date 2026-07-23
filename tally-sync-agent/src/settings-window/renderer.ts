import type { AgentConfig } from '../config';
import type { MastersRunUiResult, TallyTestResult, TestResult } from './preload';

declare global {
    interface Window {
        settingsApi: {
            getConfig: () => Promise<AgentConfig>;
            saveConfig: (config: AgentConfig) => Promise<void>;
            testTally: (host: string, port: number) => Promise<TallyTestResult>;
            testCloud: (baseUrl: string, token: string) => Promise<TestResult>;
            runMasters: () => Promise<MastersRunUiResult>;
        };
    }
}

const el = (id: string): HTMLElement => document.getElementById(id) as HTMLElement;
const input = (id: string): HTMLInputElement => document.getElementById(id) as HTMLInputElement;
const select = (id: string): HTMLSelectElement => document.getElementById(id) as HTMLSelectElement;

function setStatus(id: string, text: string, kind: 'ok' | 'err' | 'pending' | '' = ''): void {
    const node = el(id);
    node.textContent = text;
    node.className = 'status' + (kind ? ` ${kind}` : '');
}

function fillCompanyOptions(companies: string[], selected: string): void {
    const sel = select('tallyCompanyName');
    sel.innerHTML = '';
    if (companies.length === 0) {
        sel.appendChild(new Option('— no companies found —', ''));
        return;
    }
    for (const company of companies) sel.appendChild(new Option(company, company));
    if (selected && companies.includes(selected)) sel.value = selected;
}

function currentConfig(): AgentConfig {
    return {
        cloudApiBaseUrl: input('cloudApiBaseUrl').value.trim(),
        cloudApiToken: input('cloudApiToken').value.trim(),
        tallyHost: input('tallyHost').value.trim() || '127.0.0.1',
        tallyPort: Number(input('tallyPort').value) || 9000,
        tallyCompanyName: select('tallyCompanyName').value,
        pollIntervalSeconds: Number(input('pollIntervalSeconds').value) || 90,
        mastersPollIntervalSeconds: Number(input('mastersPollIntervalSeconds').value) || 3600,
    };
}

async function withBusy(button: HTMLButtonElement, fn: () => Promise<void>): Promise<void> {
    button.disabled = true;
    try {
        await fn();
    } finally {
        button.disabled = false;
    }
}

async function load(): Promise<void> {
    const cfg = await window.settingsApi.getConfig();
    input('tallyHost').value = cfg.tallyHost || '127.0.0.1';
    input('tallyPort').value = String(cfg.tallyPort || 9000);
    input('cloudApiBaseUrl').value = cfg.cloudApiBaseUrl || '';
    input('cloudApiToken').value = cfg.cloudApiToken || '';
    input('pollIntervalSeconds').value = String(cfg.pollIntervalSeconds || 90);
    input('mastersPollIntervalSeconds').value = String(cfg.mastersPollIntervalSeconds || 3600);

    // Seed the dropdown with the saved company so it shows before any test.
    if (cfg.tallyCompanyName) fillCompanyOptions([cfg.tallyCompanyName], cfg.tallyCompanyName);
}

el('testTally').addEventListener('click', () => void withBusy(el('testTally') as HTMLButtonElement, async () => {
    setStatus('tallyStatus', 'Connecting…', 'pending');
    const host = input('tallyHost').value.trim() || '127.0.0.1';
    const port = Number(input('tallyPort').value) || 9000;
    const res = await window.settingsApi.testTally(host, port);
    if (res.ok) {
        const companies = res.companies ?? [];
        fillCompanyOptions(companies, select('tallyCompanyName').value);
        setStatus('tallyStatus', `Connected — ${companies.length} compan${companies.length === 1 ? 'y' : 'ies'} found`, 'ok');
    } else {
        setStatus('tallyStatus', `Could not connect: ${res.error}`, 'err');
    }
}));

el('testCloud').addEventListener('click', () => void withBusy(el('testCloud') as HTMLButtonElement, async () => {
    setStatus('cloudStatus', 'Connecting…', 'pending');
    const res = await window.settingsApi.testCloud(input('cloudApiBaseUrl').value.trim(), input('cloudApiToken').value.trim());
    setStatus('cloudStatus', res.ok ? 'Connected — token accepted' : `Could not connect: ${res.error}`, res.ok ? 'ok' : 'err');
}));

el('save').addEventListener('click', () => void withBusy(el('save') as HTMLButtonElement, async () => {
    setStatus('saveStatus', 'Saving…', 'pending');
    try {
        await window.settingsApi.saveConfig(currentConfig());
        setStatus('saveStatus', 'Saved', 'ok');
    } catch (err) {
        setStatus('saveStatus', `Could not save: ${err instanceof Error ? err.message : String(err)}`, 'err');
    }
}));

el('runMasters').addEventListener('click', () => void withBusy(el('runMasters') as HTMLButtonElement, async () => {
    setStatus('mastersStatus', 'Pulling from Tally and posting to the ERP…', 'pending');
    el('mastersResult').innerHTML = '';
    const res = await window.settingsApi.runMasters();
    if (!res.ok) {
        setStatus('mastersStatus', `Failed: ${res.error}`, 'err');
        return;
    }
    if (!res.result) {
        setStatus('mastersStatus', 'Skipped — save your settings (company, URL, token) first.', 'err');
        return;
    }
    setStatus('mastersStatus', 'Success — both directions working', 'ok');
    const { pulled, posted } = res.result;
    const rows = Object.keys(posted)
        .map((key) => `<tr><td>${key}</td><td>${pulled[key] ?? posted[key].total}</td><td>${posted[key].created}</td><td>${posted[key].updated}</td></tr>`)
        .join('');
    el('mastersResult').innerHTML =
        `<table><thead><tr><th>Master</th><th>Pulled</th><th>Created</th><th>Updated</th></tr></thead><tbody>${rows}</tbody></table>`;
}));

void load();
