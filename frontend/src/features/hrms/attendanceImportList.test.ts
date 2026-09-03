import { describe, expect, it } from 'vitest';
import { ATTENDANCE_IMPORT_LINE_LIST_SPEC, applyLabel, defaultResolution, lineFilterChips } from './list';
import { readListParams } from '@/lib/listParams';

describe('the review list on the URL', () => {
    it('keeps a known chip and drops an unknown one', () => {
        expect(readListParams(new URLSearchParams('issue=no_punch&q=spp&page=2'), ATTENDANCE_IMPORT_LINE_LIST_SPEC)).toEqual({
            issue: 'no_punch',
            q: 'spp',
            page: 2,
        });
        expect(readListParams(new URLSearchParams('issue=late'), ATTENDANCE_IMPORT_LINE_LIST_SPEC)).toEqual({});
    });
});

describe('lineFilterChips', () => {
    it('puts the server count beside every chip, and none before the server has answered', () => {
        const chips = lineFilterChips({ open: 3, in_no_out: 1, out_no_in: 0, no_punch: 1, unknown_employee: 1, resolved: 2, clean: 40 });
        expect(chips.map((chip) => chip.label)).toEqual([
            'All',
            'All issues (3)',
            'In without Out (1)',
            'Out without In (0)',
            'No punch (1)',
            'Unknown employee (1)',
            'Resolved (2)',
            'Clean (40)',
        ]);
        expect(lineFilterChips(undefined).map((chip) => chip.label)).toEqual([
            'All',
            'All issues',
            'In without Out',
            'Out without In',
            'No punch',
            'Unknown employee',
            'Resolved',
            'Clean',
        ]);
    });
});

describe('defaultResolution', () => {
    it('is absent for a missing punch, present for a half-recorded day, and the line’s own answer when it has one', () => {
        expect(defaultResolution({ issue: 'no_punch', resolution: null })).toBe('absent');
        expect(defaultResolution({ issue: 'in_no_out', resolution: null })).toBe('present');
        expect(defaultResolution({ issue: 'out_no_in', resolution: null })).toBe('present');
        expect(defaultResolution({ issue: 'unknown_employee', resolution: null })).toBe('present');
        expect(defaultResolution({ issue: 'no_punch', resolution: 'on_leave' })).toBe('on_leave');
        expect(defaultResolution({ issue: null, resolution: 'week_off' })).toBe('week_off');
    });
});

describe('applyLabel', () => {
    it('carries the open count until nothing is open', () => {
        expect(applyLabel(0)).toBe('Apply');
        expect(applyLabel(1)).toBe('Apply (1 open)');
        expect(applyLabel(12)).toBe('Apply (12 open)');
    });
});
