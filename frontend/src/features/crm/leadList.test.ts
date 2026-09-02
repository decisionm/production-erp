import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { LEAD_DEFAULT_SORT, LEAD_LIST_SPEC, leadServerFilters } from './leadList';

describe('the lead list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        // Last contact is the latest activity's date, not a lead column.
        const params = readListParams(new URLSearchParams('sort=last_contact'), LEAD_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(leadServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-company&page=4&per_page=100'), LEAD_LIST_SPEC);

        expect(leadServerFilters(params)).toEqual({ sort: '-company', page: 4, per_page: 100 });
    });

    it('opens newest first, the service default, with no sort on the URL', () => {
        expect(LEAD_DEFAULT_SORT).toBe('-id');
        expect(leadServerFilters({})).toEqual({});
        expect(columnSortOrder('name', undefined, LEAD_DEFAULT_SORT)).toBeNull();
    });
});
