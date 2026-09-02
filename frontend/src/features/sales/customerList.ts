/**
 * THE CUSTOMER MASTER'S READING RULES — the pure half of CustomersPage
 * (03-Sep-2026): the page, the page size and the sort, all in the URL.
 *
 * Ordered on the SERVER (ListCustomersRequest::SORTABLE through ListSort),
 * because the master is paged. Absent is name order, as it always read.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

export const CUSTOMER_SORT_FIELDS: readonly string[] = ['code', 'name', 'state_code', 'is_active'];

/** CustomerService's order when no sort is asked for: name. */
export const CUSTOMER_DEFAULT_SORT = 'name';

/** The page size the list opens at — what it always was, not the server's 20. */
export const CUSTOMER_DEFAULT_PER_PAGE = 50;

export const CUSTOMER_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: CUSTOMER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type CustomerListParams = ListParams & { sort?: string };

/** Exactly what listCustomers is handed, positionally, and the query key. */
export interface CustomerListRequest {
    page: number;
    per_page: number;
    sort?: string;
}

export function customerListRequest(params: CustomerListParams): CustomerListRequest {
    const { sort, page, per_page } = compactParams(params);
    const request: CustomerListRequest = { page: page ?? 1, per_page: per_page ?? CUSTOMER_DEFAULT_PER_PAGE };

    if (typeof sort === 'string' && sort !== CUSTOMER_DEFAULT_SORT) request.sort = sort;

    return request;
}
