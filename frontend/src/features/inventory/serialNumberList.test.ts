import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { SERIAL_NUMBER_DEFAULT_SORT, SERIAL_NUMBER_LIST_SPEC, serialNumberListRequest } from './serialNumberList';

describe('what the serial numbers list asks the server for', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=warehouse'), SERIAL_NUMBER_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(serialNumberListRequest(params)).toEqual({});
    });

    it('sends a known column, the search and the page to the server', () => {
        const params = readListParams(new URLSearchParams('q=SN-9&sort=status&page=2'), SERIAL_NUMBER_LIST_SPEC);

        expect(serialNumberListRequest(params)).toEqual({ search: 'SN-9', sort: 'status', page: 2 });
        expect(serialNumberListRequest({ sort: '-serial_number' })).toEqual({ sort: '-serial_number' });
    });

    it('leaves the default order — newest first — off the request', () => {
        expect(SERIAL_NUMBER_DEFAULT_SORT).toBe('-id');
        expect(serialNumberListRequest({ sort: '-id' })).toEqual({});
    });
});
