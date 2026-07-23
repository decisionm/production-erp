import { Tray, Menu, nativeImage, shell, app } from 'electron';
import path from 'path';
import { getStatus, runSyncCycle, setPaused } from './sync';
import { runMastersSync } from './mastersSync';
import { logFilePath } from './logger';
import { isConfigured } from './config';

let tray: Tray | null = null;

function statusLabel(): string {
    const status = getStatus();
    if (!isConfigured()) return '⚠ Not configured — open Settings';
    if (status.running) return 'Syncing…';
    if (status.paused) return '⏸ Paused';
    if (status.lastError) return `⚠ Last attempt failed: ${status.lastError.slice(0, 60)}`;
    if (!status.lastRunAt) return 'Starting…';
    const secondsAgo = Math.round((Date.now() - status.lastRunAt.getTime()) / 1000);
    return `✓ Synced ${status.lastSyncedCount} entr${status.lastSyncedCount === 1 ? 'y' : 'ies'} — ${secondsAgo}s ago`;
}

function buildMenu(onOpenSettings: () => void): Menu {
    const status = getStatus();

    return Menu.buildFromTemplate([
        { label: statusLabel(), enabled: false },
        { type: 'separator' },
        {
            label: 'Sync Now',
            enabled: !status.running,
            click: () => void runSyncCycle().then(refresh),
        },
        {
            label: 'Pull Masters from Tally',
            enabled: isConfigured(),
            click: () => void runMastersSync().then(refresh).catch(refresh),
        },
        {
            label: status.paused ? 'Resume' : 'Pause',
            click: () => {
                setPaused(!status.paused);
                refresh();
            },
        },
        { type: 'separator' },
        { label: 'View Logs', click: () => void shell.openPath(logFilePath()) },
        { label: 'Settings…', click: onOpenSettings },
        { type: 'separator' },
        { label: 'Quit', click: () => app.quit() },
    ]);
}

let refreshHandle: ReturnType<typeof setInterval> | null = null;
let openSettingsCallback: () => void = () => {};

function refresh(): void {
    if (!tray) return;
    tray.setContextMenu(buildMenu(openSettingsCallback));
    tray.setToolTip(`Tally Sync Agent — ${statusLabel()}`);
}

export function createTray(onOpenSettings: () => void): Tray {
    openSettingsCallback = onOpenSettings;

    const iconPath = path.join(__dirname, '..', 'assets', 'icon.png');
    const icon = nativeImage.createFromPath(iconPath).resize({ width: 16, height: 16 });
    tray = new Tray(icon);
    tray.setToolTip('Tally Sync Agent');
    refresh();

    // Menu items like "last synced Ns ago" go stale otherwise — cheap to
    // just rebuild periodically rather than trying to patch Electron's
    // (non-reactive) Menu objects in place.
    refreshHandle = setInterval(refresh, 15_000);

    return tray;
}

export function destroyTray(): void {
    if (refreshHandle) clearInterval(refreshHandle);
    tray?.destroy();
    tray = null;
}
