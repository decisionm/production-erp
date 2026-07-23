import { app, BrowserWindow, ipcMain } from 'electron';
import { autoUpdater } from 'electron-updater';
import path from 'path';
import { testCloudConnection } from './cloudApi';
import { getConfig, setConfig, type AgentConfig } from './config';
import logger from './logger';
import { runMastersSync, startMastersLoop, stopMastersLoop } from './mastersSync';
import { startSyncLoop, stopSyncLoop } from './sync';
import { exportCompanies } from './tally/masters';
import { createTray, destroyTray } from './tray';

function errorMessage(err: unknown): string {
    if (err && typeof err === 'object' && 'response' in err) {
        const res = (err as { response?: { status?: number; data?: { message?: string } } }).response;
        if (res) return `HTTP ${res.status ?? '?'}${res.data?.message ? ` — ${res.data.message}` : ''}`;
    }
    return err instanceof Error ? err.message : String(err);
}

// Tray-only app — no window on launch, no dock icon on macOS (dev
// convenience only; this ships for Windows). Electron would otherwise quit
// when the last (non-existent) window closes, which is wrong for a
// background agent, so 'window-all-closed' is deliberately not wired to
// app.quit() below.
app.dock?.hide();

let settingsWindow: BrowserWindow | null = null;

function openSettingsWindow(): void {
    if (settingsWindow) {
        settingsWindow.focus();
        return;
    }

    settingsWindow = new BrowserWindow({
        width: 540,
        height: 760,
        resizable: true,
        title: 'Tally Sync Agent — Settings',
        webPreferences: {
            preload: path.join(__dirname, 'settings-window', 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
        },
    });

    void settingsWindow.loadFile(path.join(__dirname, 'settings-window', 'index.html'));
    settingsWindow.on('closed', () => {
        settingsWindow = null;
    });
}

ipcMain.handle('settings:get', (): AgentConfig => getConfig());

// Setup-UI probes. Each returns a plain { ok, ... } result rather than throwing,
// so the renderer can show a friendly status instead of an unhandled rejection.
ipcMain.handle('tally:test', async (_event, host: string, port: number) => {
    try {
        const companies = await exportCompanies({ host, port });
        return { ok: true, companies };
    } catch (err) {
        return { ok: false, error: errorMessage(err) };
    }
});

ipcMain.handle('cloud:test', async (_event, baseUrl: string, token: string) => {
    try {
        await testCloudConnection(baseUrl, token);
        return { ok: true };
    } catch (err) {
        return { ok: false, error: errorMessage(err) };
    }
});

ipcMain.handle('masters:run', async () => {
    try {
        const result = await runMastersSync();
        return { ok: true, result };
    } catch (err) {
        return { ok: false, error: errorMessage(err) };
    }
});

ipcMain.handle('settings:save', (_event, config: AgentConfig) => {
    setConfig(config);
    logger.info('Settings updated', { cloudApiBaseUrl: config.cloudApiBaseUrl, tallyHost: config.tallyHost, tallyPort: config.tallyPort });
    // A changed poll interval or endpoint should take effect immediately,
    // not after a manual restart.
    stopSyncLoop();
    startSyncLoop();
    stopMastersLoop();
    startMastersLoop();
});

app.whenReady().then(() => {
    logger.info('Tally Sync Agent starting', { version: app.getVersion() });

    app.setLoginItemSettings({ openAtLogin: true });

    createTray(openSettingsWindow);
    startSyncLoop();
    startMastersLoop();

    // Auto-update: checks the generic feed (the ERP's /storage/agent/latest.yml,
    // set via package.json build.publish) on launch and every 6h, downloads a
    // newer installer in the background, and installs it on next quit. No-op in
    // dev (no app-update.yml) — the caught warning is expected there.
    autoUpdater.logger = logger;
    autoUpdater.autoDownload = true;
    const checkForUpdates = () =>
        autoUpdater.checkForUpdatesAndNotify().catch((err) => logger.warn('Update check failed', { message: String(err) }));
    void checkForUpdates();
    setInterval(() => void checkForUpdates(), 6 * 60 * 60 * 1000);

    if (!getConfig().cloudApiBaseUrl) {
        // First run, nothing configured yet — open Settings immediately
        // instead of silently sitting in the tray doing nothing.
        openSettingsWindow();
    }
});

app.on('window-all-closed', () => {
    // Intentionally not calling app.quit() here — see the tray-only note above.
});

app.on('before-quit', () => {
    stopSyncLoop();
    stopMastersLoop();
    destroyTray();
});
