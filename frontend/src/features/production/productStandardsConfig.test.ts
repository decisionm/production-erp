import { describe, expect, it } from 'vitest';
import {
    attachmentNote,
    fmt,
    hasTallyIdentity,
    incompleteWordsFromServer,
    missingWords,
    num,
    packagingCountsSummary,
    packagingState,
    provisionalSkuTag,
    standardSpec,
    tallyIdentityLabel,
} from './productStandardsConfig';
import type { ProductStandardsWorkspaceRow, StandardPackaging } from './types';

const packaging = (overrides: Partial<StandardPackaging> = {}): StandardPackaging => ({
    id: 1,
    mode: 'tray',
    label: 'Tray + Box (24/tray · 480/box)',
    nos_per_pouch: null,
    pouches_per_box: null,
    nos_per_tray: 24,
    trays_per_box: 20,
    nos_per_box: 480,
    is_default: true,
    is_complete: true,
    tally_item: { id: 7, name: '500ML KIDNEY TRAY', sku: 'KID-500-T', guid: 'guid-7' },
    ...overrides,
});

/** The product's item as the workspace's verbatim rows carry it. */
const product = { id: 3, sku: '500ML KIDNEY', name: '500ML KIDNEY', tally_stock_item_guid: 'guid-3' };

// ---------------------------------------------------------------------------
// packagingState — configured or not, and which pieces are missing
// ---------------------------------------------------------------------------

describe('packagingState — the server verdict outranks the local derivation', () => {
    it('reads a packing/standard verdict (state + missing) under configuration_status verbatim', () => {
        const state = packagingState(
            packaging({ configuration_status: { state: 'incomplete', missing: ['tally_identity'], ambiguity: null } }),
        );
        expect(state).toEqual({
            complete: false,
            missing: ['tally_identity'],
            words: 'incomplete: Tally identity missing',
        });
    });

    it('reads a product-shaped verdict (complete: boolean) the same way', () => {
        expect(packagingState(packaging({ configuration_status: { complete: false, missing: ['counts'] } }))).toEqual({
            complete: false,
            missing: ['counts'],
            words: 'incomplete: counts missing',
        });
    });

    it('a server verdict of complete wins even when the local read would disagree', () => {
        // The server is the authority; a stale is_complete on the same
        // payload must not turn a configured packing back into a question.
        const state = packagingState(
            packaging({ is_complete: false, tally_item: null, configuration_status: { state: 'complete', missing: [] } }),
        );
        expect(state).toEqual({ complete: true, missing: [], words: null });
    });

    it('reads the variants endpoint flat shape (state + missing on the row) the same way', () => {
        const state = packagingState({
            is_complete: true,
            tally_item: null,
            state: 'incomplete',
            missing: ['counts', 'tally_identity'],
        });
        expect(state.complete).toBe(false);
        expect(state.missing).toEqual(['counts', 'tally_identity']);
        expect(state.words).toBe('incomplete: counts and Tally identity missing');
    });

    it('a server "incomplete" with an empty missing list still says incomplete, with no invented piece', () => {
        const state = packagingState(packaging({ configuration_status: { state: 'incomplete', missing: [] } }));
        expect(state).toEqual({ complete: false, missing: [], words: 'incomplete' });
    });
});

describe('packagingState — the local derivation, for rows the server did not judge (the same rule)', () => {
    it('counts stated and a real Tally item of its own = complete, no words', () => {
        expect(packagingState(packaging())).toEqual({ complete: true, missing: [], words: null });
    });

    it('no identity of its own but a product item Tally carries = complete — it posts as the product (DEC-20260810-003)', () => {
        expect(packagingState(packaging({ tally_item: null }), product)).toEqual({ complete: true, missing: [], words: null });
    });

    it('no identity of its own and no product item = Tally identity missing', () => {
        expect(packagingState(packaging({ tally_item: null }), null)).toEqual({
            complete: false,
            missing: ['tally_identity'],
            words: 'incomplete: Tally identity missing',
        });
        // No product passed at all reads the same: the helper cannot see a fallback it was not given.
        expect(packagingState(packaging({ tally_item: null })).missing).toEqual(['tally_identity']);
    });

    it('an own identity Tally has never heard of (no GUID) is Tally identity missing, whatever the product has', () => {
        expect(packagingState(packaging({ tally_item: { id: 9, name: 'X', guid: null } }), product).missing).toEqual([
            'tally_identity',
        ]);
    });

    it('a product item that is a local fixture or GUID-less does not count as an identity', () => {
        expect(
            packagingState(packaging({ tally_item: null }), { ...product, tally_stock_item_guid: null }).missing,
        ).toEqual(['tally_identity']);
        expect(
            packagingState(packaging({ tally_item: null }), { ...product, is_local_fixture: true }).missing,
        ).toEqual(['tally_identity']);
    });

    it('an omitted tally_item key (older payload) reads as no identity of its own, not as a crash', () => {
        expect(packagingState(packaging({ tally_item: undefined })).missing).toEqual(['tally_identity']);
    });

    it('a half-stated workbook row is missing its counts', () => {
        expect(packagingState(packaging({ is_complete: false }))).toEqual({
            complete: false,
            missing: ['counts'],
            words: 'incomplete: counts missing',
        });
    });

    it('both missing lists both, in the server\'s vocabulary order — counts before identity', () => {
        expect(packagingState(packaging({ is_complete: false, tally_item: null }))).toEqual({
            complete: false,
            missing: ['counts', 'tally_identity'],
            words: 'incomplete: counts and Tally identity missing',
        });
    });

    it('a null / undefined packaging is not configured and says so without throwing', () => {
        expect(packagingState(null).complete).toBe(false);
        expect(packagingState(undefined).words).toBe('incomplete: Tally identity missing');
    });
});

describe('hasTallyIdentity — ProductVariantService::hasTallyIdentity, client-side', () => {
    it('needs a row, a GUID, and not a fixture', () => {
        expect(hasTallyIdentity({ id: 1, guid: 'g' })).toBe(true);
        expect(hasTallyIdentity({ id: 1, tally_stock_item_guid: 'g' })).toBe(true);
        expect(hasTallyIdentity({ id: 1, guid: null })).toBe(false);
        expect(hasTallyIdentity({ id: 1, tally_stock_item_guid: '' })).toBe(false);
        expect(hasTallyIdentity({ id: 1, guid: 'g', is_local_fixture: true })).toBe(false);
        expect(hasTallyIdentity({ id: 1, guid: 'g', sku: 'LOCAL-500ML' })).toBe(false);
        expect(hasTallyIdentity(null)).toBe(false);
    });

    it('a payload that carries no GUID key at all is unknown, and unknown is not declared missing', () => {
        expect(hasTallyIdentity({ id: 1, name: '500ML KIDNEY' })).toBe(true);
    });
});

describe('missingWords — the server keys, in words', () => {
    it('reads every key of the server\'s vocabulary (ProductVariantService::MISSING_VOCABULARY)', () => {
        expect(missingWords(['standard'])).toBe('standard');
        expect(missingWords(['cavities'])).toBe('cavities');
        expect(missingWords(['unit_weight'])).toBe('unit weight');
        expect(missingWords(['cycle_time'])).toBe('cycle time');
        expect(missingWords(['packaging'])).toBe('packaging');
        expect(missingWords(['counts'])).toBe('counts');
        expect(missingWords(['tally_identity'])).toBe('Tally identity');
    });

    it('joins two with "and" and three with commas and "and"', () => {
        expect(missingWords(['tally_identity', 'counts'])).toBe('Tally identity and counts');
        expect(missingWords(['tally_identity', 'counts', 'cavities'])).toBe('Tally identity, counts and cavities');
    });

    it('shows an unknown key readably rather than dropping it — a new server key must not vanish', () => {
        expect(missingWords(['mould_number'])).toBe('mould number');
    });

    it('is empty for nothing missing', () => {
        expect(missingWords([])).toBe('');
    });
});

describe('incompleteWordsFromServer — the Shift Floor option suffix', () => {
    it('is the words only when the SERVER judged the packing incomplete', () => {
        expect(
            incompleteWordsFromServer(packaging({ configuration_status: { state: 'incomplete', missing: ['counts'], ambiguity: null } })),
        ).toBe('incomplete: counts missing');
    });

    it('is null when the server sent no verdict — the caller keeps its own old wording', () => {
        expect(incompleteWordsFromServer(packaging({ is_complete: false, tally_item: null }))).toBeNull();
    });

    it('is null when the server verdict is complete', () => {
        expect(incompleteWordsFromServer(packaging({ configuration_status: { state: 'complete', missing: [] } }))).toBeNull();
    });
});

// ---------------------------------------------------------------------------
// tallyIdentityLabel — sku · name, or the honest absence
// ---------------------------------------------------------------------------

describe('tallyIdentityLabel', () => {
    it('is "sku · name" when the two differ', () => {
        expect(tallyIdentityLabel({ id: 1, sku: 'KID-500-T', name: '500ML KIDNEY TRAY' })).toBe(
            'KID-500-T · 500ML KIDNEY TRAY',
        );
    });

    it('is the name alone when the SKU is the name (this catalogue’s normal case), ignoring case and spacing', () => {
        expect(tallyIdentityLabel({ id: 1, sku: '500ML KIDNEY', name: '500ML KIDNEY' })).toBe('500ML KIDNEY');
        expect(tallyIdentityLabel({ id: 1, sku: '500 ml kidney', name: '500ML KIDNEY' })).toBe('500ML KIDNEY');
    });

    it('is the name alone when there is no SKU, and the SKU alone when there is no name', () => {
        expect(tallyIdentityLabel({ id: 1, name: '500ML KIDNEY' })).toBe('500ML KIDNEY');
        expect(tallyIdentityLabel({ id: 1, sku: 'KID-500', name: '' })).toBe('KID-500');
    });

    it('says "no Tally identity" for null, undefined, and an item with neither', () => {
        expect(tallyIdentityLabel(null)).toBe('no Tally identity');
        expect(tallyIdentityLabel(undefined)).toBe('no Tally identity');
        expect(tallyIdentityLabel({ id: 1, name: '  ', sku: '' })).toBe('no Tally identity');
    });
});

// ---------------------------------------------------------------------------
// provisionalSkuTag — the mark, never the SKU that should replace it
// ---------------------------------------------------------------------------

describe('provisionalSkuTag', () => {
    it('is the tag text when the item is flagged, whichever way the flag serialises', () => {
        expect(provisionalSkuTag({ sku_provisional: true })).toBe('provisional SKU');
        expect(provisionalSkuTag({ sku_provisional: 1 })).toBe('provisional SKU');
        expect(provisionalSkuTag({ sku_provisional: '1' })).toBe('provisional SKU');
    });

    it('is null when the flag is false, absent, or the item is missing', () => {
        expect(provisionalSkuTag({ sku_provisional: false })).toBeNull();
        expect(provisionalSkuTag({ sku_provisional: 0 })).toBeNull();
        expect(provisionalSkuTag({})).toBeNull();
        expect(provisionalSkuTag(null)).toBeNull();
        expect(provisionalSkuTag(undefined)).toBeNull();
    });
});

// ---------------------------------------------------------------------------
// The helpers that moved out of the page unchanged
// ---------------------------------------------------------------------------

describe('fmt / num — the wire decimal, shown and held', () => {
    it('fmt trims trailing zeros and appends the suffix, and dashes the blanks', () => {
        expect(fmt('24.5000')).toBe('24.5');
        expect(fmt(10.6, ' s')).toBe('10.6 s');
        expect(fmt(null)).toBe('—');
        expect(fmt('')).toBe('—');
        expect(fmt('abc')).toBe('—');
    });

    it('num turns a decimal string into a number a form can hold, or undefined', () => {
        expect(num('24.5000')).toBe(24.5);
        expect(num(3)).toBe(3);
        expect(num(null)).toBeUndefined();
        expect(num('')).toBeUndefined();
        expect(num('x')).toBeUndefined();
    });
});

const row = (overrides: Partial<ProductStandardsWorkspaceRow> = {}): ProductStandardsWorkspaceRow =>
    ({
        id: 1,
        item: null,
        source_product_name: '500ML KIDNEY',
        carton_spec: null,
        tray_spec: null,
        pouch_spec: null,
        packagings: [],
        ...overrides,
    }) as ProductStandardsWorkspaceRow;

describe('attachmentNote — who attached the item, and when', () => {
    it('is silent for a row the importer matched (no person, no date)', () => {
        expect(attachmentNote(row())).toBeNull();
    });

    it('names the person and the date when both are known', () => {
        expect(
            attachmentNote(row({ item_attached_by: { id: 3, name: 'Kumar' }, item_attached_at: '2026-08-10T09:12:00Z' })),
        ).toBe('attached by Kumar · 2026-08-10');
    });

    it('never prints a bare user id — the date alone says a person did it', () => {
        expect(attachmentNote(row({ item_attached_by: 7, item_attached_at: '2026-08-10T09:12:00Z' }))).toBe(
            'attached here · 2026-08-10',
        );
    });

    it('names the person alone when there is no date', () => {
        expect(attachmentNote(row({ item_attached_by: { id: 3, name: 'Kumar' } }))).toBe('attached by Kumar');
    });
});

describe('standardSpec — a packing spec and whether it was inferred', () => {
    it('a stated value has no provenance marker', () => {
        expect(standardSpec(row({ carton_spec: 'HM 30.5*49' }), 'carton_spec')).toEqual({
            value: 'HM 30.5*49',
            inferred: null,
        });
    });

    it('a blank is null, whichever way the wire spells blank', () => {
        expect(standardSpec(row({ tray_spec: '' }), 'tray_spec').value).toBeNull();
        expect(standardSpec(row({ pouch_spec: null }), 'pouch_spec').value).toBeNull();
    });

    it('carries the provenance entry only when it says inferred', () => {
        const entry = { inferred: true, from_product: '500ML KIDNEY', from_source_reference: '58' };
        expect(
            standardSpec(row({ carton_spec: 'HM 30.5*49', spec_provenance: { carton_spec: entry } }), 'carton_spec')
                .inferred,
        ).toEqual(entry);
        expect(
            standardSpec(
                row({ carton_spec: 'HM 30.5*49', spec_provenance: { carton_spec: { inferred: false } } }),
                'carton_spec',
            ).inferred,
        ).toBeNull();
    });
});

describe('packagingCountsSummary — a packing\'s counts in one line', () => {
    it('states the mode\'s inner count, the containers per box and the box', () => {
        expect(packagingCountsSummary('tray', { nos_per_tray: 24, trays_per_box: 20, nos_per_box: 480 })).toBe(
            '24/tray × 20 = 480/box',
        );
        expect(packagingCountsSummary('pouch', { nos_per_pouch: 120, pouches_per_box: 4, nos_per_box: 480 })).toBe(
            '120/pouch × 4 = 480/box',
        );
        expect(packagingCountsSummary('direct_box', { nos_per_box: 500 })).toBe('500/box');
    });

    it('prints a count the server did not send as "—", never a filled-in figure', () => {
        expect(packagingCountsSummary('tray', { nos_per_tray: 24, trays_per_box: null, nos_per_box: null })).toBe(
            '24/tray × — = —/box',
        );
        expect(packagingCountsSummary('direct_box', null)).toBe('—/box');
    });
});
