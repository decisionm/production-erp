import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import {
    SUBCONTRACT_ORDER_DEFAULT_SORT,
    SUBCONTRACT_ORDER_LIST_SPEC,
    subcontractOrderServerFilters,
} from './subcontractOrdersList';

describe('subcontractOrdersList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=vendor'), SUBCONTRACT_ORDER_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(subcontractOrderServerFilters(params)).toEqual({});
    });

    it('sends a known sort and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=status&page=2'), SUBCONTRACT_ORDER_LIST_SPEC);

        expect(subcontractOrderServerFilters(params)).toEqual({ sort: 'status', page: 2 });
    });

    it('defaults to the service order, newest first, without sending it', () => {
        expect(SUBCONTRACT_ORDER_DEFAULT_SORT).toBe('-id');
        expect(subcontractOrderServerFilters(readListParams(new URLSearchParams(''), SUBCONTRACT_ORDER_LIST_SPEC))).toEqual({});
    });
});
