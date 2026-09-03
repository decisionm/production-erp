import { describe, expect, it, vi } from 'vitest';
import {
    DEFAULT_THEME_MODE,
    SIDER_WIDTH_DEFAULT,
    SIDER_WIDTH_MAX,
    SIDER_WIDTH_MIN,
    applyThemeAttribute,
    clampSiderWidth,
    isThemeMode,
    nextThemeMode,
    parseSiderWidth,
    parseThemeMode,
} from './mode';

describe('theme mode', () => {
    it('knows the two modes and nothing else', () => {
        expect(isThemeMode('light')).toBe(true);
        expect(isThemeMode('dark')).toBe(true);
        expect(isThemeMode('DARK')).toBe(false);
        expect(isThemeMode(null)).toBe(false);
    });

    it('toggles between them', () => {
        expect(nextThemeMode('light')).toBe('dark');
        expect(nextThemeMode('dark')).toBe('light');
    });

    it('reads a stored value, and falls back to the default for anything else', () => {
        expect(parseThemeMode('dark')).toBe('dark');
        expect(parseThemeMode('light')).toBe('light');
        expect(parseThemeMode(null)).toBe(DEFAULT_THEME_MODE);
        expect(parseThemeMode('midnight')).toBe(DEFAULT_THEME_MODE);
    });

    it('stamps the mode on the element the stylesheet keys on', () => {
        const setAttribute = vi.fn();
        applyThemeAttribute('dark', { setAttribute });
        expect(setAttribute).toHaveBeenCalledWith('data-theme', 'dark');
    });
});

describe('sider width', () => {
    it('holds a dragged width inside the range, rounded', () => {
        expect(clampSiderWidth(300.4)).toBe(300);
        expect(clampSiderWidth(40)).toBe(SIDER_WIDTH_MIN);
        expect(clampSiderWidth(9000)).toBe(SIDER_WIDTH_MAX);
    });

    it('falls back to the default for a number that is not one', () => {
        expect(clampSiderWidth(Number.NaN)).toBe(SIDER_WIDTH_DEFAULT);
        expect(clampSiderWidth(Number.POSITIVE_INFINITY)).toBe(SIDER_WIDTH_DEFAULT);
    });

    it('reads a stored width, and falls back for an absent or unusable one', () => {
        expect(parseSiderWidth('320')).toBe(320);
        expect(parseSiderWidth('  ')).toBe(SIDER_WIDTH_DEFAULT);
        expect(parseSiderWidth(null)).toBe(SIDER_WIDTH_DEFAULT);
        expect(parseSiderWidth('wide')).toBe(SIDER_WIDTH_DEFAULT);
        expect(parseSiderWidth('1000')).toBe(SIDER_WIDTH_MAX);
    });
});
