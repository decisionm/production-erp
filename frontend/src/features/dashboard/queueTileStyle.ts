import type { ThemeMode } from '@/theme/mode';
import type { Tone } from './waitingOnYou';

/**
 * THE THREE TILE TONES, AS COLOUR — resolved from the theme mode, exactly as
 * machineStateStyle() resolves the five machine states.
 *
 * WHY THESE LIVE IN TYPESCRIPT AND NOT IN dashboard.css. Everything a tile
 * needs for LAYOUT is in the stylesheet, where it belongs. Colour is here
 * because colour is the part that has been wrong twice: two shipped light
 * values were under WCAG AA and neither was caught by looking, only by
 * computing. A stylesheet cannot be asked what contrast it produces — a
 * `?raw` import of a .css file comes back EMPTY under vitest, the css
 * pipeline having claimed it first — so a palette that must be PROVEN
 * readable has to be reachable from a test, and that means a module.
 *
 * `light` is not a tint of `dark` at some opacity. One translucent red over
 * white and over navy gives two different colours and only one of them is
 * legible, so each mode names its own fill, edge and figure, and
 * queueTileStyle.test.ts computes all twelve pairs.
 */
export interface ToneStyle {
    /** The tile's fill. */
    background: string;
    /** Its edge — the same hue as the fill, one step up, never a grey. */
    borderColor: string;
    /** The figure. Large text, so AA wants 3:1. */
    figure: string;
    /** The two or three words under it. Small text, so AA wants 4.5:1. */
    label: string;
}

const LIGHT: Record<Tone, ToneStyle> = {
    // Your own act, still undone.
    act: { background: '#fff1f0', borderColor: '#ffccc7', figure: '#cf1322', label: '#5b6579' },
    // Somebody else's move; you are waiting on it.
    wait: { background: '#fffbe6', borderColor: '#ffe58f', figure: '#ad6800', label: '#5b6579' },
    // Nothing owed. A queue at zero must not shout.
    calm: { background: '#ffffff', borderColor: '#d9e0ec', figure: '#0a145b', label: '#5b6579' },
};

/**
 * Deep, barely-saturated grounds. The light tints would be white patches
 * here, which is the specific complaint this palette was built to answer.
 */
const DARK: Record<Tone, ToneStyle> = {
    act: { background: '#341f22', borderColor: '#5e2f30', figure: '#e8604b', label: '#afb9d0' },
    wait: { background: '#2e2716', borderColor: '#5a4a22', figure: '#e8a72b', label: '#afb9d0' },
    calm: { background: '#171f33', borderColor: '#2a3450', figure: '#e9edf7', label: '#afb9d0' },
};

export function queueTileStyle(mode: ThemeMode): Record<Tone, ToneStyle> {
    return mode === 'dark' ? DARK : LIGHT;
}
