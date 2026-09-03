import { describe, expect, it } from 'vitest';
import { correctableFiltersActive, correctableQuery } from './correctableFilters';

const TODAY = '2026-09-03';
const YESTERDAY = '2026-09-02';

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
    it('is status=pending, correctable=1, sort=newest, per_page=25, date_to=the day before today, with no filters set', () => {
        expect(correctableQuery({}, 1, TODAY)).toEqual({
            status: 'pending',
            correctable: 1,
            sort: 'newest',
            per_page: 25,
            page: 1,
            date_to: YESTERDAY,
        });
    });

    it('omits q when it is blank or whitespace-only', () => {
        expect(correctableQuery({ q: '' }, 1, TODAY)).not.toHaveProperty('q');
        expect(correctableQuery({ q: '   ' }, 1, TODAY)).not.toHaveProperty('q');
    });

    it('trims q when set', () => {
        expect(correctableQuery({ q: '  B-100  ' }, 1, TODAY).q).toBe('B-100');
    });

    it('defaults sort to newest when unset', () => {
        expect(correctableQuery({ sort: undefined }, 1, TODAY).sort).toBe('newest');
    });

    it('carries sort=oldest through when asked', () => {
        expect(correctableQuery({ sort: 'oldest' }, 1, TODAY).sort).toBe('oldest');
    });

    it('sends returned=1 only when true; omits it when false or unset', () => {
        expect(correctableQuery({ returned: true }, 1, TODAY).returned).toBe(1);
        expect(correctableQuery({ returned: false }, 1, TODAY)).not.toHaveProperty('returned');
        expect(correctableQuery({}, 1, TODAY)).not.toHaveProperty('returned');
    });

    it('every set filter appears once, alongside the constants and the requested page', () => {
        expect(correctableQuery(ALL_SET, 4, TODAY)).toEqual({
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

    describe('date_to — capped to the day before today (post-review fix)', () => {
        it('sends the day before today when the caller sets no date_to at all', () => {
            expect(correctableQuery({}, 1, TODAY).date_to).toBe(YESTERDAY);
        });

        it('keeps a user-picked date_to that is already earlier than the cap', () => {
            expect(correctableQuery({ date_to: '2026-08-20' }, 1, TODAY).date_to).toBe('2026-08-20');
        });

        it('clamps a user-picked date_to that is later than the cap (reaching toward or past today)', () => {
            expect(correctableQuery({ date_to: '2026-09-05' }, 1, TODAY).date_to).toBe(YESTERDAY);
        });

        it('clamps a user-picked date_to equal to today itself', () => {
            expect(correctableQuery({ date_to: TODAY }, 1, TODAY).date_to).toBe(YESTERDAY);
        });

        it('clamps a user-picked date_to equal to the cap (no change, but exercises the boundary)', () => {
            expect(correctableQuery({ date_to: YESTERDAY }, 1, TODAY).date_to).toBe(YESTERDAY);
        });

        it('crosses a month boundary correctly', () => {
            expect(correctableQuery({}, 1, '2026-09-01').date_to).toBe('2026-08-31');
            expect(correctableQuery({}, 1, '2026-03-01').date_to).toBe('2026-02-28');
        });

        it('crosses a year boundary correctly', () => {
            expect(correctableQuery({}, 1, '2026-01-01').date_to).toBe('2025-12-31');
        });

        it('handles a leap-year February correctly', () => {
            expect(correctableQuery({}, 1, '2028-03-01').date_to).toBe('2028-02-29');
        });

        it('never omits date_to, unlike every other optional filter', () => {
            expect(correctableQuery({}, 1, TODAY)).toHaveProperty('date_to');
        });
    });

    describe('date_from — clamped to the resolved date_to (second post-review fix)', () => {
        // The shift-switch scenario: `today` is recomputed on every shift
        // change (productionDateFor(effectiveShift)), so a `date_from` the
        // control row picked under an earlier `today` can end up AFTER the
        // newly-recomputed `date_to`. Sent as-is the server 422s
        // (after_or_equal:date_from on date_to) with retry:false, and the
        // section rendered "No batches match these filters." — a
        // validation error disguised as an empty result. The produced
        // params must never have date_from after date_to.

        it('clamps date_from to an EXPLICIT date_to when date_from is later than it', () => {
            const params = correctableQuery({ date_from: '2026-08-25', date_to: '2026-08-20' }, 1, TODAY);
            expect(params.date_from).toBe('2026-08-20');
            expect(params.date_to).toBe('2026-08-20');
            expect((params.date_from as string) <= params.date_to).toBe(true);
        });

        it('clamps date_from to the day-before-today cap when date_to is ABSENT and date_from is later than that cap', () => {
            const params = correctableQuery({ date_from: '2026-09-05' }, 1, TODAY);
            expect(params.date_from).toBe(YESTERDAY);
            expect(params.date_to).toBe(YESTERDAY);
        });

        it('does not touch date_from when it is already on or before the resolved date_to', () => {
            expect(correctableQuery({ date_from: '2026-08-15', date_to: '2026-08-20' }, 1, TODAY).date_from).toBe(
                '2026-08-15',
            );
            expect(correctableQuery({ date_from: YESTERDAY }, 1, TODAY).date_from).toBe(YESTERDAY);
        });

        it('omits date_from entirely when the caller never set it, even though date_to is always sent', () => {
            expect(correctableQuery({}, 1, TODAY)).not.toHaveProperty('date_from');
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
