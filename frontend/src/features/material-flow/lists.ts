import { type ListParams, type ListParamsSpec, compactParams } from '@/lib/listParams';
export { noMatchLine, pageRangeLine } from '@/lib/tableProps';
import type { MaterialRequestFilters } from './types';
import { type MaterialRequestStatus, type QueueStatusChoice, queueStatusFilter } from './words';

/**
 * THE TWO MATERIAL-REQUEST LISTS' URL STATE — the store's queue and the
 * floor's own page — and how each URL becomes the server's filters.
 *
 * Pure, so the mapping is pinned by an ordinary vitest and the render tests
 * can seed the exact query key a page will read. The pages hold nothing of
 * this in component state: a refresh, Back, or a pasted link lands on the
 * same view (useListParams).
 *
 * The URL carries the DROPDOWN'S CHOICE, not the server's filter, and the
 * difference matters on the queue: its default view "still to issue" is a
 * status LIST on the wire (submitted + partially_issued) and is what an
 * absent `status` means — so the bare path stays the default view, and
 * `status=all` is how the store reaches a request it has already finished.
 */

/* ------------------------------- the order ------------------------------ */

/** The columns the server orders material requests by (ListMaterialRequestsRequest). */
export const MATERIAL_REQUEST_SORT_FIELDS: readonly string[] = ['id', 'requested_at', 'submitted_at', 'status'];

/** The server's order when the URL names none: newest first. */
export const MATERIAL_REQUEST_DEFAULT_SORT = '-id';

/** Every `sort` the URL may carry, in the server's spelling: bare ascending, "-" descending. */
const MATERIAL_REQUEST_SORT_OPTIONS: readonly string[] = MATERIAL_REQUEST_SORT_FIELDS.flatMap((field) => [field, `-${field}`]);

/* ------------------------------ the queue ------------------------------- */

export const QUEUE_STATUS_CHOICES: readonly QueueStatusChoice[] = [
    'open',
    'all',
    'submitted',
    'partially_issued',
    'issued',
    'cancelled',
];

export const QUEUE_DEFAULT_STATUS: QueueStatusChoice = 'open';

export interface QueueListParams extends ListParams {
    status?: QueueStatusChoice;
    shift_id?: number;
    work_center_id?: number;
    item_id?: number;
    from?: string;
    to?: string;
    sort?: string;
}

/** Module-level, as useListParams requires. */
export const QUEUE_LIST_SPEC: ListParamsSpec = {
    strings: ['status', 'from', 'to', 'sort'],
    numbers: ['shift_id', 'work_center_id', 'item_id'],
    allowed: { status: QUEUE_STATUS_CHOICES, sort: MATERIAL_REQUEST_SORT_OPTIONS },
};

/** What the queue's dropdown shows for these params. */
export function queueStatusChoice(params: QueueListParams): QueueStatusChoice {
    return params.status ?? QUEUE_DEFAULT_STATUS;
}

/** The queue's URL → the request the server gets. Compacted: `{}` and `{ q: '' }` are one key. */
export function queueServerFilters(params: QueueListParams): MaterialRequestFilters {
    const { status: _choice, ...rest } = params;

    return compactParams({ ...rest, status: queueStatusFilter(queueStatusChoice(params)) });
}

export function queueQueryKey(filters: MaterialRequestFilters) {
    return ['material-flow', 'queue', filters] as const;
}

/* --------------------------- the floor's page --------------------------- */

export type RequestStatusChoice = 'all' | MaterialRequestStatus;

export const REQUEST_STATUS_CHOICES: readonly RequestStatusChoice[] = [
    'all',
    'draft',
    'submitted',
    'partially_issued',
    'issued',
    'cancelled',
];

export const REQUESTS_DEFAULT_STATUS: RequestStatusChoice = 'all';

export interface RequestsListParams extends ListParams {
    status?: RequestStatusChoice;
    sort?: string;
}

export const REQUESTS_LIST_SPEC: ListParamsSpec = {
    strings: ['status', 'sort'],
    allowed: { status: REQUEST_STATUS_CHOICES, sort: MATERIAL_REQUEST_SORT_OPTIONS },
};

export function requestsStatusChoice(params: RequestsListParams): RequestStatusChoice {
    return params.status ?? REQUESTS_DEFAULT_STATUS;
}

/**
 * The floor's own page asks for its drafts — always, because Submit is a
 * row action on this table and a draft it cannot see is a request it can
 * never send (see MaterialRequestFilters.include_unsubmitted).
 */
export function requestsServerFilters(params: RequestsListParams): MaterialRequestFilters {
    const choice = requestsStatusChoice(params);
    const { status: _choice, ...rest } = params;

    return compactParams({ ...rest, status: choice === 'all' ? undefined : choice, include_unsubmitted: 1 });
}

export function requestsQueryKey(filters: MaterialRequestFilters) {
    return ['material-flow', 'requests', filters] as const;
}

/* ------------------------------ the words ------------------------------- */


