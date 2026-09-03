import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { CUSTOMER_DEFAULT_SORT, CUSTOMER_LIST_SPEC, customerListRequest } from './customerList';

describe('what the customer master asks the server for', () => {
    it('opens at page one, fifty to a page, in the service\'s own name order', () => {
        expect(CUSTOMER_DEFAULT_SORT).toBe('name');
        expect(customerListRequest({})).toEqual({ page: 1, per_page: 50 });
        expect(customerListRequest({ sort: 'name' })).toEqual({ page: 1, per_page: 50 });
    });

    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=email'), CUSTOMER_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(customerListRequest(params)).toEqual({ page: 1, per_page: 50 });
    });

    it('sends a known column, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-state_code&page=3&per_page=100'), CUSTOMER_LIST_SPEC);

        expect(customerListRequest(params)).toEqual({ page: 3, per_page: 100, sort: '-state_code' });
        expect(customerListRequest({ sort: 'is_active' })).toEqual({ page: 1, per_page: 50, sort: 'is_active' });
    });
});
