/**
 * Light or dark, and how wide the sidebar is — the two display choices a
 * person makes for their own eyes (03-Sep-2026).
 *
 * Both live in `localStorage`, not on the server: they are per-device, and a
 * supervisor's phone and the floor tablet should not fight over one stored
 * value. Every read is guarded — a private window, a cleared store or a
 * browser that refuses site data must not blank the app — and every read
 * returns the default rather than throwing.
 */
export type ThemeMode = 'light' | 'dark';

export const THEME_MODE_KEY = 'swaashpet.theme-mode';
export const SIDER_WIDTH_KEY = 'swaashpet.sider-width';

/** The shipped default: the factory reads these screens in daylight. */
export const DEFAULT_THEME_MODE: ThemeMode = 'light';

/** Narrow enough that the labels still fit; wide enough for the longest one. */
export const SIDER_WIDTH_MIN = 200;
export const SIDER_WIDTH_MAX = 420;
export const SIDER_WIDTH_DEFAULT = 248;

export function isThemeMode(value: unknown): value is ThemeMode {
    return value === 'light' || value === 'dark';
}

/** The other mode — what the toggle switches to. */
export function nextThemeMode(mode: ThemeMode): ThemeMode {
    return mode === 'dark' ? 'light' : 'dark';
}

/** A dragged width, held inside the sidebar's range and rounded to whole pixels. */
export function clampSiderWidth(width: number): number {
    if (!Number.isFinite(width)) return SIDER_WIDTH_DEFAULT;

    return Math.round(Math.min(SIDER_WIDTH_MAX, Math.max(SIDER_WIDTH_MIN, width)));
}

/** What a stored width means: a usable number, or the default. */
export function parseSiderWidth(stored: string | null): number {
    if (stored === null || stored.trim() === '') return SIDER_WIDTH_DEFAULT;
    const parsed = Number(stored);

    return Number.isFinite(parsed) ? clampSiderWidth(parsed) : SIDER_WIDTH_DEFAULT;
}

/** What a stored mode means: one of the two words, or the default. */
export function parseThemeMode(stored: string | null): ThemeMode {
    return isThemeMode(stored) ? stored : DEFAULT_THEME_MODE;
}

function storage(): Storage | null {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        // A browser set to block site data throws on the accessor itself.
        return null;
    }
}

export function readStoredThemeMode(): ThemeMode {
    try {
        return parseThemeMode(storage()?.getItem(THEME_MODE_KEY) ?? null);
    } catch {
        return DEFAULT_THEME_MODE;
    }
}

export function writeStoredThemeMode(mode: ThemeMode): void {
    try {
        storage()?.setItem(THEME_MODE_KEY, mode);
    } catch {
        // Nothing to tell the user: the app works, this browser just will
        // not remember the choice past this tab.
    }
}

export function readStoredSiderWidth(): number {
    try {
        return parseSiderWidth(storage()?.getItem(SIDER_WIDTH_KEY) ?? null);
    } catch {
        return SIDER_WIDTH_DEFAULT;
    }
}

export function writeStoredSiderWidth(width: number): void {
    try {
        storage()?.setItem(SIDER_WIDTH_KEY, String(clampSiderWidth(width)));
    } catch {
        // As above.
    }
}

/**
 * The attribute the stylesheet keys its dark palette on. Set on <html> so
 * the ground is painted before the first paint of any component, and so a
 * portal (a Modal, a Dropdown, a Select's list) is inside the same scope.
 */
export function applyThemeAttribute(mode: ThemeMode, root: { setAttribute(name: string, value: string): void } | null = typeof document === 'undefined' ? null : document.documentElement): void {
    root?.setAttribute('data-theme', mode);
}
