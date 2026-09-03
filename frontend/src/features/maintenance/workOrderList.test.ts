import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { WORK_ORDER_DEFAULT_SORT, WORK_ORDER_LIST_SPEC, WORK_ORDER_SORT_FIELDS, workOrderServerFilters } from './workOrderList';

describe('the work order register reads its sort from the URL', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=asset'), WORK_ORDER_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(workOrderServerFilters(params)).toEqual({});
    });

    it('sends a known sort, the asset filter, the page and the page size to the server', () => {
        const params = readListParams(
            new URLSearchParams('sort=-reported_date&asset_id=7&page=2&per_page=100'),
            WORK_ORDER_LIST_SPEC,
        );

        expect(workOrderServerFilters(params)).toEqual({ sort: '-reported_date', asset_id: 7, page: 2, per_page: 100 });
    });

    it('accepts every sortable column in both directions', () => {
        for (const field of WORK_ORDER_SORT_FIELDS) {
            for (const sort of [field, `-${field}`]) {
                expect(readListParams(new URLSearchParams(`sort=${sort}`), WORK_ORDER_LIST_SPEC).sort).toBe(sort);
            }
        }
    });

    it('opens newest first — what MaintenanceWorkOrderService defaults to — and keeps that order off the URL', () => {
        expect(WORK_ORDER_DEFAULT_SORT).toBe('-id');
        expect(columnSortOrder('id', undefined, WORK_ORDER_DEFAULT_SORT)).toBe('descend');
        expect(columnSortOrder('reported_date', undefined, WORK_ORDER_DEFAULT_SORT)).toBeNull();
        expect(sortParamFromSorter({ columnKey: 'id', order: 'descend' }, WORK_ORDER_SORT_FIELDS, WORK_ORDER_DEFAULT_SORT)).toBeUndefined();
        expect(sortParamFromSorter({ columnKey: 'status', order: 'ascend' }, WORK_ORDER_SORT_FIELDS, WORK_ORDER_DEFAULT_SORT)).toBe('status');
    });
});
