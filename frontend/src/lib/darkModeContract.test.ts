import { describe, expect, it } from 'vitest';

/**
 * A source lint (03-Sep-2026, owner: the dashboard in dark is "pathetic",
 * and "the complete patch white spaces are not good").
 *
 * A colour written literally into a component is fixed at BUILD time, so it
 * cannot follow light/dark. A near-white one painted as a BACKGROUND is the
 * visible failure: on a dark page it stays near-white and the screen shows a
 * white patch. Six pages had one, and the dashboard's whole palette was
 * light-only — `--dash-panel: #ffffff` was the day-bin faceplate.
 *
 * Backgrounds and borders must come from a theme variable (`var(--app-…)`,
 * `var(--brand-…)`, `var(--dash-…)`) or from an antd token. A literal is
 * still fine for a FOREGROUND on a fill this app controls — white text on
 * the navy login panel, or on a solid status pill.
 *
 * Read through Vite's own glob: this project ships no @types/node, and a
 * lint test is not a reason to add one.
 */
const sources = import.meta.glob('../features/**/*.tsx', {
    eager: true,
    query: '?raw',
    import: 'default',
}) as Record<string, string>;

/** `background: '#fafafa'` and friends — a literal light fill in a component. */
const LIGHT_FILL = /\bbackground(?:Color)?:\s*'#(?:fff|ffffff|fafafa|f5f5f5|f0f0f0|fcfcfc|eeeeee|e8e8e8)'/i;

/** The one place a literal light fill is right: the login page's own brand panel. */
const ALLOWED = ['LoginPage.tsx'];

export function paintsALiteralLightFill(source: string): boolean {
    return LIGHT_FILL.test(source);
}

describe('dark mode', () => {
    it('reads the pages it is meant to be linting', () => {
        expect(Object.keys(sources).length).toBeGreaterThan(50);
    });

    it('spots a literal light fill', () => {
        expect(paintsALiteralLightFill("style={{ background: '#fafafa' }}")).toBe(true);
        expect(paintsALiteralLightFill("style={{ backgroundColor: '#FFF' }}")).toBe(true);
        expect(paintsALiteralLightFill("style={{ background: 'var(--app-inset)' }}")).toBe(false);
        // A foreground on a fill the app controls is not this bug.
        expect(paintsALiteralLightFill("style={{ color: '#fff' }}")).toBe(false);
    });

    it('is never painted into a page as a literal light fill', () => {
        const offenders = Object.entries(sources)
            .filter(([path]) => !ALLOWED.some((allowed) => path.endsWith(allowed)))
            .filter(([, source]) => paintsALiteralLightFill(source))
            .map(([path]) => path);

        expect(offenders).toEqual([]);
    });
});
