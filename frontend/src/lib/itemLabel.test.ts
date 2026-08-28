import { describe, expect, it } from 'vitest';
import { itemLabel, itemPickerLabel, uomOf } from '@/lib/itemLabel';

/**
 * The one helper that names a product on ~40 screens.
 *
 * The `display_name` case is here because a label only two pages could see was
 * the field doing half its job (Codex, a8fe21c): the store picker, the
 * sales-order picker and the material-request screens all name products
 * through this function, and they were still showing Tally's wire name.
 */
describe('itemLabel', () => {
    it('shows the ERP display name in place of the Tally name when one is set', () => {
        expect(itemLabel({ sku: 'BTL-100-RND-840', name: '100ML ROUND - 840 Nos', display_name: '100ml round — 840/box pouch' }))
            .toBe('BTL-100-RND-840 — 100ml round — 840/box pouch');
    });

    it('falls back to the Tally name when the ERP has not been given one', () => {
        // Every trimmed `item:id,sku,name` stub in the app looks like this, so
        // widening the type must change nothing for them.
        expect(itemLabel({ sku: 'BTL-500', name: '500ml PET Bottle' })).toBe('BTL-500 — 500ml PET Bottle');
        expect(itemLabel({ sku: 'BTL-500', name: '500ml PET Bottle', display_name: null })).toBe('BTL-500 — 500ml PET Bottle');
        expect(itemLabel({ sku: 'BTL-500', name: '500ml PET Bottle', display_name: '   ' })).toBe('BTL-500 — 500ml PET Bottle');
    });

    it('still hides a SKU that merely repeats the name, display name included', () => {
        // The catalogue's normal case: the masters pull seeds the SKU from the
        // Tally name, and this spelling drift ('100ml' vs '100 Ml') is why the
        // comparison ignores whitespace as well as case.
        expect(itemLabel({ sku: '1 Litre Pet Bottle - Ovel', name: '1 Litre Pet Bottle - Ovel' }))
            .toBe('1 Litre Pet Bottle - Ovel');
        expect(itemLabel({ sku: '100ml Round', name: 'IGNORED', display_name: '100 Ml Round' })).toBe('100 Ml Round');
    });

    it('renders a dash rather than throwing when the line carries no product', () => {
        expect(itemLabel(null)).toBe('—');
        expect(itemLabel(undefined)).toBe('—');
        expect(itemLabel({})).toBe('—');
    });

    it('shows whichever half exists on its own', () => {
        expect(itemLabel({ sku: 'BTL-500' })).toBe('BTL-500');
        expect(itemLabel({ name: '500ml PET Bottle' })).toBe('500ml PET Bottle');
        expect(itemLabel({ sku: '', display_name: 'Pouch pack' })).toBe('Pouch pack');
    });
});

describe('uomOf', () => {
    it('reports the master’s own unit, and null rather than a guessed one', () => {
        expect(uomOf({ uom: 'Nos.' })).toBe('Nos.');
        expect(uomOf({ uom: '  ' })).toBeNull();
        expect(uomOf(null)).toBeNull();
    });
});

describe('itemPickerLabel', () => {
    it('appends the unit, which is the honest discriminator between near-duplicate names', () => {
        expect(itemPickerLabel({ sku: 'SR-01', name: 'Shrink Roll', uom: 'kg' })).toBe('SR-01 — Shrink Roll · kg');
        expect(itemPickerLabel({ sku: 'Shrink Roll', name: 'Shrink Roll', uom: 'Nos.' })).toBe('Shrink Roll · Nos.');
    });

    it('stays the plain label when the master carries no unit — a blank is not a licence to guess one', () => {
        expect(itemPickerLabel({ sku: 'SR-01', name: 'Shrink Roll', uom: '  ' })).toBe('SR-01 — Shrink Roll');
        expect(itemPickerLabel({ sku: 'SR-01', name: 'Shrink Roll' })).toBe('SR-01 — Shrink Roll');
    });

    it('renders a missing item as the same dash itemLabel does, with no unit dangling after it', () => {
        expect(itemPickerLabel(null)).toBe('—');
        expect(itemPickerLabel({ uom: 'kg' })).toBe('—');
    });
});
