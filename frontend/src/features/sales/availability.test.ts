import { describe, expect, it } from 'vitest';
import { availabilityByItem, availabilityChips, availabilityItemIds } from './availability';
import type { ItemAvailability } from './types';

/**
 * THE PER-LINE AVAILABILITY CHIPS on the New Sales Order modal.
 *
 * The claim worth pinning is the one about SILENCE: until the read answers for
 * an item there are NO chips, because "0 free" printed while a request is in
 * flight would talk a desk out of an order the factory can fill. Unknown is
 * never zero — the same rule `formatQuantity` was written for.
 *
 * The other is `over_reserved`. Free is clamped at zero on the server (S8), so
 * without that chip a desk sees a full shelf promising nothing and has no way
 * to find out why.
 */

function availability(overrides: Partial<ItemAvailability> = {}): ItemAvailability {
    return {
        item_id: 7,
        on_hand: '400.0000',
        reserved: '150.0000',
        free: '250.0000',
        over_reserved: '0.0000',
        ...overrides,
    };
}

const labels = (chips: { label: string }[]) => chips.map((chip) => chip.label);

describe('the availability chips', () => {
    it('show nothing at all until the read has answered for this item', () => {
        expect(availabilityChips(undefined, 100)).toEqual([]);
    });

    it('show free and held, trimmed out of the 4dp decimal strings', () => {
        expect(labels(availabilityChips(availability(), null))).toEqual(['250 free', '150 held']);
    });

    it('omit the held chip when nothing is held, rather than printing a zero', () => {
        expect(labels(availabilityChips(availability({ reserved: '0.0000' }), null))).toEqual(['250 free']);
    });

    it('still print a free chip when the shelf is empty — that is a figure, not an absence', () => {
        const chips = availabilityChips(availability({ free: '0.0000', reserved: '0.0000' }), null);

        expect(labels(chips)).toEqual(['0 free']);
        expect(chips[0].tone).toBe('neutral');
    });

    it('add the short chip only once a quantity beyond free stock is typed', () => {
        expect(labels(availabilityChips(availability(), null))).not.toContain('short 750');
        expect(labels(availabilityChips(availability(), 100))).toEqual(['250 free', '150 held']);
        expect(labels(availabilityChips(availability(), 1000))).toContain('short 750');
    });

    it('print an over-promise rather than leaving a full shelf promising nothing unexplained', () => {
        const chips = availabilityChips(
            availability({ on_hand: '100.0000', reserved: '400.0000', free: '0.0000', over_reserved: '300.0000' }),
            null,
        );

        expect(labels(chips)).toEqual(['0 free', '400 held', '300 promised twice']);
        expect(chips[2].tone).toBe('warning');
    });
});

describe('the one availability request the modal makes', () => {
    it('asks for each item once, sorted, so re-ordering two lines is not a new question', () => {
        expect(
            availabilityItemIds([{ item_id: 9 }, { item_id: 7 }, { item_id: 9 }, { item_id: undefined }, {}]),
        ).toEqual([7, 9]);
    });

    it('asks for nothing when no line names a product yet', () => {
        expect(availabilityItemIds([{}, { item_id: null }])).toEqual([]);
    });

    it('keys the answers by item so a line finds its own figures', () => {
        const map = availabilityByItem([availability(), availability({ item_id: 9, free: '10.0000' })]);

        expect(map.get(9)?.free).toBe('10.0000');
        expect(map.get(11)).toBeUndefined();
        expect(availabilityByItem(undefined).size).toBe(0);
    });
});
