import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { GL_ACCOUNT_DEFAULT_SORT, GL_ACCOUNT_LIST_SPEC, glAccountServerFilters } from './glAccountList';

describe('the chart of accounts list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=colour'), GL_ACCOUNT_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(glAccountServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-name&page=3&per_page=50'), GL_ACCOUNT_LIST_SPEC);

        expect(glAccountServerFilters(params)).toEqual({ sort: '-name', page: 3, per_page: 50 });
    });

    it('opens in code order, the service default, with no sort on the URL', () => {
        expect(GL_ACCOUNT_DEFAULT_SORT).toBe('code');
        expect(glAccountServerFilters({})).toEqual({});
        expect(columnSortOrder('code', undefined, GL_ACCOUNT_DEFAULT_SORT)).toBe('ascend');
        expect(columnSortOrder('name', undefined, GL_ACCOUNT_DEFAULT_SORT)).toBeNull();
    });
});
