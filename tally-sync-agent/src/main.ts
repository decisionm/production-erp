import { app, BrowserWindow, ipcMain } from 'electron';
import { autoUpdater } from 'electron-updater';
import path from 'path';
import { testCloudConnection } from './cloudApi';
import { getConfig, setConfig, type AgentConfig } from './config';
import logger from './logger';
import { runMastersSync } from './mastersSync';
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

// Single-instance lock. Without it, running the installer (or the login
// auto-start) while an older build is still alive in the tray leaves TWO
// agents polling the same queue at once — the exact confusion that made
// v0.1.5 look like it hadn't taken effect (an old process kept answering).
// If another instance already holds the lock, exit immediately.
const hasInstanceLock = app.requestSingleInstanceLock();
if (!hasInstanceLock) {
    app.quit();
}
app.on('second-instance', () => openSettingsWindow());

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

ipcMain.handle('app:version', (): string => app.getVersion());

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
});

app.whenReady().then(() => {
    // Lost the single-instance race — another agent is already running; this
    // process is on its way out, so do nothing.
    if (!hasInstanceLock) {
        return;
    }

    logger.info('Tally Sync Agent starting', { version: app.getVersion() });

    app.setLoginItemSettings({ openAtLogin: true });

    createTray(openSettingsWindow);
    startSyncLoop();
    // NO automatic masters loop since v0.3.4. The factory's rule after the
    // 07/08-Aug corruption scare: Tally is the single source of record and
    // the agent must not read from it on its own — a timer that polled a
    // recovering Tally every few minutes would violate that unprompted.
    // Masters refresh remains available as the operator's deliberate act:
    // the tray's "Pull Masters from Tally" and the Settings test actions.
    // Voucher POSTING (the outbound sync loop above) is unchanged.

    // Auto-update: checks the generic feed (the ERP's /storage/agent/latest.yml,
    // set via package.json build.publish) on launch and every 6h, downloads a
    // newer installer in the background, and installs it on next quit. No-op in
    // dev (no app-update.yml) — the caught warning is expected there.
    autoUpdater.logger = logger;
    autoUpdater.autoDownload = true;
    // This agent is engineered never to quit (tray-only, openAtLogin, no
    // window-all-closed quit), so electron-updater's default
    // "install on next app quit" would stage a downloaded update forever and
    // never apply it — which is why the factory kept running old code. Apply
    // the update as soon as it's downloaded: quitAndInstall relaunches the new
    // build (it auto-starts on login anyway). A brief interruption mid-sync is
    // harmless — the cloud queue is retried.
    autoUpdater.on('update-downloaded', (info) => {
        logger.info('Update downloaded — installing and relaunching', { version: info.version });
        setImmediate(() => autoUpdater.quitAndInstall(true, true));
    });
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
    destroyTray();
});
