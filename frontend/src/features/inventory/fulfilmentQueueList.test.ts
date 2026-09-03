import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import {
    FULFILMENT_QUEUE_DEFAULT_SORT,
    FULFILMENT_QUEUE_LIST_SPEC,
    FULFILMENT_QUEUE_SORT_FIELDS,
    fulfilmentQueueRequest,
} from './fulfilmentQueueList';

describe('what the store fulfilment queue asks the server for', () => {
    it('drops a sort on a computed column rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=-shortfall'), FULFILMENT_QUEUE_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(fulfilmentQueueRequest(params)).toEqual({});
    });

    it('sends a real column of the base query, with the state and the page', () => {
        const params = readListParams(
            new URLSearchParams('state=over_reserved&sort=-quantity&page=2&per_page=50'),
            FULFILMENT_QUEUE_LIST_SPEC,
        );

        expect(fulfilmentQueueRequest(params)).toEqual({ state: 'over_reserved', sort: '-quantity', page: 2, per_page: 50 });
        expect(fulfilmentQueueRequest({ sort: 'sales_order_id' })).toEqual({ sort: 'sales_order_id' });
    });

    it('drops a state the server does not know, so its absence stays "needs the store"', () => {
        const params = readListParams(new URLSearchParams('state=everything'), FULFILMENT_QUEUE_LIST_SPEC);

        expect(params.state).toBeUndefined();
    });

    it('has no default column: the bare request is the server\'s blocker order', () => {
        expect(FULFILMENT_QUEUE_DEFAULT_SORT).toBeUndefined();
        expect(fulfilmentQueueRequest({})).toEqual({});
        for (const field of FULFILMENT_QUEUE_SORT_FIELDS) {
            expect(columnSortOrder(field, undefined, FULFILMENT_QUEUE_DEFAULT_SORT)).toBeNull();
        }
    });
});
