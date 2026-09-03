import { describe, expect, it } from 'vitest';
import {
    DAY_STATE_COLORS,
    DAY_STATE_LABELS,
    bulkOffer,
    bulkOutcome,
    dayLabel,
    progressLine,
    progressPercent,
    punchLine,
} from './attendanceReview';
import type { AttendanceImportCounts } from './types';

const counts = (over: Partial<AttendanceImportCounts> = {}): AttendanceImportCounts => ({
    open: 0,
    resolved: 0,
    clean: 0,
    in_no_out: 0,
    out_no_in: 0,
    no_punch: 0,
    unknown_employee: 0,
    hours_unclear: 0,
    worked_on_week_off: 0,
    ...over,
});

describe('dayLabel', () => {
    it('reads as a day, not as an ISO date', () => {
        expect(dayLabel('2026-07-01')).toBe('Wed 1 Jul');
        expect(dayLabel('2026-07-31')).toBe('Fri 31 Jul');
    });
});

describe('punchLine', () => {
    it('says what the clock recorded', () => {
        expect(punchLine({ first_in: '09:00', last_out: '18:00' })).toBe('In 09:00, out 18:00');
        expect(punchLine({ first_in: '06:04', last_out: null })).toBe('In 06:04, no out');
        expect(punchLine({ first_in: null, last_out: '18:00' })).toBe('Out 18:00, no in');
        expect(punchLine({ first_in: null, last_out: null })).toBe('No punch');
    });
});

describe('progress', () => {
    it('counts what is left against the month, with thousands separated', () => {
        expect(progressLine(counts({ open: 589, resolved: 0, clean: 1240 }), 1829)).toBe(
            '589 of 1,829 days need an answer · 0 answered',
        );
        expect(progressLine(counts({ open: 100, resolved: 489 }), 1829)).toBe(
            '100 of 1,829 days need an answer · 489 answered',
        );
    });

    it('says the month is ready once nothing is open', () => {
        expect(progressLine(counts({ open: 0, resolved: 589 }), 1829)).toBe('All 589 answered, ready to apply');
    });

    it('says so when a month had no issue at all', () => {
        expect(progressLine(counts({ clean: 900 }), 900)).toBe('900 days, nothing to answer');
    });

    it('is a percentage of the issues, not of the month', () => {
        expect(progressPercent(counts({ open: 589, resolved: 0 }))).toBe(0);
        expect(progressPercent(counts({ open: 300, resolved: 300 }))).toBe(50);
        expect(progressPercent(counts({ open: 0, resolved: 589 }))).toBe(100);
        expect(progressPercent(counts())).toBe(100);
        expect(progressPercent(undefined)).toBe(0);
    });
});

describe('bulkOffer', () => {
    it('offers absent for a day nobody punched, and needs no time', () => {
        const offer = bulkOffer('no_punch', counts({ no_punch: 366 }));

        expect(offer).toEqual({
            issue: 'no_punch',
            resolution: 'absent',
            label: 'Mark all 366 as Absent',
            time: null,
            timeLabel: '',
        });
    });

    it('offers a shift end for a missing out-punch', () => {
        expect(bulkOffer('in_no_out', counts({ in_no_out: 223 }))).toMatchObject({
            resolution: 'present',
            label: 'Set the out-time for all 223',
            time: 'check_out',
        });
        expect(bulkOffer('out_no_in', counts({ out_no_in: 4 }))).toMatchObject({
            label: 'Set the in-time for all 4',
            time: 'check_in',
        });
    });

    it('never offers to answer for a person the master does not have', () => {
        expect(bulkOffer('unknown_employee', counts({ unknown_employee: 12 }))).toBeNull();
    });

    it('offers nothing on the wide views, on the answered ones, or on an empty kind', () => {
        expect(bulkOffer('', counts({ no_punch: 366 }))).toBeNull();
        expect(bulkOffer('open', counts({ no_punch: 366 }))).toBeNull();
        expect(bulkOffer('resolved', counts({ resolved: 20 }))).toBeNull();
        expect(bulkOffer('clean', counts({ clean: 20 }))).toBeNull();
        expect(bulkOffer('no_punch', counts({ no_punch: 0 }))).toBeNull();
        expect(bulkOffer('no_punch', undefined)).toBeNull();
    });
});

describe('bulkOutcome', () => {
    it('states what was answered', () => {
        expect(bulkOutcome({ resolved: 366, skipped: 0, skipped_codes: [] })).toBe('366 days answered.');
        expect(bulkOutcome({ resolved: 1, skipped: 0, skipped_codes: [] })).toBe('1 day answered.');
    });

    it('names whoever was skipped rather than burying the count', () => {
        expect(bulkOutcome({ resolved: 4, skipped: 2, skipped_codes: ['SPP-77', 'ZZZ-99'] })).toBe(
            '4 days answered. 2 skipped, SPP-77, ZZZ-99 not in the employee master.',
        );
    });
});

describe('the day states', () => {
    it('name and colour every state a strip can draw', () => {
        const states = ['present', 'half_day', 'absent', 'on_leave', 'week_off', 'needs_fix'] as const;

        for (const state of states) {
            expect(DAY_STATE_LABELS[state]).toBeTruthy();
            expect(DAY_STATE_COLORS[state]).toMatch(/^#[0-9a-f]{6}$/);
        }
    });

    it('gives the work the only warm colour, so the eye finds it', () => {
        expect(DAY_STATE_COLORS.needs_fix).toBe('#fb8c00');
        expect(DAY_STATE_LABELS.needs_fix).toBe('Needs an answer');
        expect(DAY_STATE_COLORS.absent).toBe('#9e9e9e');
    });
});
