import fs from 'node:fs';
import path from 'node:path';

/**
 * The agent's own version, read from package.json.
 *
 * WHY NOT app.getVersion(). Electron's `app` is exactly what tests cannot
 * load (requiring it downloads an Electron binary), and the version is stamped
 * on every snapshot the agent uploads — a value the test suite must be able
 * to see. package.json is the single source app.getVersion() reads anyway
 * (electron-builder ships it inside app.asar next to dist/, and fs reads
 * through asar transparently), so this returns the same string in the
 * packaged app, in `npm run dev`, and under node:test.
 *
 * Never throws: an unreadable package.json yields null and the snapshot goes
 * up unstamped rather than not at all.
 */
let cached: string | null | undefined;

export function agentVersion(): string | null {
    if (cached !== undefined) {
        return cached;
    }

    try {
        const raw = fs.readFileSync(path.join(__dirname, '..', 'package.json'), 'utf8');
        const parsed = JSON.parse(raw) as { version?: unknown };
        cached = typeof parsed.version === 'string' && parsed.version.length > 0 ? parsed.version : null;
    } catch {
        cached = null;
    }

    return cached;
}
