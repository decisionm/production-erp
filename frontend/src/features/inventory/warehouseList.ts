/**
 * THE WAREHOUSES LIST'S READING RULES — the pure half of WarehousesPage
 * (03-Sep-2026): which URL keys it owns and what the server is asked for.
 *
 * The list is paged on the server, so its order is the server's too
 * (ListWarehousesRequest::SORTABLE through ListSort): a column sorter in the
 * browser would sort one page and present that as the order of the stores.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { ListParams as WarehouseListRequest } from '@/features/inventory/api';

export const WAREHOUSE_SORT_FIELDS: readonly string[] = ['code', 'name', 'is_active'];

/** WarehouseService's order when no sort is asked for: name. */
export const WAREHOUSE_DEFAULT_SORT = 'name';

export const WAREHOUSE_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: WAREHOUSE_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type WarehouseListParams = ListParams & { sort?: string };

/** The page's URL → the request the server gets; the default order is the bare request. */
export function warehouseListRequest(params: WarehouseListParams): WarehouseListRequest {
    const { sort, page, per_page } = compactParams(params);
    const request: WarehouseListRequest = {};

    if (typeof page === 'number' && page > 1) request.page = page;
    if (typeof per_page === 'number') request.per_page = per_page;
    if (typeof sort === 'string' && sort !== WAREHOUSE_DEFAULT_SORT) request.sort = sort;

    return request;
}
