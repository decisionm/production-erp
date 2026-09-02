import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE SCRAP-REASON MASTER'S URL STATE (useListParams, 03-Sep-2026): the
 * shared sort / page contract. The master's own order is by name, so
 * `name` is the default and never sent.
 */

/** The columns the server sorts the master on (ListScrapReasonsRequest), besides id. */
export const SCRAP_REASON_SORT_FIELDS: readonly string[] = ['id', 'code', 'name', 'is_active'];
/** ScrapReasonService's order when no sort is asked for: by name. */
export const SCRAP_REASON_DEFAULT_SORT = 'name';

export const SCRAP_REASON_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: SCRAP_REASON_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface ScrapReasonListParams extends ListParams {
    sort?: string;
}

/** What GET /production/scrap-reasons accepts. */
export interface ScrapReasonListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function scrapReasonServerFilters(params: ScrapReasonListParams): ScrapReasonListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'scrap-reasons'] prefix every scrap-reason mutation already invalidates. */
export function scrapReasonsQueryKey(filters: ScrapReasonListFilters) {
    return ['production', 'scrap-reasons', 'list', filters] as const;
}
