import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE SUBCONTRACT ORDER REGISTER'S URL STATE (useListParams, 03-Sep-2026):
 * the shared sort / page contract — the endpoint took no filter before.
 */

/** The columns the server sorts the register on (ListSubcontractOrdersRequest), besides id. */
export const SUBCONTRACT_ORDER_SORT_FIELDS: readonly string[] = ['id', 'quantity_planned', 'quantity_received', 'status'];
/** SubcontractOrderService's order when no sort is asked for: newest first. */
export const SUBCONTRACT_ORDER_DEFAULT_SORT = '-id';

export const SUBCONTRACT_ORDER_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: SUBCONTRACT_ORDER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface SubcontractOrderListParams extends ListParams {
    sort?: string;
}

/** What GET /production/subcontract-orders accepts. */
export interface SubcontractOrderListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function subcontractOrderServerFilters(params: SubcontractOrderListParams): SubcontractOrderListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'subcontract-orders'] prefix every subcontract mutation already invalidates. */
export function subcontractOrdersQueryKey(filters: SubcontractOrderListFilters) {
    return ['production', 'subcontract-orders', 'list', filters] as const;
}
