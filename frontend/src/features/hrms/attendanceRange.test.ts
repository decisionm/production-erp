import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import { presetFor, rangeFor, rangeLabel } from './attendanceRange';

// A Wednesday, so "last week" and "this week" are unambiguous.
const wed = dayjs('2026-09-02');

describe('rangeFor', () => {
    it('gives today and yesterday as single days', () => {
        expect(rangeFor('today', wed)).toEqual({ from: '2026-09-02', to: '2026-09-02' });
        expect(rangeFor('yesterday', wed)).toEqual({ from: '2026-09-01', to: '2026-09-01' });
    });

    it('runs the week from Monday, not from Sunday', () => {
        // The factory week starts on Monday; a Sunday start reads as empty
        // every Monday morning.
        expect(rangeFor('this_week', wed)).toEqual({ from: '2026-08-31', to: '2026-09-02' });
        expect(rangeFor('last_week', wed)).toEqual({ from: '2026-08-24', to: '2026-08-30' });
    });

    it('means the month that ended, not the last thirty days', () => {
        expect(rangeFor('this_month', wed)).toEqual({ from: '2026-09-01', to: '2026-09-02' });
        expect(rangeFor('last_month', wed)).toEqual({ from: '2026-08-01', to: '2026-08-31' });
    });

    it('handles a short month and a year boundary', () => {
        expect(rangeFor('last_month', dayjs('2026-03-15'))).toEqual({ from: '2026-02-01', to: '2026-02-28' });
        expect(rangeFor('last_month', dayjs('2026-01-09'))).toEqual({ from: '2025-12-01', to: '2025-12-31' });
    });

    it('keeps a Monday in its own week', () => {
        const monday = dayjs('2026-09-07');
        expect(rangeFor('this_week', monday)).toEqual({ from: '2026-09-07', to: '2026-09-07' });
        expect(rangeFor('last_week', monday)).toEqual({ from: '2026-08-31', to: '2026-09-06' });
    });
});

describe('presetFor', () => {
    it('recognises a range a button produced', () => {
        expect(presetFor({ from: '2026-09-02', to: '2026-09-02' }, wed)).toBe('today');
        expect(presetFor({ from: '2026-08-01', to: '2026-08-31' }, wed)).toBe('last_month');
    });

    it('says nothing when somebody picked their own dates', () => {
        expect(presetFor({ from: '2026-08-11', to: '2026-08-19' }, wed)).toBeNull();
    });
});

describe('rangeLabel', () => {
    it('names one day by its day of the week', () => {
        expect(rangeLabel({ from: '2026-09-02', to: '2026-09-02' })).toBe('Wed 2 Sep 2026');
    });

    it('shortens a range inside one month, and spells out one that crosses', () => {
        expect(rangeLabel({ from: '2026-09-01', to: '2026-09-30' })).toBe('1 – 30 Sep 2026');
        expect(rangeLabel({ from: '2026-08-24', to: '2026-09-06' })).toBe('24 Aug – 6 Sep 2026');
    });
});
