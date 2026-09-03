import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE ROUTING REGISTER'S URL STATE (useListParams, 03-Sep-2026): `item_id`
 * is the filter the endpoint always took, `sort` / `page` / `per_page` are
 * the shared list contract.
 */

/** The columns the server sorts the register on (ListRoutingsRequest), besides id. */
export const ROUTING_SORT_FIELDS: readonly string[] = ['id', 'name'];
/** RoutingService's order when no sort is asked for: newest first. */
export const ROUTING_DEFAULT_SORT = '-id';

export const ROUTING_LIST_SPEC: ListParamsSpec = {
    numbers: ['item_id'],
    strings: ['sort'],
    allowed: { sort: ROUTING_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface RoutingListParams extends ListParams {
    item_id?: number;
    sort?: string;
}

/** What GET /production/routings accepts. */
export interface RoutingListFilters {
    item_id?: number;
    sort?: string;
    page?: number;
    per_page?: number;
}

export function routingServerFilters(params: RoutingListParams): RoutingListFilters {
    return compactParams({ item_id: params.item_id, sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'routings'] prefix every routing mutation already invalidates. */
export function routingsQueryKey(filters: RoutingListFilters) {
    return ['production', 'routings', 'list', filters] as const;
}
