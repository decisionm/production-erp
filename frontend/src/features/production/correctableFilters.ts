/**
 * Pure query-building for "Earlier batches — still correctable"
 * (03-Sep-2026, Task 2 — the control row, the sort switch and the pager).
 *
 * The section's baseline read never changes: `status=pending`,
 * `correctable=1` — every batch the floor may still amend, exactly what
 * `listCorrectableEntries()` always asked for. `correctableQuery` layers
 * the six control-row filters ON TOP of that baseline, so the DEFAULT view
 * (no filters, page 1) is the old unfiltered request plus a page number,
 * newest first, capped to earlier days — see `date_to` below for why that
 * cap is part of the default, not an opt-in filter.
 *
 * `q` is the batch-number search box: trimmed, and omitted entirely when
 * blank (matches ListShiftProductionEntriesRequest::batchNumberTerm() —
 * a server that received an empty string would otherwise 422 on `max:64`
 * turning into "no filter" ambiguity; sending nothing is unambiguous).
 * `sort` always has a value — 'newest' unless the caller asked for
 * 'oldest' — because the server's own default is newest and the Segmented
 * control needs a value to display either way.
 *
 * `date_to` — THE FIX FOR THE PAGE-1-CAN-GO-EMPTY DEFECT (post-review,
 * 03-Sep-2026). "Earlier batches" and "Completed Today" are meant to be
 * disjoint by DATE: Completed Today is the page's own production day,
 * this list is everything before it. That used to be enforced on the
 * CLIENT, after the fact — `correctionLists()` subtracted whatever id
 * Completed Today's read already held. That was safe while `correctable=1`
 * came back as one unpaginated walk of up to 500 rows; it broke the moment
 * the read became a real 25-row page, because today's batches satisfy
 * `correctable=1` and sort first under `newest` — on a busy day they can
 * fill page 1 entirely, the client would then subtract every row on it, and
 * the section (heading, control row, pager together) would read as empty
 * with a real multi-day backlog sitting unreached on page 2. Fixed AT THE
 * SOURCE instead: `correctableQuery` always sends `date_to` capped to the
 * day BEFORE `today` (the page's own production day, the same value it
 * already computes for the Completed Today read — never a fresh clock
 * read, so a night shift filing under yesterday is not cut off at 06:00).
 * A user-picked `date_to` is honoured only when it is EARLIER than that
 * cap; a later (or equal, or absent) pick is clamped to it — the control
 * can narrow the earlier-days window further, never reach into today.
 * `correctionLists()` (correctionReads.ts) no longer subtracts Completed
 * Today's rows at all; the server now returns the disjoint set directly.
 *
 * `date_from` is clamped THE SAME WAY, to whatever `date_to` above just
 * resolved to (second fix, same review pass, 03-Sep-2026). Switching the
 * shift tab recomputes `today` — for an overnight shift `today` is
 * yesterday for most of the day — so a `date_from` the control row picked
 * under an earlier `today` can end up AFTER the newly-recomputed `date_to`.
 * Sent as-is, the server refuses with 422 (`after_or_equal:date_from`);
 * the query has `retry: false`, so that 422 was rendering as an honest-
 * looking "No batches match these filters." — a validation error disguised
 * as an empty result. When the caller's `date_from` is later than the
 * resolved `date_to`, both ends collapse to that same `date_to` value
 * (never the raw un-clamped `date_to`, which may itself have been clamped
 * down from the caller's own choice) — a single-day range at the cap, the
 * same recovery `date_to` alone already gets, rather than a 422 the UI
 * cannot show honestly.
 *
 * `correctableFiltersActive` is the Clear control's condition: true the
 * moment any CONTROL-ROW filter differs from its default, including an
 * explicit switch to oldest (the control row treats "away from the default
 * view" as a change, whether or not it narrows the row count). The `today`
 * cap is not a control-row filter — it is always in effect and never shown
 * as "active" by Clear, exactly as it was never shown before this fix
 * (the old client-side subtraction was likewise invisible to Clear).
 *
 * Pure — no axios, no React, no clock read — so vitest pins the
 * param-building, the date-cap arithmetic and the active/inactive rules
 * without a network, a render, or a real "now" (correctableFilters.test.ts).
 */

export type CorrectableSort = 'newest' | 'oldest';

export interface CorrectableFilters {
    /** The batch-number search term, untrimmed as typed; trimmed and blank-checked here. */
    q?: string;
    item_id?: number;
    work_center_id?: number;
    shift_id?: number;
    /** Factory-day (Y-m-d); clamped in `correctableQuery` to never land after the resolved `date_to`. */
    date_from?: string;
    /** Factory-day (Y-m-d); clamped in `correctableQuery` to never reach `today` or later. */
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
    /** Always present — the earlier-days cap, see the module docblock. */
    date_to: string;
    returned?: 1;
    sort: CorrectableSort;
    per_page: number;
    page: number;
}

/** `date` (Y-m-d) minus one calendar day, computed in UTC so no local-timezone/DST arithmetic can shift it. */
function dayBefore(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    const utc = new Date(Date.UTC(year, month - 1, day));
    utc.setUTCDate(utc.getUTCDate() - 1);

    return [
        utc.getUTCFullYear(),
        String(utc.getUTCMonth() + 1).padStart(2, '0'),
        String(utc.getUTCDate()).padStart(2, '0'),
    ].join('-');
}

/**
 * `filters` as they came off the control row, plus `today` (the page's own
 * production day, Y-m-d — the same value passed to `listCompletedEntriesForDay`)
 * → the request params for `listShiftProductionEntries`. Every set filter
 * appears exactly once; an unset one is not sent (never sent as `undefined`,
 * `0`, or `false` — a key the server would rather not see than misread).
 *
 * `date_to` is one exception: always present, the earlier of the caller's
 * own `date_to` and the day before `today`. `date_from` is the other: when
 * set, it is clamped down to that same resolved `date_to` if it would
 * otherwise land after it — never sent later than `date_to`, so the server
 * never sees an inverted range (`after_or_equal:date_from` on `date_to`
 * would 422 it) no matter how stale the caller's `date_from` has gone
 * relative to a `today` that changed since it was picked.
 */
export function correctableQuery(filters: CorrectableFilters, page: number, today: string): CorrectableQueryParams {
    const q = filters.q?.trim();
    const cap = dayBefore(today);
    const dateTo = filters.date_to && filters.date_to < cap ? filters.date_to : cap;
    const dateFrom = filters.date_from && filters.date_from > dateTo ? dateTo : filters.date_from;

    return {
        status: 'pending',
        correctable: 1,
        ...(filters.item_id ? { item_id: filters.item_id } : {}),
        ...(q ? { q } : {}),
        ...(filters.work_center_id ? { work_center_id: filters.work_center_id } : {}),
        ...(filters.shift_id ? { shift_id: filters.shift_id } : {}),
        ...(dateFrom ? { date_from: dateFrom } : {}),
        date_to: dateTo,
        ...(filters.returned ? { returned: 1 } : {}),
        sort: filters.sort === 'oldest' ? 'oldest' : 'newest',
        per_page: CORRECTABLE_PAGE_SIZE,
        page,
    };
}

/** True the moment any control-row filter differs from the default (unfiltered, newest-first) view. */
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
