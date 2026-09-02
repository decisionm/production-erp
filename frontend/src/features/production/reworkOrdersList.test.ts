import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { REWORK_ORDER_DEFAULT_SORT, REWORK_ORDER_LIST_SPEC, reworkOrderServerFilters } from './reworkOrdersList';

describe('reworkOrdersList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=labor_cost'), REWORK_ORDER_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(reworkOrderServerFilters(params)).toEqual({});
    });

    it('sends a known sort and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-total_cost&page=2'), REWORK_ORDER_LIST_SPEC);

        expect(reworkOrderServerFilters(params)).toEqual({ sort: '-total_cost', page: 2 });
    });

    it('defaults to the service order, newest first, without sending it', () => {
        expect(REWORK_ORDER_DEFAULT_SORT).toBe('-id');
        expect(reworkOrderServerFilters(readListParams(new URLSearchParams(''), REWORK_ORDER_LIST_SPEC))).toEqual({});
    });
});
