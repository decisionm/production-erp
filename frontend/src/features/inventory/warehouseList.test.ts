import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { WAREHOUSE_DEFAULT_SORT, WAREHOUSE_LIST_SPEC, warehouseListRequest } from './warehouseList';

describe('what the warehouses list asks the server for', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=colour'), WAREHOUSE_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(warehouseListRequest(params)).toEqual({});
    });

    it('sends a known column, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-code&page=3&per_page=50'), WAREHOUSE_LIST_SPEC);

        expect(warehouseListRequest(params)).toEqual({ sort: '-code', page: 3, per_page: 50 });
        expect(warehouseListRequest({ sort: 'is_active' })).toEqual({ sort: 'is_active' });
    });

    it('leaves the default order — name — off the request, as the service defaults to it', () => {
        expect(WAREHOUSE_DEFAULT_SORT).toBe('name');
        expect(warehouseListRequest({ sort: 'name', page: 1 })).toEqual({});
    });
});
