import { describe, expect, it } from 'vitest';
import { correctableFiltersActive, correctableQuery } from './correctableFilters';

const ALL_SET = {
    q: 'B-2100',
    item_id: 7,
    work_center_id: 3,
    shift_id: 2,
    date_from: '2026-08-01',
    date_to: '2026-08-31',
    returned: true,
    sort: 'oldest' as const,
};

describe('correctableQuery', () => {
    it('is status=pending, correctable=1, sort=newest, per_page=25 with no filters set', () => {
        expect(correctableQuery({}, 1)).toEqual({
            status: 'pending',
            correctable: 1,
            sort: 'newest',
            per_page: 25,
            page: 1,
        });
    });

    it('omits q when it is blank or whitespace-only', () => {
        expect(correctableQuery({ q: '' }, 1)).not.toHaveProperty('q');
        expect(correctableQuery({ q: '   ' }, 1)).not.toHaveProperty('q');
    });

    it('trims q when set', () => {
        expect(correctableQuery({ q: '  B-100  ' }, 1).q).toBe('B-100');
    });

    it('defaults sort to newest when unset', () => {
        expect(correctableQuery({ sort: undefined }, 1).sort).toBe('newest');
    });

    it('carries sort=oldest through when asked', () => {
        expect(correctableQuery({ sort: 'oldest' }, 1).sort).toBe('oldest');
    });

    it('sends returned=1 only when true; omits it when false or unset', () => {
        expect(correctableQuery({ returned: true }, 1).returned).toBe(1);
        expect(correctableQuery({ returned: false }, 1)).not.toHaveProperty('returned');
        expect(correctableQuery({}, 1)).not.toHaveProperty('returned');
    });

    it('every set filter appears once, alongside the constants and the requested page', () => {
        expect(correctableQuery(ALL_SET, 4)).toEqual({
            status: 'pending',
            correctable: 1,
            item_id: 7,
            q: 'B-2100',
            work_center_id: 3,
            shift_id: 2,
            date_from: '2026-08-01',
            date_to: '2026-08-31',
            returned: 1,
            sort: 'oldest',
            per_page: 25,
            page: 4,
        });
    });
});

describe('correctableFiltersActive', () => {
    it('is false for the default (empty) state', () => {
        expect(correctableFiltersActive({})).toBe(false);
    });

    it('is false when sort is explicitly newest and returned is explicitly false', () => {
        expect(correctableFiltersActive({ sort: 'newest', returned: false })).toBe(false);
    });

    it('is false for a blank or whitespace-only q', () => {
        expect(correctableFiltersActive({ q: '' })).toBe(false);
        expect(correctableFiltersActive({ q: '   ' })).toBe(false);
    });

    it('is true for each filter set on its own', () => {
        expect(correctableFiltersActive({ q: 'B-100' })).toBe(true);
        expect(correctableFiltersActive({ item_id: 7 })).toBe(true);
        expect(correctableFiltersActive({ work_center_id: 3 })).toBe(true);
        expect(correctableFiltersActive({ shift_id: 2 })).toBe(true);
        expect(correctableFiltersActive({ date_from: '2026-08-01' })).toBe(true);
        expect(correctableFiltersActive({ date_to: '2026-08-31' })).toBe(true);
        expect(correctableFiltersActive({ returned: true })).toBe(true);
        expect(correctableFiltersActive({ sort: 'oldest' })).toBe(true);
    });
});
