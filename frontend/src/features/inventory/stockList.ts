/**
 * THE STOCK LIST'S READING RULES — the pure half of StockPage, kept out of
 * the component so the URL ↔ request round trip is pinned by an ordinary
 * vitest, and so the render test can seed the exact query key the page reads.
 *
 * Nothing here fetches or derives a factory value. It says which URL keys the
 * Stock page owns, how its one `sort` key becomes the server's `sort` +
 * `direction` pair, and what an empty result says.
 */
import { pageRange } from '@/lib/tableProps';
import type { Paginated } from '@/lib/types';
import { type ListParams, type ListParamsSpec, compactParams, narrowingKeys } from '@/lib/listParams';

export const STOCK_SORT_FIELDS = ['item', 'warehouse', 'quantity'] as const;
export type StockSortField = (typeof STOCK_SORT_FIELDS)[number];
export type StockSortDirection = 'asc' | 'desc';

/** The page size the list opens at — what it always was, not the server's 20. */
export const STOCK_DEFAULT_PER_PAGE = 50;

/**
 * The URL keys beyond q / page / per_page. `sort` is ONE value in the
 * Purchase Orders spelling — `quantity` ascending, `-quantity` descending —
 * so the URL carries one key and `narrowingKeys` (which skips `sort`) never
 * counts an ordering as a filter. Anything else is dropped on read.
 */
export const STOCK_LIST_SPEC: ListParamsSpec = {
    numbers: ['warehouse_id'],
    strings: ['sort'],
    allowed: { sort: [...STOCK_SORT_FIELDS, ...STOCK_SORT_FIELDS.map((field) => `-${field}`)] },
};

export type StockListParams = ListParams & {
    warehouse_id?: number;
    sort?: string;
};

/** Exactly what listStockBalances is asked for — and the query key. */
export interface StockListRequest {
    q?: string;
    warehouse_id?: number;
    sort: StockSortField;
    direction: StockSortDirection;
    page?: number;
    per_page: number;
}

function isSortField(value: string): value is StockSortField {
    return (STOCK_SORT_FIELDS as readonly string[]).includes(value);
}

/** The URL's `sort` → the server's pair. Absent or unknown is the default: item name, ascending. */
export function parseStockSort(sort: string | undefined): { sort: StockSortField; direction: StockSortDirection } {
    const raw = (sort ?? '').trim();
    const descending = raw.startsWith('-');
    const field = descending ? raw.slice(1) : raw;

    if (!isSortField(field)) return { sort: 'item', direction: 'asc' };

    return { sort: field, direction: descending ? 'desc' : 'asc' };
}

/** The server's pair → the URL's `sort`; the default order is the bare path. */
export function encodeStockSort(field: StockSortField, direction: StockSortDirection): string | undefined {
    if (field === 'item' && direction === 'asc') return undefined;

    return direction === 'desc' ? `-${field}` : field;
}

/** What antd should show on a column's sorter for the current URL. */
export function stockSortOrder(field: StockSortField, sort: string | undefined): 'ascend' | 'descend' | null {
    const current = parseStockSort(sort);

    if (current.sort !== field) return null;

    return current.direction === 'desc' ? 'descend' : 'ascend';
}

/**
 * antd's sorter change → the URL's `sort`. Clearing (antd's third click)
 * returns to item-name order rather than leaving the list in whatever order
 * the last request happened to produce.
 */
export function stockSortFromTable(columnKey: unknown, order: 'ascend' | 'descend' | null | undefined): string | undefined {
    const field = String(columnKey ?? '');

    if (!order || !isSortField(field)) return undefined;

    return encodeStockSort(field, order === 'descend' ? 'desc' : 'asc');
}

export function stockListRequest(params: StockListParams): StockListRequest {
    const compact = compactParams(params);
    const { sort, direction } = parseStockSort(compact.sort);
    const request: StockListRequest = { sort, direction, per_page: compact.per_page ?? STOCK_DEFAULT_PER_PAGE };

    if (compact.q) request.q = compact.q;
    if (typeof compact.warehouse_id === 'number') request.warehouse_id = compact.warehouse_id;
    if (typeof compact.page === 'number' && compact.page > 1) request.page = compact.page;

    return request;
}

/** Has the reader NARROWED the list — search or warehouse, never sort or paging. */
export function stockListNarrowed(params: StockListParams): boolean {
    return narrowingKeys(params).length > 0;
}

/** The rows this page holds, as a range within the server's total — what antd's pager computes. */
export function stockRange(meta: Paginated<unknown>['meta']): [number, number] {
    return pageRange(meta);
}

/**
 * What an empty NARROWED list says: the term, so the reader sees what was
 * looked for rather than concluding the store is bare; the warehouse when one
 * is chosen, because "no match" in one store is not "no match".
 */
export function stockNoMatchLine(q: string | undefined, warehouseLabel: string | undefined): string {
    const term = (q ?? '').trim();

    if (term !== '' && warehouseLabel) return `No balances match “${term}” in ${warehouseLabel}.`;
    if (term !== '') return `No balances match “${term}”.`;
    if (warehouseLabel) return `No balances in ${warehouseLabel}.`;

    return 'No balances match these filters.';
}
