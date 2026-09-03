import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { BOM_DEFAULT_SORT, BOM_LIST_SPEC, bomServerFilters } from './bomsList';

describe('bomsList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=item_name'), BOM_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(bomServerFilters(params)).toEqual({});
    });

    it('sends a known sort, the item filter and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-version&item_id=7&page=2&per_page=50'), BOM_LIST_SPEC);

        expect(bomServerFilters(params)).toEqual({ sort: '-version', item_id: 7, page: 2, per_page: 50 });
    });

    it('defaults to the service order, newest first, without sending it', () => {
        expect(BOM_DEFAULT_SORT).toBe('-id');
        expect(bomServerFilters(readListParams(new URLSearchParams(''), BOM_LIST_SPEC))).toEqual({});
    });
});
