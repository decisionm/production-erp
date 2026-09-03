import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { ROUTING_DEFAULT_SORT, ROUTING_LIST_SPEC, routingServerFilters } from './routingsList';

describe('routingsList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=operations'), ROUTING_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(routingServerFilters(params)).toEqual({});
    });

    it('sends a known sort, the item filter and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=name&item_id=3&page=3'), ROUTING_LIST_SPEC);

        expect(routingServerFilters(params)).toEqual({ sort: 'name', item_id: 3, page: 3 });
    });

    it('defaults to the service order, newest first, without sending it', () => {
        expect(ROUTING_DEFAULT_SORT).toBe('-id');
        expect(routingServerFilters(readListParams(new URLSearchParams(''), ROUTING_LIST_SPEC))).toEqual({});
    });
});
