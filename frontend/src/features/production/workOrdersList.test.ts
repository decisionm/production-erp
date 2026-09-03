import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { WORK_ORDER_DEFAULT_SORT, WORK_ORDER_LIST_SPEC, workOrderServerFilters } from './workOrdersList';

describe('workOrdersList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=material_cost'), WORK_ORDER_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(workOrderServerFilters(params)).toEqual({});
    });

    it('sends a known sort and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-scheduled_date&page=2&per_page=20'), WORK_ORDER_LIST_SPEC);

        expect(workOrderServerFilters(params)).toEqual({ sort: '-scheduled_date', page: 2, per_page: 20 });
    });

    it('defaults to the service order, newest first, without sending it', () => {
        expect(WORK_ORDER_DEFAULT_SORT).toBe('-id');
        expect(workOrderServerFilters(readListParams(new URLSearchParams(''), WORK_ORDER_LIST_SPEC))).toEqual({});
    });
});
