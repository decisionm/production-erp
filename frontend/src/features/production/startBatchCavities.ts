/**
 * The Start Batch cavity prefill decision, extracted from
 * ShiftProductionEntryPage so the feedback cycle it sits inside can be
 * tested as plain logic.
 *
 * The cycle it must survive: `active_cavities` is BOTH written by this
 * decision and an input of the batch-preview queryKey. Writing the field
 * therefore changes the key; the preview for the new key is undefined while
 * it fetches, then loaded; each flip re-evaluates this decision. The white
 * screen this file exists to prevent (React #185, item 423 "B.450ML Ribbed
 * Pet Bottle Amber", 09-Aug) was this decision writing the STANDARD's
 * cavities from a loaded preview and the ITEM MASTER's from an in-flight
 * one: with the two figures disagreeing, every write flipped the preview
 * state and the preview state flipped the write, synchronously, because the
 * previous key's response was already in the query cache.
 *
 * Two rules make the decision a fixed point of that cycle:
 *
 *  1. Only a LOADED preview may write. An in-flight preview is not evidence
 *     that the standard has changed — the item-change effect has already
 *     seeded the item master's figure, and re-asserting it here is the
 *     oscillating leg of the loop.
 *  2. A standard prefills ONCE (`lastAppliedStandardId`). The prefill is a
 *     default, not an enforcement: after it has been applied, the field
 *     belongs to the supervisor, and the preview refetch their own edit
 *     triggers must not overwrite it — which it did, silently, before the
 *     loop made the same write loud.
 */

/** The slice of the batch preview's standard this decision reads. */
export interface PreviewStandardLite {
    id: number;
    cavities: number | null;
}

export interface CavityPrefillInput {
    /**
     * False while the preview for the CURRENT form inputs is still being
     * fetched. Distinct from "loaded with no standard" (previewStandard
     * null), which is a real answer.
     */
    previewLoaded: boolean;
    previewStandard: PreviewStandardLite | null;
    /** The variant the supervisor explicitly picked, when the product has several. */
    selectedStandardId: number | null;
    /** The item master's legacy cavity figure — the standard outranks it. */
    itemStandardCavities: number | null;
    /** The standard id this decision last wrote for, or null. */
    lastAppliedStandardId: number | null;
}

export interface CavityPrefillWrite {
    value: number | undefined;
    standardId: number;
}

/**
 * What to write into `active_cavities`, or null for "leave the field
 * alone". Callers record `standardId` back as `lastAppliedStandardId` so
 * the same standard never writes twice.
 */
export function cavityPrefill(input: CavityPrefillInput): CavityPrefillWrite | null {
    // Rule 1: an in-flight preview writes nothing — not even a fallback.
    if (!input.previewLoaded) return null;
    // A loaded preview with no resolved standard (unpicked variants, or a
    // product with none) has nothing to prefill; the item-change effect
    // already seeded the item master's figure.
    if (input.previewStandard === null) return null;
    // An explicit pick that the loaded preview does not reflect yet — wait
    // for the preview OF that pick rather than writing another standard's
    // figure.
    if (input.selectedStandardId !== null && input.previewStandard.id !== input.selectedStandardId) {
        return null;
    }
    // Rule 2: this standard has already prefilled — the field is the
    // supervisor's now.
    if (input.lastAppliedStandardId === input.previewStandard.id) return null;

    return {
        // The standard may itself carry no cavity figure; the item master
        // then stands in, exactly as the run's own precedence reads it.
        value: input.previewStandard.cavities ?? input.itemStandardCavities ?? undefined,
        standardId: input.previewStandard.id,
    };
}
