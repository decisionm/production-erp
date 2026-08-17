import { describe, expect, it } from 'vitest';
import { incompleteWordsFromServer } from './productStandardsConfig';
import {
    chosenStartVariant,
    mouldLabel,
    startBatchChoices,
    startBatchPackaging,
    startBatchTallyIdentity,
    type StartBatchPackagingLite,
    type StartBatchPreviewLite,
    type StartBatchVariantLite,
} from './startBatchChoices';

// ---------------------------------------------------------------------------
// THE INLINE CONDITIONS, VERBATIM — the Start Batch modal as it stood before
// this extraction (ShiftProductionEntryPage.tsx, Phase 5.5 discovery: the
// "Which standard is this run?" radio and the "How is it packed?" radio).
// Kept in this file on purpose: it is the contract the helper must equal.
//
//   {(batchPreview?.variants?.length ?? 0) > 1 && ( <Form.Item label="Which standard is this run?"> … )}
//
//   {(() => {
//       const chosen = (batchPreview?.variants ?? []).find((v) => v.id === selectedStandardId)
//           ?? (batchPreview?.variants?.length === 1 ? batchPreview.variants[0] : undefined);
//       if (!chosen || chosen.packagings.length < 2) return null;
//       …
//       options={chosen.packagings.map((p) => ({
//           value: p.id,
//           label: p.is_complete
//               ? p.label
//               : `${p.label} — ${incompleteWordsFromServer(p) ?? 'incomplete in workbook'}`,
//           disabled: !p.is_complete,
//       }))}
//   })()}
// ---------------------------------------------------------------------------
function inlineConditions(
    batchPreview: StartBatchPreviewLite | null | undefined,
    selectedStandardId: number | undefined,
): { askStandard: boolean; askPacking: boolean; disabledPackagings: Array<{ id: number; words: string }> } {
    const askStandard = (batchPreview?.variants?.length ?? 0) > 1;

    const chosen = (batchPreview?.variants ?? []).find((v) => v.id === selectedStandardId)
        ?? (batchPreview?.variants?.length === 1 ? batchPreview.variants[0] : undefined);
    if (!chosen || chosen.packagings.length < 2) {
        return { askStandard, askPacking: false, disabledPackagings: [] };
    }

    const disabledPackagings = chosen.packagings
        .filter((p) => !p.is_complete)
        .map((p) => ({ id: p.id, words: incompleteWordsFromServer(p) ?? 'incomplete in workbook' }));

    return { askStandard, askPacking: true, disabledPackagings };
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

let nextId = 100;

type PackagingShape = 'complete' | 'incomplete-flag-only' | 'incomplete-server-counts' | 'incomplete-server-identity';

const packaging = (shape: PackagingShape, overrides: Partial<StartBatchPackagingLite> = {}): StartBatchPackagingLite => {
    const id = nextId++;
    const base: StartBatchPackagingLite = {
        id,
        label: `Tray + Box (#${id})`,
        is_complete: true,
        tally_item: null,
        configuration_status: { state: 'complete', missing: [] },
    };
    if (shape === 'incomplete-flag-only') {
        // An older backend: `is_complete` false, no configuration_status at all.
        return { ...base, is_complete: false, configuration_status: undefined, ...overrides };
    }
    if (shape === 'incomplete-server-counts') {
        return { ...base, is_complete: false, configuration_status: { state: 'incomplete', missing: ['counts'] }, ...overrides };
    }
    if (shape === 'incomplete-server-identity') {
        // Counts stated (runnable — is_complete true) but no Tally identity anywhere.
        return { ...base, is_complete: true, configuration_status: { state: 'incomplete', missing: ['tally_identity'] }, ...overrides };
    }
    return { ...base, ...overrides };
};

const variant = (packagings: StartBatchPackagingLite[], overrides: Partial<StartBatchVariantLite> = {}): StartBatchVariantLite => ({
    id: nextId++,
    packagings,
    configuration_status: { state: 'complete', missing: [] },
    ...overrides,
});

const preview = (variants: StartBatchVariantLite[], overrides: Partial<StartBatchPreviewLite> = {}): StartBatchPreviewLite => ({
    variants,
    packaging: null,
    ...overrides,
});

const PACKAGING_SHAPES: PackagingShape[] = ['complete', 'incomplete-flag-only', 'incomplete-server-counts', 'incomplete-server-identity'];

/** Every packaging list this matrix runs: 0, 1 and N packagings, complete and incomplete. */
function packagingLists(): Array<{ name: string; packagings: () => StartBatchPackagingLite[] }> {
    const lists: Array<{ name: string; packagings: () => StartBatchPackagingLite[] }> = [
        { name: '0 packagings', packagings: () => [] },
    ];
    for (const shape of PACKAGING_SHAPES) {
        lists.push({ name: `1 packaging (${shape})`, packagings: () => [packaging(shape)] });
    }
    lists.push({ name: '2 packagings (both complete)', packagings: () => [packaging('complete'), packaging('complete')] });
    for (const shape of PACKAGING_SHAPES) {
        if (shape === 'complete') continue;
        lists.push({ name: `2 packagings (complete + ${shape})`, packagings: () => [packaging('complete'), packaging(shape)] });
    }
    lists.push({
        name: '3 packagings (complete + counts + identity)',
        packagings: () => [packaging('complete'), packaging('incomplete-server-counts'), packaging('incomplete-server-identity')],
    });
    lists.push({
        name: '2 packagings (both incomplete)',
        packagings: () => [packaging('incomplete-flag-only'), packaging('incomplete-server-counts')],
    });
    return lists;
}

// ---------------------------------------------------------------------------
// RED-BEFORE: the helper's verdict equals the inline conditions
// ---------------------------------------------------------------------------

describe('startBatchChoices equals the inline Start Batch conditions (0/1/N variants × 0/1/N packagings × complete/incomplete)', () => {
    it('no preview at all — asks nothing (the item is not chosen yet)', () => {
        for (const p of [undefined, null]) {
            const expected = inlineConditions(p, undefined);
            const actual = startBatchChoices(p, undefined);
            expect(actual.askStandard).toBe(expected.askStandard);
            expect(actual.askPacking).toBe(expected.askPacking);
            expect(actual.disabledPackagings).toEqual(expected.disabledPackagings);
            expect(actual.askStandard).toBe(false);
            expect(actual.askPacking).toBe(false);
        }
    });

    it('0 variants — no standard question, no packing question, whatever is selected', () => {
        const p = preview([]);
        for (const selected of [undefined, 1, 999]) {
            const expected = inlineConditions(p, selected);
            const actual = startBatchChoices(p, selected);
            expect(actual).toMatchObject(expected);
            expect(actual.askStandard).toBe(false);
            expect(actual.askPacking).toBe(false);
        }
    });

    it('1 variant × every packaging list — never asks the standard; asks packing only when ≥ 2 packagings', () => {
        for (const list of packagingLists()) {
            const v = variant(list.packagings());
            const p = preview([v]);
            // Whether the (single) standard is selected or not must not change the answer.
            for (const selected of [undefined, v.id, 999]) {
                const expected = inlineConditions(p, selected);
                const actual = startBatchChoices(p, selected);
                expect(actual, `${list.name} / selected=${selected}`).toMatchObject(expected);
                expect(actual.askStandard, list.name).toBe(false);
                expect(actual.askPacking, list.name).toBe(v.packagings.length >= 2);
            }
        }
    });

    it('N variants × every packaging list — asks the standard; asks packing only once a variant is chosen and it has ≥ 2 packagings', () => {
        for (const listA of packagingLists()) {
            for (const listB of packagingLists()) {
                const a = variant(listA.packagings());
                const b = variant(listB.packagings());
                const p = preview([a, b]);
                for (const selected of [undefined, a.id, b.id, 999]) {
                    const expected = inlineConditions(p, selected);
                    const actual = startBatchChoices(p, selected);
                    const label = `A=${listA.name} B=${listB.name} selected=${selected}`;
                    expect(actual, label).toMatchObject(expected);
                    expect(actual.askStandard, label).toBe(true);
                    const chosen = selected === a.id ? a : selected === b.id ? b : undefined;
                    expect(actual.askPacking, label).toBe(!!chosen && chosen.packagings.length >= 2);
                }
            }
        }
    });

    it('three variants — the picker is a real choice; the second variant chosen governs the packing question', () => {
        const a = variant([packaging('complete')]);
        const b = variant([packaging('complete'), packaging('incomplete-server-counts')]);
        const c = variant([]);
        const p = preview([a, b, c]);
        for (const selected of [undefined, a.id, b.id, c.id]) {
            expect(startBatchChoices(p, selected)).toMatchObject(inlineConditions(p, selected));
        }
        expect(startBatchChoices(p, b.id).askPacking).toBe(true);
        expect(startBatchChoices(p, b.id).disabledPackagings).toEqual([
            { id: b.packagings[1].id, words: 'incomplete: counts missing' },
        ]);
    });

    it('disabled packagings carry the server\'s words when the preview has them, the old wording otherwise', () => {
        const flagOnly = packaging('incomplete-flag-only');
        const counts = packaging('incomplete-server-counts');
        const identity = packaging('incomplete-server-identity'); // runnable — is_complete true — so NOT disabled
        const v = variant([packaging('complete'), flagOnly, counts, identity]);
        const p = preview([v]);

        const actual = startBatchChoices(p, undefined);
        expect(actual).toMatchObject(inlineConditions(p, undefined));
        expect(actual.disabledPackagings).toEqual([
            { id: flagOnly.id, words: 'incomplete in workbook' },
            { id: counts.id, words: 'incomplete: counts missing' },
        ]);
    });
});

// ---------------------------------------------------------------------------
// "Ask only when there is a real choice" — pinned (audit §49)
// ---------------------------------------------------------------------------

describe('ask only when there is a real choice', () => {
    it('a fully configured SKU — one standard, one packaging — asks nothing and names no gap', () => {
        const only = packaging('complete', { tally_item: { id: 7, sku: 'KID-500-T', name: '500ML KIDNEY TRAY', guid: 'g-7' } });
        const p = preview([variant([only])], { packaging: { id: only.id } });
        expect(startBatchChoices(p, undefined)).toEqual({
            askStandard: false,
            askPacking: false,
            disabledPackagings: [],
            gaps: [],
        });
    });

    it('a two-packaging SKU — one standard — asks ONE question (how it is packed)', () => {
        const p = preview([variant([packaging('complete'), packaging('complete')])]);
        const c = startBatchChoices(p, undefined);
        expect(c.askStandard).toBe(false);
        expect(c.askPacking).toBe(true);
    });

    it('a two-standard SKU asks the standard first, and packing only when the chosen standard genuinely offers two', () => {
        const a = variant([packaging('complete')]);
        const b = variant([packaging('complete'), packaging('complete')]);
        const p = preview([a, b]);
        expect(startBatchChoices(p, undefined)).toMatchObject({ askStandard: true, askPacking: false });
        expect(startBatchChoices(p, a.id)).toMatchObject({ askStandard: true, askPacking: false });
        expect(startBatchChoices(p, b.id)).toMatchObject({ askStandard: true, askPacking: true });
    });

    it('a variant with one incomplete packaging is not a question — nothing to choose between', () => {
        const p = preview([variant([packaging('incomplete-server-counts')])]);
        const c = startBatchChoices(p, undefined);
        expect(c.askPacking).toBe(false);
        expect(c.disabledPackagings).toEqual([]);
    });
});

// ---------------------------------------------------------------------------
// The gaps the modal names (P5.5-06) — from the server's vocabulary
// ---------------------------------------------------------------------------

describe('gaps', () => {
    it('come from the chosen variant\'s configuration_status, in words, deduplicated', () => {
        const v = variant(
            [packaging('incomplete-server-counts'), packaging('incomplete-server-counts')],
            { configuration_status: { state: 'incomplete', missing: ['cycle_time', 'counts', 'counts', 'tally_identity'] } },
        );
        const p = preview([v]);
        expect(startBatchChoices(p, undefined).gaps).toEqual(['cycle time', 'counts', 'Tally identity']);
    });

    it('a top-level preview.configuration_status wins when a backend sends one', () => {
        const v = variant([packaging('complete')], { configuration_status: { state: 'incomplete', missing: ['counts'] } });
        const p = preview([v], { configuration_status: { complete: false, missing: ['standard', 'unit_weight'] } });
        expect(startBatchChoices(p, undefined).gaps).toEqual(['standard', 'unit weight']);
    });

    it('a complete top-level verdict names nothing, even when a variant is incomplete', () => {
        const v = variant([packaging('complete')], { configuration_status: { state: 'incomplete', missing: ['counts'] } });
        const p = preview([v], { configuration_status: { complete: true, missing: [] } });
        expect(startBatchChoices(p, undefined).gaps).toEqual([]);
    });

    it('is empty when no variant is chosen yet among several — the standard question comes first', () => {
        const a = variant([packaging('complete')], { configuration_status: { state: 'incomplete', missing: ['counts'] } });
        const b = variant([packaging('complete')], { configuration_status: { state: 'incomplete', missing: ['cavities'] } });
        const p = preview([a, b]);
        expect(startBatchChoices(p, undefined).gaps).toEqual([]);
        expect(startBatchChoices(p, a.id).gaps).toEqual(['counts']);
        expect(startBatchChoices(p, b.id).gaps).toEqual(['cavities']);
    });

    it('is empty when the preview carries no verdict at all (an older backend) — a gap this helper cannot see is not one it declares', () => {
        const v = variant([packaging('incomplete-flag-only')], { configuration_status: undefined });
        expect(startBatchChoices(preview([v]), undefined).gaps).toEqual([]);
        expect(startBatchChoices(preview([]), undefined).gaps).toEqual([]);
        expect(startBatchChoices(undefined, undefined).gaps).toEqual([]);
    });

    it('an unknown server key is shown readably rather than dropped', () => {
        const v = variant([], { configuration_status: { state: 'incomplete', missing: ['mould_code'] } });
        expect(startBatchChoices(preview([v]), undefined).gaps).toEqual(['mould code']);
    });
});

// ---------------------------------------------------------------------------
// The packaging the run resolved to, and the Tally identity it posts as
// ---------------------------------------------------------------------------

describe('chosenStartVariant / startBatchPackaging / startBatchTallyIdentity', () => {
    const product = { id: 1, sku: 'B-500-KID', name: '500ML KIDNEY' };

    it('chosenStartVariant follows the inline rule: the selected id, else the only variant', () => {
        const a = variant([]);
        const b = variant([]);
        expect(chosenStartVariant(preview([a]), undefined)?.id).toBe(a.id);
        expect(chosenStartVariant(preview([a]), 999)?.id).toBe(a.id);
        expect(chosenStartVariant(preview([a, b]), undefined)).toBeUndefined();
        expect(chosenStartVariant(preview([a, b]), b.id)?.id).toBe(b.id);
        expect(chosenStartVariant(preview([]), undefined)).toBeUndefined();
        expect(chosenStartVariant(null, undefined)).toBeUndefined();
    });

    it('startBatchPackaging is the packaging the server resolved (preview.packaging), found among the variants', () => {
        const tray = packaging('complete');
        const pouch = packaging('complete');
        const p = preview([variant([tray, pouch])], { packaging: { id: pouch.id } });
        expect(startBatchPackaging(p)?.id).toBe(pouch.id);
        expect(startBatchPackaging(preview([variant([tray, pouch])], { packaging: null }))).toBeUndefined();
        expect(startBatchPackaging(preview([variant([tray])], { packaging: { id: 424242 } }))).toBeUndefined();
    });

    it('a packing with its own identity posts as that item — sku · name', () => {
        const own = packaging('complete', { tally_item: { id: 7, sku: 'KID-500-T', name: '500ML KIDNEY TRAY', guid: 'g-7' } });
        const p = preview([variant([own])], { packaging: { id: own.id } });
        expect(startBatchTallyIdentity(p, product)).toEqual({
            label: 'KID-500-T · 500ML KIDNEY TRAY',
            source: 'packaging',
        });
    });

    it('a packing without its own identity posts as the product\'s item — and says so', () => {
        const plain = packaging('complete', { tally_item: null });
        const p = preview([variant([plain])], { packaging: { id: plain.id } });
        expect(startBatchTallyIdentity(p, product)).toEqual({
            label: 'B-500-KID · 500ML KIDNEY',
            source: 'product',
        });
    });

    it('a standard with no packaging at all posts as the product\'s item', () => {
        const p = preview([variant([])], { packaging: null });
        expect(startBatchTallyIdentity(p, product)).toEqual({ label: 'B-500-KID · 500ML KIDNEY', source: 'product' });
    });

    it('is undecided while a two-packaging choice is still open — never guessed', () => {
        const p = preview([variant([packaging('complete'), packaging('complete')])], { packaging: null });
        expect(startBatchTallyIdentity(p, product)).toEqual({ label: null, source: null });
    });

    it('is undecided with no preview or no product', () => {
        expect(startBatchTallyIdentity(undefined, product)).toEqual({ label: null, source: null });
        const plain = packaging('complete');
        const p = preview([variant([plain])], { packaging: { id: plain.id } });
        expect(startBatchTallyIdentity(p, undefined)).toEqual({ label: null, source: null });
    });

    it('a product whose SKU is its name reads once, not twice', () => {
        const plain = packaging('complete');
        const p = preview([variant([plain])], { packaging: { id: plain.id } });
        expect(startBatchTallyIdentity(p, { id: 2, sku: '500ML KIDNEY', name: '500ML KIDNEY' })).toEqual({
            label: '500ML KIDNEY',
            source: 'product',
        });
    });
});

// ---------------------------------------------------------------------------
// The mould, as the configuration names it
// ---------------------------------------------------------------------------

describe('mouldLabel', () => {
    it('reads "code · name" from the configuration\'s mould', () => {
        expect(mouldLabel({ id: 3, code: 'MLD-200-BRUTE', name: '200ml Brute 4-cav' })).toBe('MLD-200-BRUTE · 200ml Brute 4-cav');
    });

    it('collapses to the one word that exists, or the shared word', () => {
        expect(mouldLabel({ code: 'MLD-1', name: null })).toBe('MLD-1');
        expect(mouldLabel({ code: '', name: 'Mould 1' })).toBe('Mould 1');
        expect(mouldLabel({ code: 'Mould 1', name: 'Mould 1' })).toBe('Mould 1');
    });

    it('is null — a dash on screen — when no configuration names a mould; nothing else is consulted', () => {
        expect(mouldLabel(null)).toBeNull();
        expect(mouldLabel(undefined)).toBeNull();
        expect(mouldLabel({ code: null, name: null })).toBeNull();
        expect(mouldLabel({ code: '  ', name: '' })).toBeNull();
    });
});
