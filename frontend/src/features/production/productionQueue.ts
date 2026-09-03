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
