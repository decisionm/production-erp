import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { SCRAP_REASON_DEFAULT_SORT, SCRAP_REASON_LIST_SPEC, scrapReasonServerFilters } from './scrapReasonsList';

describe('scrapReasonsList', () => {
    it('drops an unknown sort', () => {
        const params = readListParams(new URLSearchParams('sort=description'), SCRAP_REASON_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(scrapReasonServerFilters(params)).toEqual({});
    });

    it('sends a known sort and the page to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-code&page=2'), SCRAP_REASON_LIST_SPEC);

        expect(scrapReasonServerFilters(params)).toEqual({ sort: '-code', page: 2 });
    });

    it('defaults to the service order, by name, without sending it', () => {
        expect(SCRAP_REASON_DEFAULT_SORT).toBe('name');
        expect(scrapReasonServerFilters(readListParams(new URLSearchParams(''), SCRAP_REASON_LIST_SPEC))).toEqual({});
    });
});
