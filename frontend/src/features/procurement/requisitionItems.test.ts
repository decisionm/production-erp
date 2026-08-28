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

    it('shows a dash rather than a count when the only line carries no item', () => {
        expect(requisitionItemsLabel([{ item: null }])).toBe('—');
    });

    /**
     * A dash plus a count ("—  +2") tells a buyer strictly less than the line
     * count it replaced, so the first line that actually names something wins.
     */
    it('skips an opening line whose item the master no longer serves', () => {
        expect(requisitionItemsLabel([
            { item: null },
            { item: { sku: 'Caps', name: 'Caps' } },
            { item: { sku: 'Cartons', name: 'Cartons' } },
        ])).toBe('Caps  +2');
    });

    it('shows a dash when no line names anything', () => {
        expect(requisitionItemsLabel([{ item: null }, { item: null }])).toBe('—');
    });

    it('shows a dash for a requisition with no lines', () => {
        expect(requisitionItemsLabel([])).toBe('—');
        expect(requisitionItemsLabel(undefined)).toBe('—');
    });
});
