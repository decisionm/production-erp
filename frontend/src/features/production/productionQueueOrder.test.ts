import { describe, expect, it } from 'vitest';
import { movedOrder } from './pages/ProductionQueuePage';

/**
 * WHAT AN UP OR DOWN ARROW ACTUALLY SENDS.
 *
 * THE WHOLE ORDERING, never a "move #4 up" delta, and that is the claim worth
 * pinning: priorities are dense and `reorder()` rewrites all of them inside one
 * locking transaction, so two supervisors reordering at once end with one of
 * the two orders rather than an interleaving of both. A delta API would have
 * no such property, and a payload that dropped or duplicated an id would
 * renumber the queue around a job that quietly left it.
 *
 * The queue's ORDER is otherwise entirely the server's — this is the only
 * place on the floor's page that computes one, and it computes a swap.
 */
describe('the production queue reorder payload', () => {
    const ids = [11, 12, 13, 14];

    it('swaps a row with the one above it, and carries every other id along unchanged', () => {
        expect(movedOrder(ids, 2, -1)).toEqual([11, 13, 12, 14]);
    });

    it('swaps a row with the one below it', () => {
        expect(movedOrder(ids, 0, 1)).toEqual([12, 11, 13, 14]);
    });

    it('never loses or duplicates an id', () => {
        const moved = movedOrder(ids, 1, 1);

        expect(moved).toHaveLength(ids.length);
        expect([...moved].sort()).toEqual([...ids].sort());
    });

    it('leaves the queue exactly as it was at either end, rather than wrapping it round', () => {
        // The buttons are disabled there, so this is the belt to that braces:
        // a first job that wrapped to last would be a silent reprioritisation
        // of the whole floor.
        expect(movedOrder(ids, 0, -1)).toEqual(ids);
        expect(movedOrder(ids, ids.length - 1, 1)).toEqual(ids);
    });

    it('does nothing with an index that is not in the queue', () => {
        expect(movedOrder(ids, -1, 1)).toEqual(ids);
        expect(movedOrder(ids, 9, -1)).toEqual(ids);
        expect(movedOrder([], 0, 1)).toEqual([]);
    });
});
