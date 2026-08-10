import { describe, expect, it } from 'vitest';
import { cavityPrefill, type CavityPrefillInput, type PreviewStandardLite } from './startBatchCavities';

/**
 * The live crash, reduced to its moving parts (item 423, "B.450ML Ribbed
 * Pet Bottle Amber 34gms", owner-verified 09-Aug): production standard 95
 * says 7 cavities, the item master carries a different legacy figure, and
 * `active_cavities` is both what the prefill writes and part of the
 * batch-preview queryKey. Writing the field changes the key; the preview
 * for a new key is undefined until its fetch lands, while any PREVIOUS
 * key's response answers synchronously from the query cache.
 *
 * This harness models exactly that: a form field, a query cache keyed by
 * the field's value, and a render loop that re-evaluates the prefill after
 * every write. A fetch never completes inside the burst — that is the real
 * timing, because React throws #185 (Maximum update depth exceeded) long
 * before any network response arrives.
 */

const STANDARD: PreviewStandardLite = { id: 95, cavities: 7 };
const ITEM_MASTER_CAVITIES = 3; // ≠ the standard's 7 — the disagreement that oscillated

type Decision = (input: CavityPrefillInput) => { value: number | undefined; standardId: number } | null;

interface SimulationResult {
    converged: boolean;
    renders: number;
    finalValue: number | undefined;
    writes: Array<number | undefined>;
}

function simulateStartBatchOpen(
    decide: Decision,
    options: {
        itemStandardCavities?: number | null;
        /** Keys (active_cavities values) whose preview response is already cached. */
        cachedKeys?: Array<number | undefined>;
        selectedStandardId?: number | null;
        seededLastApplied?: number | null;
    } = {},
): SimulationResult {
    const itemMaster = options.itemStandardCavities === undefined ? ITEM_MASTER_CAVITIES : options.itemStandardCavities;
    // The item-change effect has already seeded the field with the item
    // master's figure — that is the state the first preview response lands in.
    let field: number | undefined = itemMaster ?? undefined;
    const cache = new Set<string>((options.cachedKeys ?? [field]).map(String));
    let lastApplied: number | null = options.seededLastApplied ?? null;
    const writes: Array<number | undefined> = [];

    const MAX_RENDERS = 50; // React throws #185 at this depth of nested updates
    let renders = 0;

    while (renders < MAX_RENDERS) {
        renders += 1;
        const previewLoaded = cache.has(String(field));
        const write = decide({
            previewLoaded,
            previewStandard: previewLoaded ? STANDARD : null,
            selectedStandardId: options.selectedStandardId ?? null,
            itemStandardCavities: itemMaster,
            lastAppliedStandardId: lastApplied,
        });

        if (write === null || write.value === field) {
            // react-hook-form does not re-render on a same-value setValue —
            // the cycle is broken, the screen settles.
            return { converged: true, renders, finalValue: field, writes };
        }

        writes.push(write.value);
        lastApplied = write.standardId;
        field = write.value;
        // The new key starts fetching; its response is NOT in the cache yet.
    }

    return { converged: false, renders, finalValue: field, writes };
}

/**
 * The decision as ShiftProductionEntryPage shipped it: the loaded
 * standard's cavities, else the item master's, written whenever present.
 * Kept here as the pinned defect — if anyone reintroduces the fallback
 * write, the convergence tests below say what it does to the page.
 */
const legacyDecision: Decision = (input) => {
    const resolved = input.previewStandard?.cavities ?? input.itemStandardCavities ?? undefined;
    if (resolved === undefined) return null;
    if (input.selectedStandardId !== null && input.previewStandard?.id !== input.selectedStandardId) return null;
    return { value: resolved, standardId: input.previewStandard?.id ?? -1 };
};

describe('the item-423 update loop, reproduced', () => {
    it('the shipped decision oscillates between the standard and the item master until React #185', () => {
        const result = simulateStartBatchOpen(legacyDecision);

        expect(result.converged).toBe(false);
        expect(result.renders).toBe(50);
        // The exact ping-pong the console showed: 7, 3, 7, 3, …
        expect(result.writes.slice(0, 4)).toEqual([7, 3, 7, 3]);
    });
});

describe('cavityPrefill — convergence contract', () => {
    it('settles on the standard cavities in one write when master and standard disagree', () => {
        const result = simulateStartBatchOpen(cavityPrefill);

        expect(result.converged).toBe(true);
        expect(result.finalValue).toBe(7);
        expect(result.writes).toEqual([7]);
    });

    it('never writes while the preview for the current inputs is in flight', () => {
        const write = cavityPrefill({
            previewLoaded: false,
            previewStandard: null,
            selectedStandardId: null,
            itemStandardCavities: ITEM_MASTER_CAVITIES,
            lastAppliedStandardId: null,
        });

        expect(write).toBeNull();
    });

    it('stays settled when the field value already carries a cached preview', () => {
        // Both keys answered before the burst — the worst cache shape for
        // the legacy logic (fully synchronous alternation) must still be a
        // fixed point for the new one.
        const result = simulateStartBatchOpen(cavityPrefill, { cachedKeys: [3, 7] });

        expect(result.converged).toBe(true);
        expect(result.finalValue).toBe(7);
    });

    it('leaves an item with no standard on the item master default', () => {
        const write = cavityPrefill({
            previewLoaded: true,
            previewStandard: null,
            selectedStandardId: null,
            itemStandardCavities: ITEM_MASTER_CAVITIES,
            lastAppliedStandardId: null,
        });

        expect(write).toBeNull();
    });

    it('falls back to the item master only when the standard itself has no cavity figure', () => {
        const write = cavityPrefill({
            previewLoaded: true,
            previewStandard: { id: 95, cavities: null },
            selectedStandardId: null,
            itemStandardCavities: ITEM_MASTER_CAVITIES,
            lastAppliedStandardId: null,
        });

        expect(write).toEqual({ value: ITEM_MASTER_CAVITIES, standardId: 95 });
    });
});

describe('cavityPrefill — the field belongs to the supervisor after the prefill', () => {
    it('does not overwrite a manual edit when the preview refetch it triggered lands', () => {
        // Prefill applied (standard 95 → 7), supervisor typed 5, preview
        // for key 5 comes back still naming standard 95.
        const write = cavityPrefill({
            previewLoaded: true,
            previewStandard: STANDARD,
            selectedStandardId: null,
            itemStandardCavities: ITEM_MASTER_CAVITIES,
            lastAppliedStandardId: 95,
        });

        expect(write).toBeNull();
    });

    it('a resumed Configure-Recipe draft keeps its cavities (lastApplied seeded on resume)', () => {
        const result = simulateStartBatchOpen(cavityPrefill, {
            seededLastApplied: 95,
            cachedKeys: [3, 7],
        });

        expect(result.converged).toBe(true);
        expect(result.writes).toEqual([]);
        expect(result.finalValue).toBe(ITEM_MASTER_CAVITIES);
    });
});

describe('cavityPrefill — multiple standard variants', () => {
    it('waits for the preview OF the picked variant instead of writing another variant’s figure', () => {
        const write = cavityPrefill({
            previewLoaded: true,
            previewStandard: { id: 83, cavities: 3 },
            selectedStandardId: 95,
            itemStandardCavities: ITEM_MASTER_CAVITIES,
            lastAppliedStandardId: null,
        });

        expect(write).toBeNull();
    });

    it('a newly picked variant prefills once, then leaves the field alone', () => {
        const first = cavityPrefill({
            previewLoaded: true,
            previewStandard: { id: 83, cavities: 3 },
            selectedStandardId: 83,
            itemStandardCavities: ITEM_MASTER_CAVITIES,
            lastAppliedStandardId: 95,
        });
        expect(first).toEqual({ value: 3, standardId: 83 });

        const second = cavityPrefill({
            previewLoaded: true,
            previewStandard: { id: 83, cavities: 3 },
            selectedStandardId: 83,
            itemStandardCavities: ITEM_MASTER_CAVITIES,
            lastAppliedStandardId: 83,
        });
        expect(second).toBeNull();
    });
});
