/**
 * THE ASSET REGISTER'S URL STATE (03-Sep-2026) — the pure half of
 * AssetsPage: which URL keys the page owns, which columns the server sorts
 * on (ListAssetsRequest::SORTABLE), and how the URL becomes the request.
 * Module-level, as useListParams requires; pinned by assetList.test.ts.
 */
import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
import type { AssetListFilters } from './types';

/** The columns the server sorts the register on, besides id. */
export const ASSET_SORT_FIELDS: readonly string[] = ['id', 'code', 'name', 'category', 'location', 'status'];
/** AssetService's order when no sort is asked for: by name. */
export const ASSET_DEFAULT_SORT = 'name';

export const ASSET_LIST_SPEC: ListParamsSpec = {
    strings: ['sort'],
    allowed: { sort: ASSET_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export type AssetListParams = ListParams & { sort?: string };

/** The page's URL → the request the server gets. Compacted: `{}` and `{ sort: '' }` are one key. */
export function assetServerFilters(params: AssetListParams): AssetListFilters {
    return compactParams(params);
}

/** Under the ['maintenance', 'assets'] prefix every asset mutation already invalidates. */
export function assetsQueryKey(filters: AssetListFilters) {
    return ['maintenance', 'assets', 'list', filters] as const;
}
