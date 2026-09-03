/**
 * Pure query-building for "Earlier batches — still correctable"
 * (03-Sep-2026, Task 2 — the control row, the sort switch and the pager).
 *
 * The section's baseline read never changes: `status=pending`,
 * `correctable=1` — every batch the floor may still amend, exactly what
 * `listCorrectableEntries()` always asked for. `correctableQuery` layers
 * the six filters the control row adds ON TOP of that baseline, so the
 * DEFAULT view (no filters, page 1) is byte-for-byte the old unfiltered
 * request plus a page number, newest first — nothing narrower, nothing
 * hidden.
 *
 * `q` is the batch-number search box: trimmed, and omitted entirely when
 * blank (matches ListShiftProductionEntriesRequest::batchNumberTerm() —
 * a server that received an empty string would otherwise 422 on `max:64`
 * turning into "no filter" ambiguity; sending nothing is unambiguous).
 * `sort` always has a value — 'newest' unless the caller asked for
 * 'oldest' — because the server's own default is newest and the Segmented
 * control needs a value to display either way.
 *
 * `correctableFiltersActive` is the Clear control's condition: true the
 * moment any filter differs from that same default state, including an
 * explicit switch to oldest (the control row treats "away from the
 * default view" as a change, whether or not it narrows the row count).
 *
 * Pure — no axios, no React — so vitest pins the param-building and the
 * active/inactive rules without a network or a render
 * (correctableFilters.test.ts).
 */

export type CorrectableSort = 'newest' | 'oldest';

export interface CorrectableFilters {
    /** The batch-number search term, untrimmed as typed; trimmed and blank-checked here. */
    q?: string;
    item_id?: number;
    work_center_id?: number;
    shift_id?: number;
    /** Factory-day (Y-m-d), same as every other date filter on this endpoint. */
    date_from?: string;
    date_to?: string;
    /** Sent back by Quality at least once (config_snapshot->quality_returns). */
    returned?: boolean;
    sort?: CorrectableSort;
}

/** The server's page ceiling for this read — a control row's worth of cards, not a silent walk. */
export const CORRECTABLE_PAGE_SIZE = 25;

/** The full request params for one page of the correctable list, filters applied. */
export interface CorrectableQueryParams {
    status: 'pending';
    correctable: 1;
    item_id?: number;
    q?: string;
    work_center_id?: number;
    shift_id?: number;
    date_from?: string;
    date_to?: string;
    returned?: 1;
    sort: CorrectableSort;
    per_page: number;
    page: number;
}

/**
 * `filters` as they came off the control row → the request params for
 * `listShiftProductionEntries`. Every set filter appears exactly once; an
 * unset one is not sent (never sent as `undefined`, `0`, or `false` — a
 * key the server would rather not see than misread).
 */
export function correctableQuery(filters: CorrectableFilters, page: number): CorrectableQueryParams {
    const q = filters.q?.trim();

    return {
        status: 'pending',
        correctable: 1,
        ...(filters.item_id ? { item_id: filters.item_id } : {}),
        ...(q ? { q } : {}),
        ...(filters.work_center_id ? { work_center_id: filters.work_center_id } : {}),
        ...(filters.shift_id ? { shift_id: filters.shift_id } : {}),
        ...(filters.date_from ? { date_from: filters.date_from } : {}),
        ...(filters.date_to ? { date_to: filters.date_to } : {}),
        ...(filters.returned ? { returned: 1 } : {}),
        sort: filters.sort === 'oldest' ? 'oldest' : 'newest',
        per_page: CORRECTABLE_PAGE_SIZE,
        page,
    };
}

/** True the moment any control differs from the default (unfiltered, newest-first) view. */
export function correctableFiltersActive(filters: CorrectableFilters): boolean {
    return Boolean(
        filters.q?.trim() ||
            filters.item_id ||
            filters.work_center_id ||
            filters.shift_id ||
            filters.date_from ||
            filters.date_to ||
            filters.returned ||
            filters.sort === 'oldest',
    );
}
