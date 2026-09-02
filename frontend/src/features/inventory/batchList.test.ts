import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { BATCH_DEFAULT_SORT, BATCH_LIST_SPEC, batchListRequest } from './batchList';

describe('what the batches list asks the server for', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=colour'), BATCH_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(batchListRequest(params)).toEqual({});
    });

    it('sends a known column, the search and the page to the server', () => {
        const params = readListParams(new URLSearchParams('q=B-12&sort=-expiry_date&page=2&per_page=50'), BATCH_LIST_SPEC);

        expect(batchListRequest(params)).toEqual({ search: 'B-12', sort: '-expiry_date', page: 2, per_page: 50 });
    });

    it('leaves the default order — newest first — off the request', () => {
        expect(BATCH_DEFAULT_SORT).toBe('-id');
        expect(batchListRequest({ sort: '-id', q: ' ' })).toEqual({});
    });
});
