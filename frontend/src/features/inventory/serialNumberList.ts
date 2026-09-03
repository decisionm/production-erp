/**
 * THE SERIAL NUMBERS LIST'S READING RULES — the pure half of
 * SerialNumbersPage (03-Sep-2026), the batch list's shape: `q` is the
 * server's `search`, `sort` one of ListSerialNumbersRequest::SORTABLE.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { ListParams as SerialNumberListRequest } from '@/features/inventory/api';

export const SERIAL_NUMBER_SORT_FIELDS: readonly string[] = ['serial_number', 'status'];

/** SerialNumberService's order when no sort is asked for: newest first. */
export const SERIAL_NUMBER_DEFAULT_SORT = '-id';

export const SERIAL_NUMBER_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: SERIAL_NUMBER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type SerialNumberListParams = ListParams & { sort?: string };

export function serialNumberListRequest(params: SerialNumberListParams): SerialNumberListRequest {
    const { q, sort, page, per_page } = compactParams(params);
    const request: SerialNumberListRequest = {};

    if (typeof q === 'string') request.search = q;
    if (typeof page === 'number' && page > 1) request.page = page;
    if (typeof per_page === 'number') request.per_page = per_page;
    if (typeof sort === 'string' && sort !== SERIAL_NUMBER_DEFAULT_SORT) request.sort = sort;

    return request;
}
