import { describe, expect, it } from 'vitest';
import { requisitionItemsLabel } from './requisitionItems';

describe('requisitionItemsLabel', () => {
    it('names the single item a one-line requisition asks for', () => {
        expect(requisitionItemsLabel([{ item: { sku: 'PET-RESIN', name: 'PET Resin (Virgin Grade)' } }]))
            .toBe('PET-RESIN — PET Resin (Virgin Grade)');
    });

    it('names the first item and counts the rest', () => {
        expect(requisitionItemsLabel([
            { item: { sku: 'Pet Resin', name: 'Pet Resin' } },
            { item: { sku: 'Caps', name: 'Caps' } },
            { item: { sku: 'Cartons', name: 'Cartons' } },
        ])).toBe('Pet Resin  +2');
    });

    /** The catalogue stores the same string in both fields; itemLabel already de-duplicates it. */
    it('does not repeat a name whose sku is the same string', () => {
        expect(requisitionItemsLabel([{ item: { sku: '100 Ml Tray', name: '100Ml Tray' } }])).toBe('100Ml Tray');
    });

    it('shows a dash rather than a count when a line carries no item', () => {
        expect(requisitionItemsLabel([{ item: null }])).toBe('—');
    });

    it('shows a dash for a requisition with no lines', () => {
        expect(requisitionItemsLabel([])).toBe('—');
        expect(requisitionItemsLabel(undefined)).toBe('—');
    });
});
