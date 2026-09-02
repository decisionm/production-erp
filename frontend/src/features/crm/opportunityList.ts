/**
 * THE OPPORTUNITY LIST'S URL STATE (useListParams): `sort`, `page`,
 * `per_page`. Pure, pinned by opportunityList.test.ts. The Customer
 * column shows a relation's name and is not sortable here.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListOpportunitiesRequest), id included. */
export const OPPORTUNITY_SORT_FIELDS: readonly string[] = ['id', 'name', 'estimated_value', 'probability', 'expected_close_date', 'stage'];
/** OpportunityService's order when no sort is asked for: newest first. */
export const OPPORTUNITY_DEFAULT_SORT = '-id';

export const OPPORTUNITY_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: OPPORTUNITY_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type OpportunityListParams = ListParams & { sort?: string };

export interface OpportunityListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function opportunityServerFilters(params: OpportunityListParams): OpportunityListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['crm', 'opportunities'] prefix every opportunity mutation already invalidates. */
export function opportunitiesQueryKey(filters: OpportunityListFilters) {
    return ['crm', 'opportunities', 'list', filters] as const;
}
