import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { OPPORTUNITY_DEFAULT_SORT, OPPORTUNITY_LIST_SPEC, opportunityServerFilters } from './opportunityList';

describe('the opportunity list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        // The customer is a relation, not an opportunity column.
        const params = readListParams(new URLSearchParams('sort=customer'), OPPORTUNITY_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(opportunityServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=expected_close_date&page=2'), OPPORTUNITY_LIST_SPEC);

        expect(opportunityServerFilters(params)).toEqual({ sort: 'expected_close_date', page: 2 });
    });

    it('opens newest first, the service default, with no sort on the URL', () => {
        expect(OPPORTUNITY_DEFAULT_SORT).toBe('-id');
        expect(opportunityServerFilters({})).toEqual({});
        expect(columnSortOrder('estimated_value', undefined, OPPORTUNITY_DEFAULT_SORT)).toBeNull();
    });
});
