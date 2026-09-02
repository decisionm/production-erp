import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE REWORK ORDER REGISTER'S URL STATE (useListParams, 03-Sep-2026): the
 * shared sort / page contract — the endpoint took no filter before.
 */

/** The columns the server sorts the register on (ListReworkOrdersRequest), besides id. */
export const REWORK_ORDER_SORT_FIELDS: readonly string[] = [
    'id',
    'quantity_input',
    'quantity_recovered',
    'status',
    'total_cost',
];
/** ReworkOrderService's order when no sort is asked for: newest first. */
export const REWORK_ORDER_DEFAULT_SORT = '-id';

export const REWORK_ORDER_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: REWORK_ORDER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface ReworkOrderListParams extends ListParams {
    sort?: string;
}

/** What GET /production/rework-orders accepts. */
export interface ReworkOrderListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function reworkOrderServerFilters(params: ReworkOrderListParams): ReworkOrderListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'rework-orders'] prefix every rework mutation already invalidates. */
export function reworkOrdersQueryKey(filters: ReworkOrderListFilters) {
    return ['production', 'rework-orders', 'list', filters] as const;
}
