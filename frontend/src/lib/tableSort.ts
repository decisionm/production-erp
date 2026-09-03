import type { SorterResult } from 'antd/es/table/interface';

/**
 * Column-header sorting on a SERVER-PAGINATED list (03-Sep-2026).
 *
 * The URL carries one `sort` value in the server's own spelling — a bare
 * column for ascending, a "-" prefix for descending, absent for the default
 * order (`order_date`, `-order_date`, nothing). These two functions are the
 * whole bridge between that value and antd's column sorters, so a page adds
 * `sorter: true, sortOrder: columnSortOrder(field, sort, defaultSort)` to a
 * column and hands the Table's `onChange` sorter to `sortParamFromSorter`.
 *
 * Every sorter is `sortOrder`-controlled: antd never sorts the loaded page,
 * the server re-queries the whole result set and the pager resets to 1 (the
 * caller's `setParams` does that). A column with no server sort is simply
 * not given a sorter.
 */
export type ColumnSortOrder = 'ascend' | 'descend' | null;

/** The order the header arrow shows for `field`, given the URL's `sort` (or the list's default when absent). */
export function columnSortOrder(field: string, sort: string | undefined, defaultSort?: string): ColumnSortOrder {
    const active = (sort ?? '').trim() || (defaultSort ?? '').trim();
    if (active === '') return null;

    const descending = active.startsWith('-');
    if (active.replace(/^-/, '') !== field) return null;

    return descending ? 'descend' : 'ascend';
}

/**
 * antd's `onChange` sorter → the URL's `sort`. The column's `key` names the
 * server field; a cleared sort, or the list's default order, is `undefined`
 * so the URL stays clean. Unknown fields are refused (undefined) rather than
 * sent to a 422.
 */
export function sortParamFromSorter<T>(
    sorter: SorterResult<T> | SorterResult<T>[],
    allowed: readonly string[],
    defaultSort?: string,
): string | undefined {
    const active = Array.isArray(sorter) ? sorter[0] : sorter;
    if (!active || !active.order) return undefined;

    const field = String(active.columnKey ?? active.field ?? '');
    if (!allowed.includes(field)) return undefined;

    const next = active.order === 'descend' ? `-${field}` : field;

    return next === (defaultSort ?? '') ? undefined : next;
}
