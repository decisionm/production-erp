import { apiRefusalMessage } from '@/features/material-flow/api';

/**
 * WHAT AN EMPTY QUEUE SAYS — and it is two different things.
 *
 * An empty table means either "nothing is queued" or "the read failed", and
 * those need different sentences. The read can fail for a reason the person
 * looking at the screen can FIX: the planning walk refuses when no
 * finished-goods warehouse is configured, and says which setting to name.
 * Printing "The queue could not be read." over that message throws away the
 * only useful half — found by walking the page against a fresh factory, where
 * that refusal is the FIRST thing a new deployment hits.
 *
 * So the server's own words are shown when it gave any, and the flat sentence
 * survives only as the fallback for a failure that said nothing (a dropped
 * connection, a 500 with no body).
 */
export function queueEmptyText(isError: boolean, error: unknown): string {
    if (! isError) return 'Nothing is queued for the floor.';

    return apiRefusalMessage(error, 'The queue could not be read.');
}

/**
 * WHAT AN EMPTY LOOK-BACK READ SAYS — deliberately NOT `queueEmptyText`'s
 * "Nothing is queued for the floor.": that sentence is false here. A
 * produced-only or cancelled-only filter with no rows is not an idle
 * factory, it is a filter that matched nothing, and the two must not be
 * said the same way.
 */
export function productionRequestHistoryEmptyText(isError: boolean, error: unknown): string {
    if (! isError) return 'No requests in the chosen statuses.';

    return apiRefusalMessage(error, 'The requests could not be read.');
}
