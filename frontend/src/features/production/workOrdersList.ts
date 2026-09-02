import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE WORK ORDER REGISTER'S URL STATE (useListParams, 03-Sep-2026): the
 * shared sort / page contract — the endpoint took no filter before.
 */

/** The columns the server sorts the register on (ListWorkOrdersRequest), besides id. */
export const WORK_ORDER_SORT_FIELDS: readonly string[] = [
    'id',
    'scheduled_date',
    'quantity_planned',
    'quantity_completed',
    'status',
];
/** WorkOrderService's order when no sort is asked for: newest first. */
export const WORK_ORDER_DEFAULT_SORT = '-id';

export const WORK_ORDER_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: WORK_ORDER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface WorkOrderListParams extends ListParams {
    sort?: string;
}

/** What GET /production/work-orders accepts. */
export interface WorkOrderListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function workOrderServerFilters(params: WorkOrderListParams): WorkOrderListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'work-orders'] prefix every work-order mutation already invalidates. */
export function workOrdersQueryKey(filters: WorkOrderListFilters) {
    return ['production', 'work-orders', 'list', filters] as const;
}
