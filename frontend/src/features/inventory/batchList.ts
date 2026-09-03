/**
 * THE BATCHES LIST'S READING RULES — the pure half of BatchesPage
 * (03-Sep-2026). The URL's `q` is the server's `search` (batch number or
 * item); `sort` is one of ListBatchesRequest::SORTABLE in the ListSort
 * spelling. Paged and ordered on the server, never in the browser.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { ListParams as BatchListRequest } from '@/features/inventory/api';

export const BATCH_SORT_FIELDS: readonly string[] = ['batch_number', 'manufactured_date', 'expiry_date'];

/** BatchService's order when no sort is asked for: newest first. */
export const BATCH_DEFAULT_SORT = '-id';

export const BATCH_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: BATCH_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type BatchListParams = ListParams & { sort?: string };

export function batchListRequest(params: BatchListParams): BatchListRequest {
    const { q, sort, page, per_page } = compactParams(params);
    const request: BatchListRequest = {};

    if (typeof q === 'string') request.search = q;
    if (typeof page === 'number' && page > 1) request.page = page;
    if (typeof per_page === 'number') request.per_page = per_page;
    if (typeof sort === 'string' && sort !== BATCH_DEFAULT_SORT) request.sort = sort;

    return request;
}
