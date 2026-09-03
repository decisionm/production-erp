/**
 * THE GST REGISTRATION LIST'S URL STATE (useListParams): `sort`, `page`,
 * `per_page`. Pure, pinned by gstRegistrationList.test.ts.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListGstRegistrationsRequest), id included. */
export const GST_REGISTRATION_SORT_FIELDS: readonly string[] = ['id', 'gstin', 'state_code', 'state_name', 'is_active'];
/**
 * GstRegistrationService's order when no sort is asked for is primary
 * first, then by state — two columns, which one `sort` value cannot spell.
 * So no column shows an arrow until one is clicked, and clearing a sort
 * returns to that order.
 */
export const GST_REGISTRATION_DEFAULT_SORT: string | undefined = undefined;

export const GST_REGISTRATION_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: GST_REGISTRATION_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type GstRegistrationListParams = ListParams & { sort?: string };

export interface GstRegistrationListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function gstRegistrationServerFilters(params: GstRegistrationListParams): GstRegistrationListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['compliance', 'gst-registrations'] prefix every registration mutation already invalidates. */
export function gstRegistrationsQueryKey(filters: GstRegistrationListFilters) {
    return ['compliance', 'gst-registrations', 'list', filters] as const;
}
