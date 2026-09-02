import { describe, expect, it } from 'vitest';
import { DEFAULT_VENDOR_VIEW, vendorPickerOptions, vendorPickerOptionsWithFallback } from './vendorClassification';
import type { Vendor } from './types';

const vendor = (id: number, classifications: Vendor['classifications']): Vendor =>
    ({ id, code: `V-${id}`, name: `Vendor ${id}`, email: null, phone: null, address: null, gstin: null, state_code: null, tally_ledger_name: null, is_active: true, classifications }) as Vendor;

describe('vendorPickerOptions (DEC-20260902-026)', () => {
    const vendors = [vendor(1, ['resin']), vendor(2, ['service']), vendor(3, []), vendor(4, ['packaging', 'other'])];

    it('shows resin, packaging and consumables/spares/tooling vendors by default', () => {
        expect(DEFAULT_VENDOR_VIEW).toEqual(['resin', 'packaging', 'consumables_spares_tooling']);
        expect(vendorPickerOptions(vendors, false).map((o) => o.value)).toEqual([1, 4]);
    });

    it('shows service, other and unclassified vendors behind the explicit choice', () => {
        expect(vendorPickerOptions(vendors, true).map((o) => o.value)).toEqual([1, 2, 3, 4]);
    });

    it('labels an unclassified vendor as such', () => {
        expect(vendorPickerOptions(vendors, true).find((o) => o.value === 3)?.label).toBe('V-3 — Vendor 3 · Unclassified');
    });
});

// I3: on day one every vendor is UNCLASSIFIED (DEC-20260902-026), so the
// three-class default matches nothing and the PO vendor picker opens on
// "No data" until someone discovers the unlabelled checkbox. The default
// view must never be empty when active vendors exist.
describe('vendorPickerOptionsWithFallback (I3)', () => {
    const allUnclassified = [vendor(1, []), vendor(2, [])];

    it('falls back to every active vendor, and reports showAll turned on, when the default view is empty', () => {
        const { options, showAll } = vendorPickerOptionsWithFallback(allUnclassified, false);
        expect(showAll).toBe(true);
        expect(options.map((o) => o.value)).toEqual([1, 2]);
    });

    it('does not fall back when the default view already has options', () => {
        const classified = [vendor(1, ['resin']), vendor(2, [])];
        const { options, showAll } = vendorPickerOptionsWithFallback(classified, false);
        expect(showAll).toBe(false);
        expect(options.map((o) => o.value)).toEqual([1]);
    });

    it('stays off when there are no active vendors at all — nothing to fall back to', () => {
        const { options, showAll } = vendorPickerOptionsWithFallback([], false);
        expect(showAll).toBe(false);
        expect(options).toEqual([]);
    });

    it('leaves an explicit showAll=true exactly as vendorPickerOptions would', () => {
        const { options, showAll } = vendorPickerOptionsWithFallback(allUnclassified, true);
        expect(showAll).toBe(true);
        expect(options.map((o) => o.value)).toEqual([1, 2]);
    });
});
