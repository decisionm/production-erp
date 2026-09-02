import { describe, expect, it } from 'vitest';
import { FONT_FAMILY, appTheme, brand } from './tokens';

/**
 * Pins the visual refresh (03-Sep-2026): the brand's own two colours carry
 * the theme, the font is bundled rather than fetched, and the table header
 * is navy with white text so figures read from across the floor.
 */
describe('appTheme', () => {
    it('uses the brand navy as primary and the brand orange as the hover accent', () => {
        expect(appTheme.token?.colorPrimary).toBe(brand.navy);
        expect(appTheme.token?.colorLinkHover).toBe(brand.orange);
    });

    it('names the bundled Archivo face first, with a system fallback stack', () => {
        expect(appTheme.token?.fontFamily).toBe(FONT_FAMILY);
        expect(FONT_FAMILY.startsWith("'Archivo Variable'")).toBe(true);
        expect(FONT_FAMILY).toContain('sans-serif');
    });

    it('gives every table a navy header with white text and an orange-tinted hover row', () => {
        const table = appTheme.components?.Table;
        expect(table?.headerBg).toBe(brand.navy);
        expect(table?.headerColor).toBe('#ffffff');
        expect(table?.rowHoverBg).toBe(brand.orangeSoft);
    });

    it('lights the sider so the navy moves into the tables', () => {
        expect(appTheme.components?.Layout?.siderBg).toBe(brand.glass);
        expect(appTheme.components?.Menu?.itemSelectedColor).toBe(brand.navy);
    });

    it('keeps the semantic colours distinct from each other and from the accent', () => {
        const set = new Set([brand.success, brand.warning, brand.danger, brand.orange, brand.navy, brand.teal]);
        expect(set.size).toBe(6);
    });
});
