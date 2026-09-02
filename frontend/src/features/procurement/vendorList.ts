/**
 * THE VENDOR MASTER'S URL KEYS — the pure half of the Vendors tab.
 *
 * `classification` (DEC-20260902-026) is the tab's filter: unset is the
 * default view (the three material classes); the pseudo-value
 * UNCLASSIFIED_FILTER_VALUE is split off before it reaches the API as
 * `unclassified=1`. `sort` (03-Sep-2026) is one of ListVendorsRequest::
 * SORTABLE in the ListSort spelling — ordered on the SERVER, because the
 * master is paged (628 rows after the ledger import). Absent is name order.
 */
import type { ListParams, ListParamsSpec } from '@/lib/listParams';
import { VENDOR_CLASSIFICATIONS } from '@/features/procurement/vendorClassification';

/** The Vendors tab's own pseudo-class — sent as `unclassified=1`, never as a real classification value. */
export const UNCLASSIFIED_FILTER_VALUE = '__unclassified';

export const VENDOR_SORT_FIELDS: readonly string[] = ['code', 'name', 'state_code', 'is_active'];

/** VendorService's order when no sort is asked for: name. */
export const VENDOR_DEFAULT_SORT = 'name';

export interface VendorListParams extends ListParams {
    classification?: string[];
    sort?: string;
}

/** Module-level, as useListParams requires. */
export const VENDOR_LIST_SPEC: ListParamsSpec = {
    lists: ['classification'],
    strings: ['sort'],
    allowed: {
        classification: [...VENDOR_CLASSIFICATIONS.map((c) => c.value), UNCLASSIFIED_FILTER_VALUE],
        sort: VENDOR_SORT_FIELDS.flatMap((field) => [field, `-${field}`]),
    },
};

/** The URL's `sort` → what listVendors is handed; the default order is nothing at all. */
export function vendorListSort(sort: string | undefined): string | undefined {
    const value = (sort ?? '').trim();
    if (value === '' || value === VENDOR_DEFAULT_SORT) return undefined;

    return (VENDOR_LIST_SPEC.allowed?.sort ?? []).includes(value) ? value : undefined;
}
