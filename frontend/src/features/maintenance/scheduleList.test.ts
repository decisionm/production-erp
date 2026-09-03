import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { SCHEDULE_DEFAULT_SORT, SCHEDULE_LIST_SPEC, SCHEDULE_SORT_FIELDS, scheduleServerFilters } from './scheduleList';

describe('the schedules list reads its sort from the URL', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=asset'), SCHEDULE_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(scheduleServerFilters(params)).toEqual({});
    });

    it('sends a known sort, the asset filter, the page and the page size to the server', () => {
        const params = readListParams(
            new URLSearchParams('sort=-frequency_days&asset_id=4&page=3&per_page=20'),
            SCHEDULE_LIST_SPEC,
        );

        expect(scheduleServerFilters(params)).toEqual({ sort: '-frequency_days', asset_id: 4, page: 3, per_page: 20 });
    });

    it('accepts every sortable column in both directions', () => {
        for (const field of SCHEDULE_SORT_FIELDS) {
            for (const sort of [field, `-${field}`]) {
                expect(readListParams(new URLSearchParams(`sort=${sort}`), SCHEDULE_LIST_SPEC).sort).toBe(sort);
            }
        }
    });

    it('opens soonest due first — what MaintenanceScheduleService defaults to — and keeps that order off the URL', () => {
        expect(SCHEDULE_DEFAULT_SORT).toBe('next_due_date');
        expect(columnSortOrder('next_due_date', undefined, SCHEDULE_DEFAULT_SORT)).toBe('ascend');
        expect(columnSortOrder('name', undefined, SCHEDULE_DEFAULT_SORT)).toBeNull();
        expect(
            sortParamFromSorter({ columnKey: 'next_due_date', order: 'ascend' }, SCHEDULE_SORT_FIELDS, SCHEDULE_DEFAULT_SORT),
        ).toBeUndefined();
        expect(
            sortParamFromSorter({ columnKey: 'next_due_date', order: 'descend' }, SCHEDULE_SORT_FIELDS, SCHEDULE_DEFAULT_SORT),
        ).toBe('-next_due_date');
    });
});
