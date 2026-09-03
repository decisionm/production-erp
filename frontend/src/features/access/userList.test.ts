import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { USER_DEFAULT_SORT, USER_LIST_SPEC, userServerFilters } from './userList';

describe('the user list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        // Roles are a relation, not a user column.
        const params = readListParams(new URLSearchParams('sort=roles'), USER_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(userServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-is_active&page=2&per_page=20'), USER_LIST_SPEC);

        expect(userServerFilters(params)).toEqual({ sort: '-is_active', page: 2, per_page: 20 });
    });

    it('opens in name order, the service default, with no sort on the URL', () => {
        expect(USER_DEFAULT_SORT).toBe('name');
        expect(userServerFilters({})).toEqual({});
        expect(columnSortOrder('name', undefined, USER_DEFAULT_SORT)).toBe('ascend');
    });
});
