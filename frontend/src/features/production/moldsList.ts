import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE MOULD MASTER'S URL STATE (useListParams, 03-Sep-2026): the shared
 * sort / page contract. The master's own order is by code, so `code` is
 * the default and never sent.
 */

/** The columns the server sorts the master on (ListMoldsRequest), besides id. */
export const MOLD_SORT_FIELDS: readonly string[] = ['id', 'code', 'name', 'cavity_count', 'status'];
/** MoldService's order when no sort is asked for: by code. */
export const MOLD_DEFAULT_SORT = 'code';

export const MOLD_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: MOLD_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface MoldListParams extends ListParams {
    sort?: string;
}

/** What GET /production/molds accepts. */
export interface MoldListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function moldServerFilters(params: MoldListParams): MoldListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'molds'] prefix every mould mutation already invalidates. */
export function moldsQueryKey(filters: MoldListFilters) {
    return ['production', 'molds', 'list', filters] as const;
}
