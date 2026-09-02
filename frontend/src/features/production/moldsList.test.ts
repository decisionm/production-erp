import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { MOLD_DEFAULT_SORT, MOLD_LIST_SPEC, moldServerFilters } from './moldsList';

describe('moldsList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=notes'), MOLD_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(moldServerFilters(params)).toEqual({});
    });

    it('sends a known sort and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-cavity_count&page=2'), MOLD_LIST_SPEC);

        expect(moldServerFilters(params)).toEqual({ sort: '-cavity_count', page: 2 });
    });

    it('defaults to the service order, by code, without sending it', () => {
        expect(MOLD_DEFAULT_SORT).toBe('code');
        expect(moldServerFilters(readListParams(new URLSearchParams(''), MOLD_LIST_SPEC))).toEqual({});
    });
});
