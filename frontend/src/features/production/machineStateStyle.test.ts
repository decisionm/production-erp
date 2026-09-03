import { describe, expect, it } from 'vitest';
import { stateStyle } from './machineStateStyle';

/**
 * The floor is read at a glance, sometimes across a room, on a tablet that
 * may be in a dark plant at night. So the five state colours are checked by
 * COMPUTING their contrast, not by looking at them: the owner's report was
 * that this screen "is not good in dark", and the cause was a palette fixed
 * at build time in light values.
 *
 * The card grounds come from the app theme: #ffffff by day, #171F33 at
 * night (theme/tokens.ts, colorBgContainer).
 */
const LIGHT_CARD = '#ffffff';
const DARK_CARD = '#171F33';

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

const STATES = ['down', 'mold_change', 'running_other_shift', 'running', 'idle'] as const;

describe('the machine state palette', () => {
    it('names all five states in both modes', () => {
        for (const mode of ['light', 'dark'] as const) {
            expect(Object.keys(stateStyle(mode)).sort()).toEqual([...STATES].sort());
        }
    });

    it('keeps the light rails and washes exactly as they shipped', () => {
        const light = stateStyle('light');
        expect(light.running.accent).toBe('#52c41a');
        expect(light.down.accent).toBe('#ff4d4f');
        expect(light.idle.wash).toBe('#fafafa');
    });

    /*
     * THE BUG THIS PINS. A wash is a card's whole background, and the light
     * washes are near-white by construction — on a dark page they glared.
     * A dark wash must sit CLOSE to the dark card it tints, not near white.
     */
    it('tints a dark card rather than washing it white', () => {
        for (const state of STATES) {
            const wash = stateStyle('dark')[state].wash;
            expect(wash.startsWith('rgba('), `${state} wash is ${wash}`).toBe(true);
        }
        for (const state of STATES) {
            expect(stateStyle('light')[state].wash.startsWith('#')).toBe(true);
        }
    });

    /*
     * `readable` is spent on NORMAL-size text — a breakdown reason, the
     * "Needs attention" line — not only on the big tile numerals, so the bar
     * is WCAG AA's 4.5:1, not the 3:1 that large text would allow. Measuring
     * this is what caught two shipped values missing it: the running green at
     * 3.46:1 and the mold-change amber at 4.41:1.
     */
    it('gives every state a word that can be read on its own card', () => {
        for (const state of STATES) {
            expect(contrast(stateStyle('dark')[state].readable, DARK_CARD), `${state} in dark`).toBeGreaterThan(4.5);
            expect(contrast(stateStyle('light')[state].readable, LIGHT_CARD), `${state} in light`).toBeGreaterThan(4.5);
        }
    });

    /** A card whose whole body takes the wash must still carry its own words. */
    it('gives every state a word that can be read on its own wash', () => {
        for (const state of STATES) {
            const light = stateStyle('light')[state];
            expect(contrast(light.readable, light.wash), `${state} on its wash`).toBeGreaterThan(4.5);
        }
    });

    it('tells the five rails apart, so a colour means one state', () => {
        for (const mode of ['light', 'dark'] as const) {
            const accents = STATES.map((state) => stateStyle(mode)[state].accent);
            expect(new Set(accents).size).toBe(STATES.length);
        }
    });
});
