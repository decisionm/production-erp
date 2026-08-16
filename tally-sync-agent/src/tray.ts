import { Tray, Menu, nativeImage, shell, app } from 'electron';
import path from 'path';
import { getStatus, runSyncCycle, setPaused } from './sync';
import { runMastersSync } from './mastersSync';
import { getStockReadStatus } from './stockSummarySync';
import { getConfig } from './config';
import { logFilePath } from './logger';
import { isConfigured } from './config';

let tray: Tray | null = null;

function statusLabel(): string {
    const status = getStatus();
    if (!isConfigured()) return '⚠ Not configured — open Settings';
    // The stock read narrates itself — the operator must see movement, not a
    // silent "keep on loading" that invites a second click.
    const stockRead = getStockReadStatus();
    if (stockRead.running) return `⏳ Reading Stock Summary: ${stockRead.progress ?? 'starting…'}`;
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
            label: 'Sync Vouchers Now',
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
        // The "Read Stock Summary (preview only)" item that lived here is
        // REMOVED in v0.3.3, not hidden. Every variant of the read crashed or
        // wedged the live TallyPrime on 07-Aug-2026 (one-shot v0.2.0, chunked
        // v0.3.0 twice, probed-canary v0.3.1) and the factory then reported
        // company-data corruption — a force-killed Tally mid-write is the
        // likely mechanism. The operators must open this menu for the routine
        // actions above, so a dangerous item beside them WILL eventually be
        // clicked; policy is not a guard. The whole read pipeline
        // (stockSummarySync, probes, blacklist) is kept intact for a future
        // release that re-adds the trigger once a safe read is proven against
        // the factory's own Tally.
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
