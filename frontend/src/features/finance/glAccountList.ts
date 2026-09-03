/**
 * THE CHART OF ACCOUNTS' URL STATE (useListParams): `sort`, `page`,
 * `per_page`. Pure — no React, no axios — so the URL → request mapping is
 * pinned by glAccountList.test.ts.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListGlAccountsRequest), id included. */
export const GL_ACCOUNT_SORT_FIELDS: readonly string[] = ['id', 'code', 'name', 'type', 'is_active'];
/** GLAccountService's order when no sort is asked for: code, ascending. */
export const GL_ACCOUNT_DEFAULT_SORT = 'code';

export const GL_ACCOUNT_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: GL_ACCOUNT_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type GLAccountListParams = ListParams & { sort?: string };

/** Exactly what listGLAccounts is asked for — and the query key. */
export interface GLAccountListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

/** The page's URL → the request the server gets; compacted so `{}` and a bare path are one key. */
export function glAccountServerFilters(params: GLAccountListParams): GLAccountListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['finance', 'gl-accounts'] prefix every account mutation already invalidates. */
export function glAccountsQueryKey(filters: GLAccountListFilters) {
    return ['finance', 'gl-accounts', 'list', filters] as const;
}
