import { describe, expect, it } from 'vitest';
import { isUnclassified, purchasePickerItems } from './purchasePicker';
import type { Item } from '@/features/inventory/types';

const item = (id: number, category: Item['category'], extra: Partial<Item> = {}): Item =>
    ({ id, sku: `S${id}`, name: `Item ${id}`, uom: 'Kgs', is_active: true, is_production_input: true, category, ...extra }) as Item;

describe('purchasePickerItems (DEC-20260902-023)', () => {
    const items = [
        item(1, 'raw_material'),
        item(2, 'packing_material'),
        item(3, 'other'),
        item(4, null),
        item(5, 'finished_good'),
        item(6, 'raw_material', { is_active: false }),
    ];

    it('offers raw and packing material by default and nothing else', () => {
        expect(purchasePickerItems(items, false).map((i) => i.id)).toEqual([1, 2]);
    });

    it('adds other and unclassified items behind the deliberate choice, flagging the unclassified', () => {
        const shown = purchasePickerItems(items, true);
        expect(shown.map((i) => i.id)).toEqual([1, 2, 3, 4]);
        expect(shown.find((i) => i.id === 4)?.warning).toBe('Unclassified — reason required');
        expect(shown.find((i) => i.id === 3)?.warning).toBeUndefined();
    });

    it('never offers a finished good, whatever the choice', () => {
        expect(purchasePickerItems(items, true).some((i) => i.id === 5)).toBe(false);
    });

    it('never offers an archived item', () => {
        expect(purchasePickerItems(items, true).some((i) => i.id === 6)).toBe(false);
    });

    it('isUnclassified is true only for a null category', () => {
        expect(isUnclassified(item(4, null))).toBe(true);
        expect(isUnclassified(item(3, 'other'))).toBe(false);
    });
});
