/**
 * THE WORK ORDER REGISTER'S URL STATE (03-Sep-2026) — the pure half of
 * WorkOrdersPage: which URL keys the page owns, which columns the server
 * sorts on (ListMaintenanceWorkOrdersRequest), and how the URL becomes the
 * request. Module-level, as useListParams requires; pinned by
 * workOrderList.test.ts.
 *
 * parts_cost / total_cost are in the allowed set because the column exists
 * for finance eyes; for anyone else the column is not drawn, so its sorter
 * is never offered, and the server refuses the sort (FC-06).
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { MaintenanceWorkOrderListFilters } from './types';

/** The columns the server sorts the register on, besides id. */
export const WORK_ORDER_SORT_FIELDS: readonly string[] = [
    'id',
    'type',
    'status',
    'reported_date',
    'labor_cost',
    'parts_cost',
    'total_cost',
];
/** MaintenanceWorkOrderService's order when no sort is asked for: newest first. */
export const WORK_ORDER_DEFAULT_SORT = '-id';

export const WORK_ORDER_LIST_SPEC: ListParamsSpec = {
    numbers: ['asset_id'],
    strings: ['sort'],
    allowed: { sort: WORK_ORDER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type WorkOrderListParams = ListParams & { asset_id?: number; sort?: string };

/** The page's URL → the request the server gets. Compacted: `{}` and `{ sort: '' }` are one key. */
export function workOrderServerFilters(params: WorkOrderListParams): MaintenanceWorkOrderListFilters {
    return compactParams(params);
}

/** Under the ['maintenance', 'work-orders'] prefix every work-order mutation already invalidates. */
export function workOrdersQueryKey(filters: MaintenanceWorkOrderListFilters) {
    return ['maintenance', 'work-orders', 'list', filters] as const;
}
