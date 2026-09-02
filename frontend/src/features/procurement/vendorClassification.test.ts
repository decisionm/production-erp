import { describe, expect, it } from 'vitest';
import { DEFAULT_VENDOR_VIEW, vendorPickerOptions } from './vendorClassification';
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
