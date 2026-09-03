import { describe, expect, it } from 'vitest';
import { queueTileStyle } from './queueTileStyle';
import type { Tone } from './waitingOnYou';

/**
 * The tiles are the first thing on the dashboard and they are read at a
 * glance, on a tablet, in a plant that is dark at night. So every pair is
 * CHECKED BY COMPUTING IT. Twice already a colour that looked perfectly fine
 * in a screenshot was under AA — the Ask ERP bubble at 4.00:1, and two light
 * machine-state values that had been live for weeks. Eyes are not the
 * instrument for this.
 *
 * The thresholds are WCAG AA: 4.5:1 for the label, which is 13px, and 3:1
 * for the figure, which is 40px and therefore large text.
 */
function luminance(hex: string): number {
    const channel = (pair: string) => {
        const value = Number.parseInt(pair, 16) / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(hex.slice(1, 3)) + 0.7152 * channel(hex.slice(3, 5)) + 0.0722 * channel(hex.slice(5, 7));
}

function contrast(a: string, b: string): number {
    const [light, dim] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dim + 0.05);
}

const TONES: Tone[] = ['act', 'wait', 'calm'];
const MODES = ['light', 'dark'] as const;

describe('the queue tile palette', () => {
    it('names all three tones in both modes', () => {
        for (const mode of MODES) {
            expect(Object.keys(queueTileStyle(mode)).sort()).toEqual([...TONES].sort());
        }
    });

    it('puts the figure over its own fill at 3:1 or better', () => {
        for (const mode of MODES) {
            const palette = queueTileStyle(mode);
            for (const tone of TONES) {
                const { figure, background } = palette[tone];
                expect(contrast(figure, background), `${mode}/${tone} figure`).toBeGreaterThanOrEqual(3);
            }
        }
    });

    it('puts the label over its own fill at 4.5:1 or better', () => {
        for (const mode of MODES) {
            const palette = queueTileStyle(mode);
            for (const tone of TONES) {
                const { label, background } = palette[tone];
                expect(contrast(label, background), `${mode}/${tone} label`).toBeGreaterThanOrEqual(4.5);
            }
        }
    });

    /*
     * THE BUG THIS PINS, and the reason the two modes are separate tables
     * rather than one table at an opacity: #fff1f0 is a perfectly good alarm
     * over white and a white patch over navy. A dark tile whose ground is
     * near-white is the exact complaint this dashboard already had once.
     */
    it('never paints a near-white tile in dark', () => {
        for (const tone of TONES) {
            expect(luminance(queueTileStyle('dark')[tone].background), `dark/${tone}`).toBeLessThan(0.2);
        }
    });

    it('keeps every tile distinguishable from the one beside it', () => {
        // Two tones that differ only by a hair are one tone with extra steps.
        const light = queueTileStyle('light');
        expect(contrast(light.act.background, light.calm.background)).toBeGreaterThan(1.02);
        expect(contrast(light.wait.background, light.calm.background)).toBeGreaterThan(1.02);

        const dark = queueTileStyle('dark');
        expect(contrast(dark.act.background, dark.calm.background)).toBeGreaterThan(1.02);
        expect(contrast(dark.wait.background, dark.calm.background)).toBeGreaterThan(1.02);
    });

    it('gives the edge a visible step up from its own fill', () => {
        for (const mode of MODES) {
            const palette = queueTileStyle(mode);
            for (const tone of TONES) {
                const { borderColor, background } = palette[tone];
                expect(contrast(borderColor, background), `${mode}/${tone} edge`).toBeGreaterThan(1.1);
            }
        }
    });
});
