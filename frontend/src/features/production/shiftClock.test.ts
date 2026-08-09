import { describe, expect, it } from 'vitest';
import { activeShifts, currentShift, productionDateFor } from './shiftClock';
import type { Shift } from './types';

/**
 * The live database's real shift shape, exactly: the factory's three active
 * shifts PLUS the deactivated Morning/Afternoon/Night rows the rename era
 * left behind (DEC-20260806-007, seeder incident PR #125). Historical
 * production references the retired rows, so they exist forever — and any
 * consumer fed this list raw doubles every shift window. The dashboard rail
 * did exactly that and drew six segments on live (09-Aug).
 */
const LIVE_SHAPED: Shift[] = [
    { id: 1, name: 'Shift A', start_time: '06:00', end_time: '14:00', is_active: true },
    { id: 2, name: 'Shift B', start_time: '14:00', end_time: '22:00', is_active: true },
    { id: 3, name: 'Shift C', start_time: '22:00', end_time: '06:00', is_active: true },
    { id: 4, name: 'Morning', start_time: '06:00', end_time: '14:00', is_active: false },
    { id: 5, name: 'Afternoon', start_time: '14:00', end_time: '22:00', is_active: false },
    { id: 6, name: 'Night', start_time: '22:00', end_time: '06:00', is_active: false },
];

const at = (hours: number, minutes = 0) => new Date(2026, 7, 9, hours, minutes);

describe('activeShifts — the operational contract', () => {
    it('reduces the live six-row shape to the factory’s three', () => {
        // The failure mode first: the raw list really does carry six rows —
        // this is the shape that reached the rail and drew six segments.
        expect(LIVE_SHAPED).toHaveLength(6);

        expect(activeShifts(LIVE_SHAPED).map((s) => s.name)).toEqual(['Shift A', 'Shift B', 'Shift C']);
    });
});

describe('currentShift on the operational list', () => {
    it('picks the factory shift, never a retired twin', () => {
        const shift = currentShift(activeShifts(LIVE_SHAPED), at(15, 30));
        expect(shift?.name).toBe('Shift B');
        expect(shift?.is_active).toBe(true);
    });

    it('resolves the overnight shift after midnight', () => {
        expect(currentShift(activeShifts(LIVE_SHAPED), at(2, 0))?.name).toBe('Shift C');
    });
});

describe('productionDateFor', () => {
    it('files an overnight entry made after midnight under yesterday', () => {
        const nightShift = activeShifts(LIVE_SHAPED)[2];
        expect(productionDateFor(nightShift, at(2, 0))).toBe('2026-08-08');
    });

    it('files a day-shift entry under today', () => {
        expect(productionDateFor(activeShifts(LIVE_SHAPED)[1], at(15, 0))).toBe('2026-08-09');
    });
});
