import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';

/**
 * THE BOM REGISTER'S URL STATE (useListParams, 03-Sep-2026): `item_id` is
 * the filter the endpoint always took, `sort` / `page` / `per_page` are the
 * shared list contract. Pure — the vitest beside it pins the round trip.
 */

/** The columns the server sorts the register on (ListBomsRequest), besides id. */
export const BOM_SORT_FIELDS: readonly string[] = ['id', 'name', 'version', 'is_active'];
/** BomService's order when no sort is asked for: newest first. */
export const BOM_DEFAULT_SORT = '-id';

export const BOM_LIST_SPEC: ListParamsSpec = {
    numbers: ['item_id'],
    strings: ['sort'],
    allowed: { sort: BOM_SORT_FIELDS.flatMap((field) => [field, `-${field}`]) },
};

export interface BomListParams extends ListParams {
    item_id?: number;
    sort?: string;
}

/** What GET /production/boms accepts. */
export interface BomListFilters {
    item_id?: number;
    sort?: string;
    page?: number;
    per_page?: number;
}

/** The page's URL → the request the server gets. Compacted: `{}` and `{ sort: '' }` are one key. */
export function bomServerFilters(params: BomListParams): BomListFilters {
    return compactParams({ item_id: params.item_id, sort: params.sort, page: params.page, per_page: params.per_page });
}

/** Under the ['production', 'boms'] prefix every BOM mutation already invalidates. */
export function bomsQueryKey(filters: BomListFilters) {
    return ['production', 'boms', 'list', filters] as const;
}
