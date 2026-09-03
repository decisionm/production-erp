import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { SHIFT_DEFAULT_SORT, SHIFT_LIST_SPEC, shiftServerFilters } from './shiftsList';

describe('shiftsList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=end_time'), SHIFT_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(shiftServerFilters(params)).toEqual({});
    });

    it('sends a known sort and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-name&page=2'), SHIFT_LIST_SPEC);

        expect(shiftServerFilters(params)).toEqual({ sort: '-name', page: 2 });
    });

    it('defaults to the service order, by start time, without sending it', () => {
        expect(SHIFT_DEFAULT_SORT).toBe('start_time');
        expect(shiftServerFilters(readListParams(new URLSearchParams(''), SHIFT_LIST_SPEC))).toEqual({});
    });
});
