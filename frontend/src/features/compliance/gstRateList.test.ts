import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder } from '@/lib/tableSort';
import { GST_RATE_DEFAULT_SORT, GST_RATE_LIST_SPEC, gstRateServerFilters } from './gstRateList';

describe('the GST rate list', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=cess'), GST_RATE_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(gstRateServerFilters(params)).toEqual({});
    });

    it('sends a known sort, with the page, to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-rate_percent&page=2&per_page=20'), GST_RATE_LIST_SPEC);

        expect(gstRateServerFilters(params)).toEqual({ sort: '-rate_percent', page: 2, per_page: 20 });
    });

    it('opens in HSN/SAC order, the service default, with no sort on the URL', () => {
        expect(GST_RATE_DEFAULT_SORT).toBe('hsn_sac_code');
        expect(gstRateServerFilters({})).toEqual({});
        expect(columnSortOrder('hsn_sac_code', undefined, GST_RATE_DEFAULT_SORT)).toBe('ascend');
    });
});
