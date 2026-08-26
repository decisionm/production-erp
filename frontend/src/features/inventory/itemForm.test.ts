import { describe, expect, it } from 'vitest';
import { CATEGORY_UNCLASSIFIED, editValuesFromItem, type ItemFormValues, toUpdatePayload } from './itemForm';
import type { Item } from './types';

/**
 * The write path's null discipline, pinned — this is where a save can lie.
 *
 *  - a cleared field must reach the server as `null`; `undefined` is dropped by
 *    JSON.stringify, so a link the user just cleared would silently stay;
 *  - `category` is the reverse: `undefined` means this build never saw one for
 *    the row, and a save from that screen must not blank a category it could
 *    not display. The explicit "Unclassified" choice (`''`) is what clears it;
 *  - every field that was already on this form goes to the wire exactly as it
 *    did before — the identity fields are an addition, not a rewrite.
 */

const item = (over: Partial<Item> = {}) =>
    ({
        id: 1,
        sku: 'SKU-1',
        name: 'Base product',
        uom: 'Nos',
        hsn_sac_code: null,
        reorder_level: '0.0000',
        nominal_weight_grams: null,
        tracking_type: 'none',
        is_production_input: false,
        ...over,
    }) as unknown as Item;

const values = (over: Partial<ItemFormValues> = {}): ItemFormValues => ({
    sku: 'SKU-1',
    name: 'Base product',
    uom: 'Nos',
    hsn_sac_code: '',
    reorder_level: 0,
    tracking_type: 'none',
    is_production_input: false,
    ...over,
});

describe('editValuesFromItem', () => {
    it('seeds the identity fields, blank rather than undefined for the text boxes', () => {
        const seeded = editValuesFromItem(item({
            display_name: 'Amber 200 ML',
            variant_of_item_id: 4,
            variant_label: '840/box pouch',
            category: 'finished_good',
        }));

        expect(seeded.display_name).toBe('Amber 200 ML');
        expect(seeded.variant_of_item_id).toBe(4);
        expect(seeded.variant_label).toBe('840/box pouch');
        expect(seeded.category).toBe('finished_good');
    });

    it('turns a served-but-null category into the explicit Unclassified choice', () => {
        expect(editValuesFromItem(item({ category: null })).category).toBe(CATEGORY_UNCLASSIFIED);
    });

    it('leaves category undefined when the server never served the field', () => {
        expect(editValuesFromItem(item({})).category).toBeUndefined();
    });

    it('reads the decimal strings the API sends as numbers the form can hold', () => {
        const seeded = editValuesFromItem(item({ reorder_level: '25.0000', nominal_weight_grams: '10.5000' }));

        expect(seeded.reorder_level).toBe(25);
        expect(seeded.nominal_weight_grams).toBe(10.5);
        expect(editValuesFromItem(item({})).nominal_weight_grams).toBeUndefined();
    });
});

describe('toUpdatePayload', () => {
    it('sends a cleared text field as null, not as an empty string', () => {
        const payload = toUpdatePayload(values({ display_name: '   ', variant_label: '' }));

        expect(payload.display_name).toBeNull();
        expect(payload.variant_label).toBeNull();
    });

    it('trims what was typed', () => {
        const payload = toUpdatePayload(values({ display_name: '  Amber 200 ML  ' }));

        expect(payload.display_name).toBe('Amber 200 ML');
    });

    it('sends a cleared variant picker as null so the link actually clears', () => {
        expect(toUpdatePayload(values({ variant_of_item_id: undefined })).variant_of_item_id).toBeNull();
        expect(toUpdatePayload(values({ variant_of_item_id: null })).variant_of_item_id).toBeNull();
        expect(toUpdatePayload(values({ variant_of_item_id: 4 })).variant_of_item_id).toBe(4);
    });

    it('clears a category only on the explicit Unclassified choice', () => {
        expect(toUpdatePayload(values({ category: CATEGORY_UNCLASSIFIED })).category).toBeNull();
        expect(toUpdatePayload(values({ category: 'raw_material' })).category).toBe('raw_material');
    });

    it('omits category entirely when this build never saw one for the row', () => {
        const payload = toUpdatePayload(values({ category: undefined }));

        expect('category' in payload).toBe(false);
    });

    it('passes the fields that were already on this form through untouched', () => {
        const payload = toUpdatePayload(values({
            sku: 'SKU-9',
            name: 'Tray pack',
            uom: 'Kgs',
            hsn_sac_code: '',
            reorder_level: 25,
            nominal_weight_grams: 10.5,
            tracking_type: 'batch',
            is_production_input: true,
        }));

        expect(payload).toMatchObject({
            sku: 'SKU-9',
            name: 'Tray pack',
            uom: 'Kgs',
            hsn_sac_code: '',
            reorder_level: 25,
            nominal_weight_grams: 10.5,
            tracking_type: 'batch',
            is_production_input: true,
        });
    });
});
