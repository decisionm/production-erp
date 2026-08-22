/**
 * What the Start Batch modal ASKS, and what it merely SAYS — extracted from
 * ShiftProductionEntryPage's Start modal so the rule "ask only when there is
 * a real choice" is a pure function pinned by vitest, not a pair of inline
 * conditions in an 8,600-line component (Phase 5.5, P5.5-01 / P5.5-06).
 *
 * The verdicts are BEHAVIOUR-PRESERVING: `askStandard`, `askPacking` and
 * `disabledPackagings` are the modal's two radio conditions verbatim (the
 * red-before test in startBatchChoices.test.ts carries the inline code and
 * asserts equality across 0/1/N variants × 0/1/N packagings × complete/
 * incomplete). What is new is `gaps` — the configuration pieces the server
 * says are missing, in words — and the two identity readers below.
 *
 * Nothing here decides a factory value. The words come from the server's
 * keys (ProductVariantService::MISSING_VOCABULARY, worded once in
 * productStandardsConfig.ts); a gap the preview does not carry is NOT
 * declared; the Tally identity is READ from the packaging the server
 * resolved, never chosen here (DEC-20260810-003: a packing's own item, else
 * the product's).
 */

import {
    incompleteWordsFromServer,
    missingWords,
    packagingBelongsToSeparateProduct,
    SEPARATE_PRODUCT_REQUIRED,
    tallyIdentityLabel,
} from '@/features/production/productStandardsConfig';
import type { ConfigurationCompleteness, PackagingTallyItem } from '@/features/production/types';

// ---------------------------------------------------------------------------
// The slice of the batch preview these decisions read
// ---------------------------------------------------------------------------

/** One packaging as the preview's `variants[].packagings[]` sends it. */
export interface StartBatchPackagingLite {
    id: number;
    label?: string;
    /** The runnable flag the picker disables on (a half-stated workbook row). */
    is_complete: boolean;
    /** The packing's OWN Tally identity; null/absent = posts as the product's item. */
    tally_item?: PackagingTallyItem | null;
    /** The server's verdict on this packing (Phase 5); absent on an older backend. */
    configuration_status?: ConfigurationCompleteness | null;
}

/** One standard variant as the preview's `variants[]` sends it. */
export interface StartBatchVariantLite {
    id: number;
    packagings: StartBatchPackagingLite[];
    /** The server's verdict on this standard (Phase 5); absent on an older backend. */
    configuration_status?: ConfigurationCompleteness | null;
}

export interface StartBatchPreviewLite {
    variants?: StartBatchVariantLite[] | null;
    /** The packaging the SERVER resolved for this run (chosen id, else the only complete one, else the default). */
    packaging?: { id: number } | null;
    /**
     * THE RUN'S OWN VERDICT (BatchPreviewController, Phase 5.5 fix loop):
     * ProductVariantService::runStatus for the resolved standard and
     * packaging — the SAME rule startBatch freezes into the entry's
     * configuration_gaps — with `grain` 'run' once a packaging is resolved
     * or 'standard' (the union) while the packaging is still to be chosen;
     * null while the standard is. Read first and wins over the per-variant
     * reading below, which is the standard's union over EVERY packaging and
     * named a tray run's sibling pouch as the run's gap. Absent on an older
     * backend, where the per-variant reading stands in.
     */
    configuration_status?: ConfigurationCompleteness | null;
}

/** The product's own item — the fallback identity a packing without one posts as. */
export interface StartBatchProductLite {
    id: number;
    sku?: string | null;
    name?: string;
}

export interface StartBatchChoices {
    /** Show "Which standard is this run?" — ONLY when the product genuinely has more than one. */
    askStandard: boolean;
    /** Show "How is it packed?" — ONLY when the chosen standard offers two or more packings. */
    askPacking: boolean;
    /** Of the offered packings, the ones shown but not pickable, with the words printed beside each. Empty unless `askPacking`. */
    disabledPackagings: Array<{ id: number; words: string }>;
    /**
     * The offered packings that post as a DIFFERENT Tally item from the
     * product (DEC-20260821-001) — each one a separate product, and none of
     * them startable here. A SEPARATE list from `disabledPackagings` on
     * purpose: that field is the extracted inline rule, pinned verbatim
     * across the 0/1/N matrix, and folding a new reason into it would
     * silently move a contract the red-before test exists to hold. The
     * caller disables on the union.
     *
     * Empty unless a `product` was passed — an older caller gets exactly the
     * behaviour it had.
     */
    conflictingPackagings: Array<{ id: number; words: string }>;
    /** The configuration pieces the server says this run is missing, in words, in the server's order. Empty = complete or unjudged. */
    gaps: string[];
}

// ---------------------------------------------------------------------------
// The decisions
// ---------------------------------------------------------------------------

/**
 * The variant this run is about — the inline rule verbatim: the selected id
 * when it names one of the preview's variants, else the ONLY variant when
 * there is exactly one, else nothing (a choice still open, or no standard).
 */
export function chosenStartVariant(
    preview: StartBatchPreviewLite | null | undefined,
    chosenStandardId: number | null | undefined,
): StartBatchVariantLite | undefined {
    const variants = preview?.variants ?? [];
    return variants.find((v) => v.id === chosenStandardId)
        ?? (variants.length === 1 ? variants[0] : undefined);
}

export function startBatchChoices(
    preview: StartBatchPreviewLite | null | undefined,
    chosenStandardId?: number | null,
    // OPTIONAL, and last, so every existing call keeps its exact behaviour:
    // without the product there is nothing to compare a packing's identity
    // against, and `conflictingPackagings` comes back empty rather than
    // guessed. (DEC-20260821-001.)
    product?: StartBatchProductLite | null,
): StartBatchChoices {
    // Radio 1 — verbatim `(batchPreview?.variants?.length ?? 0) > 1`.
    const askStandard = (preview?.variants?.length ?? 0) > 1;

    // Radio 2 — verbatim `if (!chosen || chosen.packagings.length < 2) return null`.
    const chosen = chosenStartVariant(preview, chosenStandardId);
    const askPacking = !!chosen && chosen.packagings.length >= 2;

    // The shown-not-offered rows, with the exact words the option label
    // carried: the server's own missing pieces when the preview has them,
    // the old wording otherwise. Only meaningful when the radio is shown.
    const disabledPackagings = askPacking && chosen
        ? chosen.packagings
            .filter((p) => !p.is_complete)
            .map((p) => ({ id: p.id, words: incompleteWordsFromServer(p) ?? 'incomplete in workbook' }))
        : [];

    // Named on EVERY offered packing of the chosen standard, not only when
    // the packing radio is shown: the commonest shape of this problem is a
    // standard with a single packing, which the server resolves silently and
    // which therefore has no radio and nothing to disable. Gating that case
    // is the Start button's job (startBatchSeparateProductConflict below);
    // this list is what the radio uses when there IS one.
    const conflictingPackagings = chosen && product
        ? chosen.packagings
            .filter((p) => packagingBelongsToSeparateProduct(p, product))
            .map((p) => ({ id: p.id, words: SEPARATE_PRODUCT_REQUIRED }))
        : [];

    return { askStandard, askPacking, disabledPackagings, conflictingPackagings, gaps: gapsFor(preview, chosen) };
}

/**
 * Whether the packing this run has actually RESOLVED to belongs under a
 * separate product — the one verdict the Start button is gated on
 * (DEC-20260821-001).
 *
 * Read from `startBatchPackaging()`, the server's own resolution, so it
 * answers true in the case the radio cannot: a standard with one packing,
 * auto-resolved, no question asked and nothing on screen to disable. False
 * while nothing is resolved — a choice still open is not yet a conflict, and
 * the picker's own labels carry it in that state.
 *
 * Advisory. The backend refuses this start whatever the browser believes.
 */
export function startBatchSeparateProductConflict(
    preview: StartBatchPreviewLite | null | undefined,
    product: StartBatchProductLite | null | undefined,
): boolean {
    if (!preview || !product) return false;

    return packagingBelongsToSeparateProduct(startBatchPackaging(preview), product);
}

/**
 * The gaps the modal names. The top-level verdict — the RUN's, the same
 * judgment the entry will freeze — wins when the backend sends one, so the
 * modal and Completed Today say one thing about one run. Otherwise (an
 * older backend) the CHOSEN standard's verdict — which, by the server's
 * rule (ProductVariantService::standardStatus), includes every word its
 * packagings say, siblings included. With no standard chosen yet (several
 * on offer, none picked) nothing is named: the standard question comes
 * first, and naming the union would name gaps of a standard this run will
 * not use.
 *
 * Words, not keys, and each once: the server orders and dedupes its list;
 * this dedupes again so a hand-built or older payload cannot print "counts,
 * counts". An unknown key is shown readably rather than dropped.
 */
function gapsFor(
    preview: StartBatchPreviewLite | null | undefined,
    chosen: StartBatchVariantLite | undefined,
): string[] {
    const verdict = preview?.configuration_status ?? chosen?.configuration_status ?? null;
    if (!verdict) return [];

    const complete = typeof verdict.complete === 'boolean' ? verdict.complete : verdict.state === 'complete';
    if (complete) return [];

    const keys = Array.isArray(verdict.missing) ? verdict.missing : [];
    const seen = new Set<string>();
    const words: string[] = [];
    for (const key of keys) {
        if (seen.has(key)) continue;
        seen.add(key);
        // missingWords([k]) is exactly wordFor(k) — the one vocabulary,
        // without exporting a second name for it.
        words.push(missingWords([key]));
    }
    return words;
}

// ---------------------------------------------------------------------------
// What the run posts as — read from the packaging the server resolved
// ---------------------------------------------------------------------------

/**
 * The packaging this run RESOLVED to — `preview.packaging` is the server's
 * answer (chosen id, else the only complete one, else the default), but it
 * carries no identity; this finds the same row among the variants, which
 * do. Undefined when nothing is resolved (a choice still open, or a
 * standard with no packagings) or when the id is not among the variants.
 */
export function startBatchPackaging(
    preview: StartBatchPreviewLite | null | undefined,
): StartBatchPackagingLite | undefined {
    const resolvedId = preview?.packaging?.id;
    if (resolvedId === null || resolvedId === undefined) return undefined;

    for (const v of preview?.variants ?? []) {
        const hit = v.packagings.find((p) => p.id === resolvedId);
        if (hit) return hit;
    }
    return undefined;
}

export interface StartBatchTallyIdentity {
    /** "sku · name" (the name alone when the SKU is the name); null when undecided. */
    label: string | null;
    /** Whose identity: the packing's own, or the product's item (say so on screen); null when undecided. */
    source: 'packaging' | 'product' | null;
}

/**
 * Which Tally item this run's production posts as, as far as the preview
 * can say (DEC-20260810-003): the resolved packing's own item when it has
 * one; else the product's item — including a standard that has no packing
 * at all, which posts as the product; UNDECIDED (nulls) while a packing
 * choice is still open, when the preview has not arrived, or when the
 * product is unknown. Never guessed.
 */
export function startBatchTallyIdentity(
    preview: StartBatchPreviewLite | null | undefined,
    product: StartBatchProductLite | null | undefined,
    chosenStandardId?: number | null,
): StartBatchTallyIdentity {
    const undecided: StartBatchTallyIdentity = { label: null, source: null };
    if (!preview || !product) return undecided;

    const packaging = startBatchPackaging(preview);
    if (packaging) {
        if (packaging.tally_item) {
            return { label: tallyIdentityLabel(packaging.tally_item), source: 'packaging' };
        }
        return { label: tallyIdentityLabel(product), source: 'product' };
    }

    // No packaging resolved: a standard with NO packagings posts as the
    // product; a standard whose packings are still to be chosen is open.
    const chosen = chosenStartVariant(preview, chosenStandardId);
    if (chosen && chosen.packagings.length === 0) {
        return { label: tallyIdentityLabel(product), source: 'product' };
    }

    return undecided;
}

// ---------------------------------------------------------------------------
// The mould, as the preview's configuration names it
// ---------------------------------------------------------------------------

/**
 * "code · name" for the mould the approved machine configuration runs; the
 * one of them that exists when only one does (or when they are the same
 * word); null when no configuration governs the run or it names no mould —
 * a product standard carries no mould, so nothing else is consulted.
 */
export function mouldLabel(
    mould: { id?: number; code?: string | null; name?: string | null } | null | undefined,
): string | null {
    if (!mould) return null;
    const code = (mould.code ?? '').trim();
    const name = (mould.name ?? '').trim();
    if (code && name && code !== name) return `${code} · ${name}`;
    return name || code || null;
}
