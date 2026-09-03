import { describe, expect, it } from 'vitest';
import { askBubbleBg } from '@/theme/tokens';

/**
 * The question bubble carries WHITE text on a solid fill, in both modes, at
 * normal size — so both fills must clear WCAG AA's 4.5:1.
 *
 * This is measured because the eye missed it: the bubble first took the app's
 * navy, which lifts to #5B7BD6 in dark, and white on that is 4.00:1. It
 * looked perfectly fine in a screenshot.
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

describe('the question bubble', () => {
    it('has a fill for both modes', () => {
        expect(Object.keys(askBubbleBg).sort()).toEqual(['dark', 'light']);
    });

    it('carries white text at a readable contrast in both modes', () => {
        for (const mode of ['light', 'dark'] as const) {
            expect(contrast('#ffffff', askBubbleBg[mode]), `white on ${mode} ${askBubbleBg[mode]}`).toBeGreaterThan(4.5);
        }
    });

    it('would have caught the fill the eye passed', () => {
        // The app navy as it lifts in dark. It looked fine, and it is not.
        expect(contrast('#ffffff', '#5B7BD6')).toBeLessThan(4.5);
    });
});
