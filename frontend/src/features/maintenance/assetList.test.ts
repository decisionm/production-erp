import { describe, expect, it } from 'vitest';
import { readListParams } from '@/lib/listParams';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { ASSET_DEFAULT_SORT, ASSET_LIST_SPEC, ASSET_SORT_FIELDS, assetServerFilters } from './assetList';

describe('the asset register reads its sort from the URL', () => {
    it('drops a sort nobody defined rather than sending it to a 422', () => {
        const params = readListParams(new URLSearchParams('sort=colour'), ASSET_LIST_SPEC);

        expect(params.sort).toBeUndefined();
        expect(assetServerFilters(params)).toEqual({});
    });

    it('sends a known sort, the page and the page size to the server', () => {
        const params = readListParams(new URLSearchParams('sort=-code&page=2&per_page=50'), ASSET_LIST_SPEC);

        expect(assetServerFilters(params)).toEqual({ sort: '-code', page: 2, per_page: 50 });
    });

    it('accepts every sortable column in both directions', () => {
        for (const field of ASSET_SORT_FIELDS) {
            for (const sort of [field, `-${field}`]) {
                expect(readListParams(new URLSearchParams(`sort=${sort}`), ASSET_LIST_SPEC).sort).toBe(sort);
            }
        }
    });

    it('opens in name order — what AssetService defaults to — and keeps that order off the URL', () => {
        expect(ASSET_DEFAULT_SORT).toBe('name');
        expect(columnSortOrder('name', undefined, ASSET_DEFAULT_SORT)).toBe('ascend');
        expect(columnSortOrder('code', undefined, ASSET_DEFAULT_SORT)).toBeNull();
        expect(sortParamFromSorter({ columnKey: 'name', order: 'ascend' }, ASSET_SORT_FIELDS, ASSET_DEFAULT_SORT)).toBeUndefined();
        expect(sortParamFromSorter({ columnKey: 'name', order: 'descend' }, ASSET_SORT_FIELDS, ASSET_DEFAULT_SORT)).toBe('-name');
    });
});
