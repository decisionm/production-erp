import type { TablePaginationConfig } from 'antd';
import type { Paginated } from './types';

/**
 * The table props every server-paged list shares, so a page cannot get
 * them subtly wrong.
 *
 * `TABLE_STICKY`: the app header is 64px tall and itself sticky, so a table
 * header frozen at `top: 0` slides UNDER it and the column names vanish
 * exactly when the reader scrolls to need them. A bare `sticky` is that
 * bug; this is the offset that keeps the header visible.
 *
 * `serverPagination`: the pager wired to the server's own meta. Rows past
 * the first page exist on the server whether or not the page shows a
 * pager — a list that renders `data.data` with `pagination={false}` is
 * silently truncated at the server's page size, and nothing on screen says
 * so. Every list that reads a Paginated<T> uses this.
 */
export const TABLE_STICKY = { offsetHeader: 64 } as const;

export const PAGE_SIZE_OPTIONS: readonly number[] = [20, 50, 100];

type PageMeta = Paginated<unknown>['meta'];

/** "1–20 of 143 requests", "143 requests" when it all fits, "0 requests". */
export function rangeLine(total: number, range: readonly [number, number], noun: string): string {
    if (total === 0) return `0 ${noun}`;
    if (range[0] <= 1 && range[1] >= total) return `${total} ${noun}`;

    return `${range[0]}–${range[1]} of ${total} ${noun}`;
}

/** The rows this page holds, as [from, to] within the total; [0, 0] when empty. */
export function pageRange(meta: PageMeta): [number, number] {
    if (meta.total === 0) return [0, 0];

    return [(meta.current_page - 1) * meta.per_page + 1, Math.min(meta.current_page * meta.per_page, meta.total)];
}

/**
 * "1–20 of 143 requests" for the toolbar, from the server's own meta; null
 * until the server has answered. The pager says the same at the foot of
 * the table, but antd draws no pager at all for a zero total — and
 * "0 requests" beside a search box is exactly the line that needs to be
 * there.
 */
export function pageRangeLine(meta: PageMeta | undefined, noun: string): string | null {
    if (meta === undefined) return null;

    return rangeLine(meta.total, pageRange(meta), noun);
}

/** The no-match line repeats the term, so a typo is visible where it was made. */
export function noMatchLine(noun: string, term: string): string {
    return `No ${noun} match “${term}”.`;
}

export function serverPagination(
    meta: PageMeta | undefined,
    onChange: (page: number, perPage: number) => void,
    noun: string,
): TablePaginationConfig | false {
    if (meta === undefined) return false;

    return {
        current: meta.current_page,
        pageSize: meta.per_page,
        total: meta.total,
        showSizeChanger: true,
        pageSizeOptions: [...PAGE_SIZE_OPTIONS],
        showTotal: (total, range) => rangeLine(total, range, noun),
        onChange,
    };
}
