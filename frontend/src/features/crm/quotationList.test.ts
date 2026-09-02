import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { QUOTATION_DEFAULT_SORT, QUOTATION_LIST_SPEC, quotationServerFilters } from './quotationList';

describe('the quotation list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=customer'), QUOTATION_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(quotationServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-quotation_date&page=2&per_page=50'), QUOTATION_LIST_SPEC);

        expect(quotationServerFilters(params)).toEqual({ sort: '-quotation_date', page: 2, per_page: 50 });
    });

    it('opens newest first, the service default, with no sort on the URL', () => {
        expect(QUOTATION_DEFAULT_SORT).toBe('-id');
        expect(quotationServerFilters({})).toEqual({});
        expect(columnSortOrder('id', undefined, QUOTATION_DEFAULT_SORT)).toBe('descend');
    });
});
