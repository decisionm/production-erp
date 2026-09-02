import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE SHIFT MASTER'S URL STATE (useListParams, 03-Sep-2026): the shared
 * sort / page contract. The master's own order is the clock's, so
 * `start_time` is the default and never sent.
 */

/** The columns the server sorts the master on (ListShiftsRequest), besides id. */
export const SHIFT_SORT_FIELDS: readonly string[] = ['id', 'name', 'start_time'];
/** ShiftService's order when no sort is asked for: by start time. */
export const SHIFT_DEFAULT_SORT = 'start_time';

export const SHIFT_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: SHIFT_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface ShiftListParams extends ListParams {
    sort?: string;
}

/** What GET /production/shifts accepts besides `active`. */
export interface ShiftListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function shiftServerFilters(params: ShiftListParams): ShiftListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'shifts'] prefix every shift mutation already invalidates. */
export function shiftsQueryKey(filters: ShiftListFilters) {
    return ['production', 'shifts', 'list', filters] as const;
}
