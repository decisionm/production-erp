/**
 * THE USER LIST'S URL STATE (useListParams): `sort`, `page`, `per_page`.
 * Pure, pinned by userList.test.ts. Roles are a relation and are not
 * sortable here.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/** The columns the server sorts on (ListUsersRequest), id included. */
export const USER_SORT_FIELDS: readonly string[] = ['id', 'name', 'email', 'is_active'];
/** UserService's order when no sort is asked for: name, ascending. */
export const USER_DEFAULT_SORT = 'name';

export const USER_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: USER_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type UserListParams = ListParams & { sort?: string };

export interface UserListFilters {
    sort?: string;
    page?: number;
    per_page?: number;
}

export function userServerFilters(params: UserListParams): UserListFilters {
    return compactParams({ sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['access', 'users'] prefix every user mutation already invalidates. */
export function usersQueryKey(filters: UserListFilters) {
    return ['access', 'users', 'list', filters] as const;
}
