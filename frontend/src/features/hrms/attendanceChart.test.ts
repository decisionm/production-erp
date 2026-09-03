import { describe, expect, it } from 'vitest';
import { barScale, tallyBars } from './attendanceChart';
import type { AttendanceTally } from './types';

const tally = (over: Partial<AttendanceTally> = {}): AttendanceTally => ({
    present: 0,
    absent: 0,
    half_day: 0,
    on_leave: 0,
    recorded: 0,
    week_off: 0,
    needs_review: 0,
    from_import: 0,
    mismatches: 0,
    ...over,
});

describe('the attendance chart', () => {
    it('reads worked, then not worked, then unsettled', () => {
        const bars = tallyBars(tally({ present: 18, half_day: 2, absent: 1, on_leave: 1, week_off: 4, needs_review: 3, mismatches: 5 }));

        expect(bars.map((bar) => bar.label)).toEqual([
            'Present',
            'Half Day',
            'Absent',
            'On Leave',
            'Week Off',
            'Needs review',
            'Mismatches',
        ]);
        expect(bars.map((bar) => bar.value)).toEqual([18, 2, 1, 1, 4, 3, 5]);
    });

    it('keeps the empty bars', () => {
        // A month with no absences and a month whose absences were never
        // counted look identical once the zeroes are dropped, and only one
        // of those is good news.
        const bars = tallyBars(tally({ present: 20 }));

        expect(bars).toHaveLength(7);
        expect(bars.find((bar) => bar.label === 'Absent')?.value).toBe(0);
    });

    it('scales to whole days, never to a fraction of one', () => {
        const scale = barScale(tallyBars(tally({ present: 18 })));

        expect(scale.max).toBe(20);
        expect(scale.ticks).toEqual([0, 5, 10, 15, 20]);
        expect(scale.ticks.every(Number.isInteger)).toBe(true);
    });

    it('gives an empty month an axis rather than dividing by nothing', () => {
        expect(barScale(tallyBars(tally())).max).toBe(1);
    });

    it('does not round a small month up to something it is not', () => {
        expect(barScale(tallyBars(tally({ present: 3 }))).max).toBe(3);
    });
});
