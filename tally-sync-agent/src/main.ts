import { app, BrowserWindow, ipcMain } from 'electron';
import path from 'path';
import { getConfig, setConfig, type AgentConfig } from './config';
import logger from './logger';
import { startSyncLoop, stopSyncLoop } from './sync';
import { createTray, destroyTray } from './tray';

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
        width: 480,
        height: 560,
        resizable: false,
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

ipcMain.handle('settings:save', (_event, config: AgentConfig) => {
    setConfig(config);
    logger.info('Settings updated', { cloudApiBaseUrl: config.cloudApiBaseUrl, tallyHost: config.tallyHost, tallyPort: config.tallyPort });
    // A changed poll interval or endpoint should take effect immediately,
    // not after a manual restart.
    stopSyncLoop();
    startSyncLoop();
});

app.whenReady().then(() => {
    logger.info('Tally Sync Agent starting', { version: app.getVersion() });

    app.setLoginItemSettings({ openAtLogin: true });

    createTray(openSettingsWindow);
    startSyncLoop();

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
