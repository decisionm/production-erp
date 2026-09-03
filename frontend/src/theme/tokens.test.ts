import { describe, expect, it } from 'vitest';
import { FONT_FAMILY, appTheme, brand, dark } from './tokens';

/**
 * Pins the visual refresh (03-Sep-2026): the brand's own two colours carry
 * the theme, the font is bundled rather than fetched, the table header is
 * navy with white text, and dark mode's words are genuinely readable.
 */

/** Relative luminance per WCAG 2.1, from a #rrggbb string. */
function luminance(hex: string): number {
    const channel = (pair: string) => {
        const value = Number.parseInt(pair, 16) / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };
    const r = channel(hex.slice(1, 3));
    const g = channel(hex.slice(3, 5));
    const b = channel(hex.slice(5, 7));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
    const [light, dim] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dim + 0.05);
}

describe('appTheme', () => {
    it('uses the brand navy as primary and the brand orange as the hover accent in light', () => {
        const light = appTheme('light');
        expect(light.token?.colorPrimary).toBe(brand.navy);
        expect(light.token?.colorLinkHover).toBe(brand.orange);
    });

    it('names the bundled Archivo face first, with a system fallback stack, in both modes', () => {
        for (const mode of ['light', 'dark'] as const) {
            expect(appTheme(mode).token?.fontFamily).toBe(FONT_FAMILY);
        }
        expect(FONT_FAMILY.startsWith("'Archivo Variable'")).toBe(true);
        expect(FONT_FAMILY).toContain('sans-serif');
    });

    it('gives every table a dark header with white text in both modes', () => {
        expect(appTheme('light').components?.Table?.headerBg).toBe(brand.navy);
        expect(appTheme('dark').components?.Table?.headerBg).toBe(dark.tableHeader);
        for (const mode of ['light', 'dark'] as const) {
            expect(appTheme(mode).components?.Table?.headerColor).toBe('#ffffff');
        }
    });

    it('keeps the sidebar dark navy in both modes, darker still at night', () => {
        expect(appTheme('light').components?.Layout?.siderBg).toBe(brand.sider);
        expect(appTheme('dark').components?.Layout?.siderBg).toBe(brand.siderDark);
        expect(luminance(brand.siderDark)).toBeLessThan(luminance(brand.sider));
        // "A little dark", not black: the rail still reads as navy.
        expect(luminance(brand.sider)).toBeGreaterThan(luminance('#000000'));
    });

    it('switches antd algorithms so every unlisted token follows the mode', () => {
        expect(appTheme('light').algorithm).not.toBe(appTheme('dark').algorithm);
    });

    it('makes dark-mode words MORE readable, not less', () => {
        // WCAG AA is 4.5:1 for body text and 3:1 for large text; the point of
        // this mode is that a floor screen is easier to read, so body clears
        // AAA (7:1) and the secondary tone clears AA.
        expect(contrast(dark.text, dark.bg)).toBeGreaterThan(7);
        expect(contrast(dark.text, dark.bgContainer)).toBeGreaterThan(7);
        expect(contrast(dark.textSecondary, dark.bgContainer)).toBeGreaterThan(4.5);
        expect(contrast(dark.heading, dark.bgContainer)).toBeGreaterThan(7);
        expect(contrast('#ffffff', dark.tableHeader)).toBeGreaterThan(7);
    });

    it('lifts the accents off the dark ground rather than reusing the navy', () => {
        expect(contrast(dark.primary, dark.bg)).toBeGreaterThan(3);
        expect(contrast(dark.orange, dark.bg)).toBeGreaterThan(3);
        expect(dark.primary).not.toBe(brand.navy);
    });

    it('keeps the semantic colours distinct from each other and from the accent', () => {
        const set = new Set([brand.success, brand.warning, brand.danger, brand.orange, brand.navy, brand.teal]);
        expect(set.size).toBe(6);
    });
});
