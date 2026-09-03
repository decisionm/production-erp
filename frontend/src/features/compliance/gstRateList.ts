/**
 * THE GST RATE LIST'S URL STATE (useListParams): `sort`, `page`,
 * `per_page`. Pure, pinned by gstRateList.test.ts.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListGstRatesRequest), id included. */
export const GST_RATE_SORT_FIELDS: readonly string[] = ['id', 'hsn_sac_code', 'description', 'rate_percent', 'is_active'];
/** GstRateService's order when no sort is asked for: HSN/SAC code, ascending. */
export const GST_RATE_DEFAULT_SORT = 'hsn_sac_code';

export const GST_RATE_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: GST_RATE_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type GstRateListParams = ListParams & { sort?: string };

export interface GstRateListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function gstRateServerFilters(params: GstRateListParams): GstRateListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['compliance', 'gst-rates'] prefix every rate mutation already invalidates. */
export function gstRatesQueryKey(filters: GstRateListFilters) {
    return ['compliance', 'gst-rates', 'list', filters] as const;
}
