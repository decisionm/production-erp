/**
 * THE QUOTATION LIST'S URL STATE (useListParams): `sort`, `page`,
 * `per_page`. Pure, pinned by quotationList.test.ts. The Customer column
 * shows a relation's name and is not sortable here; the lines table in
 * the drawer keeps document order.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListQuotationsRequest), id included. */
export const QUOTATION_SORT_FIELDS: readonly string[] = ['id', 'status', 'quotation_date'];
/** QuotationService's order when no sort is asked for: newest first. */
export const QUOTATION_DEFAULT_SORT = '-id';

export const QUOTATION_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: QUOTATION_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type QuotationListParams = ListParams & { sort?: string };

export interface QuotationListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function quotationServerFilters(params: QuotationListParams): QuotationListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['crm', 'quotations'] prefix every quotation mutation already invalidates. */
export function quotationsQueryKey(filters: QuotationListFilters) {
    return ['crm', 'quotations', 'list', filters] as const;
}
