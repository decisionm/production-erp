import { describe, expect, it } from 'vitest';
import { isStalledRead } from '@/features/finance/pages/ClientOutstandingPage';

/**
 * A READ THAT IS NOT COMING BACK MUST NOT LOOK LIKE AN EMPTY POSITION.
 *
 * The first case below is not hypothetical — it is the state this page's query
 * was MEASURED in, in a real browser, pointed at a backend without the route:
 *
 *     status: "pending"  fetchStatus: "paused"  failureCount: 1  error: null
 *
 * TanStack's `networkMode: "online"` PAUSES a retry rather than failing it, so
 * `isError` never becomes true and the status never leaves "pending". The page
 * originally guarded on `isError`, which therefore never fired, and it showed
 * the calm "No outstanding position has been pulled from Tally yet" banner
 * over a 404 — telling the reader to go and press a button on the factory PC
 * when the real answer was that the server had not been reached.
 *
 * "Nothing is owed" and "we could not find out" are opposite facts about a
 * debtor book. This pins them apart.
 */
describe('isStalledRead', () => {
    it('treats a PAUSED retry as stalled, even though nothing has errored', () => {
        // The measured state. isError is false here — that is the trap.
        expect(isStalledRead({ isSuccess: false, isError: false, fetchStatus: 'paused' })).toBe(true);
    });

    it('treats a hard failure as stalled', () => {
        expect(isStalledRead({ isSuccess: false, isError: true, fetchStatus: 'idle' })).toBe(true);
    });

    it('does not call a first fetch stalled', () => {
        // Still loading is not a fault, and must not flash an error.
        expect(isStalledRead({ isSuccess: false, isError: false, fetchStatus: 'fetching' })).toBe(false);
    });

    it('never calls a successful read stalled, whatever the fetch status', () => {
        // A background refetch that pauses must not blank a position already
        // on screen.
        for (const fetchStatus of ['idle', 'fetching', 'paused']) {
            expect(isStalledRead({ isSuccess: true, isError: false, fetchStatus })).toBe(false);
        }
    });
});
