import { describe, expect, it } from 'vitest';
import { lotIsSubmittable, lotScanState } from './lotScan';

const bags = (n: number): string[] => Array.from({ length: n }, (_, i) => `BAG-${i + 1}`);

describe('bag scanning on one supplier lot', () => {
    it('allows generated identities when NOTHING has been scanned', () => {
        // The ordinary case for a supplier who barcodes nothing. There are no
        // scans to lose, so the server mints one identity per bag.
        const state = lotScanState(4, []);

        expect(state.status).toBe('none');
        expect(state.submittable).toBe(true);
        expect(state.message).toBeNull();
    });

    it('allows a fully scanned lot', () => {
        expect(lotScanState(4, bags(4))).toMatchObject({ status: 'complete', submittable: true, remaining: 0 });
    });

    it('BLOCKS a part-scanned lot instead of quietly generating identities', () => {
        const state = lotScanState(4, bags(2));

        expect(state.status).toBe('incomplete');
        expect(state.submittable).toBe(false);
        expect(state.remaining).toBe(2);
        expect(state.message).toContain('2 bags still to scan');
        // The way out is named, so the receiver is never stuck.
        expect(state.message).toContain('discard');
    });

    describe('changing the bag count after scanning has started', () => {
        it('INCREASING it is allowed and simply asks for the rest', () => {
            const state = lotScanState(10, bags(4));

            expect(state.status).toBe('incomplete');
            expect(state.remaining).toBe(6);
            expect(state.submittable).toBe(false);
        });

        it('DECREASING it while nothing is scanned is allowed', () => {
            // Nothing can be destroyed, so correcting the count is free.
            expect(lotScanState(3, [])).toMatchObject({ status: 'none', submittable: true });
        });

        it('DECREASING it BELOW the scans is blocked, and says so', () => {
            // The regression this pins: remaining floored at 0, so the screen
            // read "all bags scanned" and the payload silently fell back to
            // generated identities — ten supplier barcodes thrown away.
            const state = lotScanState(5, bags(10));

            expect(state.status).toBe('over');
            expect(state.submittable).toBe(false);
            expect(state.message).toContain('10 bags have been scanned but the line says 5');
        });

        it('decreasing to exactly the scanned count is allowed', () => {
            expect(lotScanState(4, bags(4))).toMatchObject({ status: 'complete', submittable: true });
        });
    });

    it('treats a missing bag count as not yet answerable rather than as zero bags', () => {
        // bag_count is undefined until the receiver types it; scans already
        // taken must not be declared "over" in the meantime.
        expect(lotScanState(undefined, [])).toMatchObject({ status: 'none', submittable: true });
        expect(lotScanState(undefined, bags(2)).status).toBe('over');
        expect(lotScanState(undefined, bags(2)).submittable).toBe(false);
    });

    it('survives a draft restore — the state is a pure function of what was saved', () => {
        // A restored draft is just its saved values. If those values were
        // submittable before the refresh they are submittable after it, and if
        // they were part-scanned they are still blocked.
        const saved = { bag_count: 4, barcodes: bags(4) };
        const restored = JSON.parse(JSON.stringify(saved)) as typeof saved;

        expect(lotScanState(restored.bag_count, restored.barcodes)).toEqual(lotScanState(saved.bag_count, saved.barcodes));
        expect(lotIsSubmittable(restored.bag_count, restored.barcodes)).toBe(true);

        const partial = { bag_count: 4, barcodes: bags(1) };
        expect(lotIsSubmittable(partial.bag_count, partial.barcodes)).toBe(false);
    });

    it('judges each lot on its own — one bad lot does not condemn a good one', () => {
        // Multi-line GRN isolation: a receipt may carry several lines, each with
        // several lots. The rule is per lot.
        const lots = [
            { bag_count: 4, barcodes: bags(4) }, // complete
            { bag_count: 2, barcodes: [] }, // generated, fine
            { bag_count: 6, barcodes: bags(3) }, // part-scanned, blocks
        ];

        expect(lots.map((l) => lotIsSubmittable(l.bag_count, l.barcodes))).toEqual([true, true, false]);
        expect(lots.filter((l) => !lotIsSubmittable(l.bag_count, l.barcodes))).toHaveLength(1);
    });
});
