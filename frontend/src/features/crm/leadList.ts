/**
 * THE LEAD LIST'S URL STATE (useListParams): `sort`, `page`, `per_page`.
 * Pure, pinned by leadList.test.ts. "Last contact" and "next follow-up"
 * are read off the latest activity, not a lead column, so they are not
 * sortable here.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListLeadsRequest), id included. */
export const LEAD_SORT_FIELDS: readonly string[] = ['id', 'name', 'company', 'email', 'status'];
/** LeadService's order when no sort is asked for: newest first. */
export const LEAD_DEFAULT_SORT = '-id';

export const LEAD_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: LEAD_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type LeadListParams = ListParams & { sort?: string };

export interface LeadListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function leadServerFilters(params: LeadListParams): LeadListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['crm', 'leads'] prefix every lead mutation already invalidates. */
export function leadsQueryKey(filters: LeadListFilters) {
    return ['crm', 'leads', 'list', filters] as const;
}
