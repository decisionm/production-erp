import type { ProductionRequestStatus } from '@/features/production/types';

/**
 * WHETHER A QUEUE ROW MAY STILL BE ACTED ON — Start, Cancel, drag or the
 * reorder arrows. A `produced` request retired on its own (DEC-20260902-032:
 * its sales line was covered) and a `cancelled` one was withdrawn; neither
 * comes back onto the floor's worklist by pressing anything on this screen.
 *
 * A PURE PREDICATE, checked with its own test, so every place on the page
 * that draws a row action asks the same question rather than each re-deriving
 * "is this row finished" from the status string.
 */
export function queueRowActionsAllowed(status: ProductionRequestStatus): boolean {
    return status === 'queued' || status === 'in_progress';
}

/** What the queue reads when nobody has touched the look-back filter — the open worklist. */
export const DEFAULT_QUEUE_STATUSES: ProductionRequestStatus[] = ['queued', 'in_progress'];

/**
 * WHETHER THE LOOK-BACK FILTER IS SHOWING THE DEFAULT (GROUPED) VIEW —
 * ProductionQueuePage's own worklist, with planning columns and reorder
 * arrows — rather than the flat, read-only history table.
 *
 * An EMPTY selection counts as the default (03-Sep-2026 fix): clearing a
 * multi-select is the most ordinary gesture a user makes with one, and
 * `statuses = []` is not a deliberate request to filter down to nothing — it
 * is "I removed my filter", which means "show me what you show me with no
 * filter at all". Selecting exactly the two default statuses is the other
 * way to land on the same view; any other combination — one status, three,
 * all four including something other than exactly queued+in_progress — is a
 * deliberate filter act and switches to the flat history read.
 *
 * A PURE PREDICATE, checked with its own test — the multi-select's onChange
 * asks this, nothing re-derives it inline.
 */
export function isDefaultQueueView(statuses: ProductionRequestStatus[]): boolean {
    return (
        statuses.length === 0 ||
        (statuses.length === DEFAULT_QUEUE_STATUSES.length && DEFAULT_QUEUE_STATUSES.every((status) => statuses.includes(status)))
    );
}
