/**
 * WHAT A LIST WITH NO ROWS SHOULD SAY — judged on the query's state, never
 * on the row count alone. A list that could not be read has no rows, and
 * "No purchase orders match" over a 500 is a server failure read as an
 * empty result: the operator concludes the work is done when the truth is
 * the screen is blind.
 *
 * Three states, in priority order:
 *   error   → the failure, in the server's own sentence, with a retry
 *   pending → "Reading …"
 *   ready   → the page's own empty wording (which stays the page's business:
 *             the store queue's "nothing outstanding" line is domain wording
 *             this module must not flatten)
 *
 * Pure module — no React. The chooser is unit-tested; ListEmpty.tsx renders
 * the choice. Kept apart so the words are pinned by an ordinary vitest
 * without a DOM.
 */
import { apiErrorSummary } from './apiError';

export interface ListReadState {
    isPending: boolean;
    isError: boolean;
    error?: unknown;
}

export type ListStateKind = 'error' | 'pending' | 'ready';

/** Error outranks pending: a failed first read is failed, not still reading. */
export function listStateKind(state: ListReadState): ListStateKind {
    if (state.isError) return 'error';
    if (state.isPending) return 'pending';

    return 'ready';
}

/**
 * The failure line. `entity` is the plural the reader sees on the page
 * title ("purchase orders", "goods receipts") so the sentence names what
 * could not be read, and the server's own refusal follows when it sent one.
 */
export function listReadFailureLine(entity: string, error: unknown): string {
    return `Could not read ${entity}: ${apiErrorSummary(error, 'the server did not say why')}`;
}

/** The reading line, worded once. */
export function listReadingLine(entity: string): string {
    return `Reading ${entity}…`;
}
