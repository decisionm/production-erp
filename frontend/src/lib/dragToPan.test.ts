import { describe, expect, it } from 'vitest';
import { PAN_THRESHOLD, movementIsAPan, overflowsHorizontally, pannedScrollLeft, pressCanPan } from './dragToPan';

const wide = { scrollLeft: 0, scrollWidth: 1843, clientWidth: 1011 };
const narrow = { scrollLeft: 0, scrollWidth: 1011, clientWidth: 1011 };

/** The DOM's `closest`, stubbed: does this press sit on something interactive? */
const on = (interactive: boolean) => () => interactive;

describe('a wide table pans', () => {
    it('only when it is actually hiding columns', () => {
        expect(overflowsHorizontally(wide)).toBe(true);
        expect(overflowsHorizontally(narrow)).toBe(false);
        expect(pressCanPan(0, on(false), narrow)).toBe(false);
        expect(pressCanPan(0, on(false), null)).toBe(false);
    });

    it('from a press on the rows, with the primary button', () => {
        expect(pressCanPan(0, on(false), wide)).toBe(true);
        // Right and middle buttons keep their own meanings.
        expect(pressCanPan(2, on(false), wide)).toBe(false);
        expect(pressCanPan(1, on(false), wide)).toBe(false);
    });

    it('never from something you press or type in', () => {
        // A button, a link, a checkbox, a sorter — the press belongs to them.
        expect(pressCanPan(0, on(true), wide)).toBe(false);
    });
});

describe('what counts as a pan rather than a click', () => {
    it('needs real sideways travel', () => {
        expect(movementIsAPan(0, 0)).toBe(false);
        expect(movementIsAPan(PAN_THRESHOLD, 0)).toBe(false);
        expect(movementIsAPan(PAN_THRESHOLD + 1, 0)).toBe(true);
        expect(movementIsAPan(-(PAN_THRESHOLD + 1), 0)).toBe(true);
    });

    it('leaves a vertical drag to the page, so scrolling still works', () => {
        expect(movementIsAPan(6, 40)).toBe(false);
        expect(movementIsAPan(40, 6)).toBe(true);
    });
});

describe('where the table lands', () => {
    it('follows the hand: drag left, the columns come in from the right', () => {
        expect(pannedScrollLeft(0, -100, wide)).toBe(100);
        expect(pannedScrollLeft(300, 100, wide)).toBe(200);
    });

    it('stops at both ends rather than running past them', () => {
        expect(pannedScrollLeft(0, 500, wide)).toBe(0);
        expect(pannedScrollLeft(0, -99999, wide)).toBe(wide.scrollWidth - wide.clientWidth);
    });
});
