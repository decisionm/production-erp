/**
 * THE STORE FULFILMENT QUEUE'S READING RULES — the pure half of
 * StoreFulfilmentPage (03-Sep-2026): the state filter, the sort and the
 * page, all in the URL, and what the server is asked for.
 *
 * ONLY TWO COLUMNS SORT, and both on the server. The queue is computed row
 * by row and paged in PHP, so the honest sorts are the real columns of its
 * base query — the order number and the ordered quantity
 * (FulfilmentQueueService::SORTABLE). Reserved, shortfall, free and state
 * are computed per row and carry no sorter. With no sort the queue keeps its
 * own order: over-reserved first, then the order book (S8).
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { FulfilmentQueueFilters, FulfilmentState } from '@/features/inventory/types';

export const FULFILMENT_QUEUE_SORT_FIELDS: readonly string[] = ['sales_order_id', 'quantity'];

/** No single column: the server's own blocker order. */
export const FULFILMENT_QUEUE_DEFAULT_SORT = undefined;

export const FULFILMENT_QUEUE_STATES: readonly FulfilmentState[] = [
    'untouched',
    'partially_allocated',
    'awaiting_production',
    'over_reserved',
    'fully_allocated',
];

export const FULFILMENT_QUEUE_LIST_SPEC: ListParamsSpec = {
    strings: ['state', 'sort'],
    allowed: {
        state: FULFILMENT_QUEUE_STATES,
        sort: FULFILMENT_QUEUE_SORT_FIELDS.flatMap((field) => [field, `-${field}`]),
    },
};

export type FulfilmentQueueListParams = ListParams & {
    state?: FulfilmentState;
    sort?: string;
};

/** The page's URL → the request the server gets. */
export function fulfilmentQueueRequest(params: FulfilmentQueueListParams): FulfilmentQueueFilters {
    const { state, sort, page, per_page } = compactParams(params);
    const request: FulfilmentQueueFilters = {};

    if (state !== undefined) request.state = state;
    if (typeof sort === 'string') request.sort = sort;
    if (typeof page === 'number' && page > 1) request.page = page;
    if (typeof per_page === 'number') request.per_page = per_page;

    return request;
}
