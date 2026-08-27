import { z } from 'zod';
import type { UpdateItemPayload } from './api';
import type { ItemRow } from './types';

/**
 * THE ITEM FORM'S SHAPE AND ITS WRITE PATH, apart from the page.
 *
 * The identity fields (display_name, variant_of_item_id, variant_label,
 * category) made the null discipline load-bearing, so the mapping lives here
 * where it can be tested rather than inside a submit handler:
 *
 *  - a CLEARED text field must go to the wire as `null`, not `''` and not
 *    absent — `undefined` is dropped by JSON.stringify, so a link the user
 *    just cleared would never clear;
 *  - `category` is the opposite case. It has a sentinel (`''` — the explicit
 *    "Unclassified" choice) precisely so `undefined` can keep meaning "this
 *    server never served the field", and a save from a screen that could not
 *    see a category can never blank one.
 */

export const itemSchema = z.object({
    sku: z.string().min(1, 'SKU is required').max(64),
    name: z.string().min(1, 'Name is required').max(255),
    display_name: z.string().max(255).optional(),
    uom: z.string().min(1, 'UOM is required').max(16),
    hsn_sac_code: z.string().max(20).optional(),
    reorder_level: z.number().min(0).optional(),
    nominal_weight_grams: z.number().gt(0).optional(),
    tracking_type: z.enum(['none', 'batch', 'serial']).optional(),
    is_production_input: z.boolean().optional(),
    variant_of_item_id: z.number().int().positive().nullable().optional(),
    variant_label: z.string().max(255).optional(),
    /**
     * Not `z.enum` on purpose: a category a newer server knows and this build
     * does not must survive being loaded into the form and saved back, rather
     * than failing validation on a field nobody touched.
     */
    category: z.string().nullable().optional(),
});

export type ItemFormValues = z.infer<typeof itemSchema>;

/** The value the category Select carries for "nobody has said yet". */
export const CATEGORY_UNCLASSIFIED = '';

export const NEW_ITEM_DEFAULTS: ItemFormValues = {
    sku: '',
    name: '',
    uom: 'PCS',
    hsn_sac_code: '',
    reorder_level: 0,
    nominal_weight_grams: undefined,
    tracking_type: 'none',
    is_production_input: false,
};

/** A cleared box is a cleared FIELD, and the server hears `null` for it. */
function blankToNull(value: string | undefined | null): string | null {
    if (value === undefined || value === null) return null;
    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
}

/**
 * The edit form, seeded from the row.
 *
 * `category: undefined` when the row does not carry the key at all — that row
 * came from a server that does not serve categories, and the form must not
 * turn its silence into a value.
 *
 * The row may be the NARROW one the identity read serves, which carries no
 * reorder level and no tracking type. A field the row cannot speak for is left
 * undefined, and `toUpdatePayload` then leaves it off the wire entirely rather
 * than sending a zero the screen never showed anyone.
 */
export function editValuesFromItem(item: ItemRow): ItemFormValues {
    const reorderLevel = item.reorder_level;
    const weight = item.nominal_weight_grams;

    return {
        sku: item.sku,
        name: item.name,
        display_name: item.display_name ?? '',
        uom: item.uom,
        hsn_sac_code: item.hsn_sac_code ?? '',
        reorder_level: reorderLevel === undefined || reorderLevel === null ? undefined : Number(reorderLevel),
        nominal_weight_grams: weight ? Number(weight) : undefined,
        tracking_type: item.tracking_type,
        is_production_input: item.is_production_input,
        variant_of_item_id: item.variant_of_item_id ?? null,
        variant_label: item.variant_label ?? '',
        category: item.category === undefined ? undefined : (item.category ?? CATEGORY_UNCLASSIFIED),
    };
}

/**
 * The PUT body. Everything the form already sent is passed through unchanged —
 * this function exists for the four identity fields and nothing else.
 */
export function toUpdatePayload(values: ItemFormValues): UpdateItemPayload {
    const { display_name, variant_label, variant_of_item_id, category, ...rest } = values;

    return {
        ...rest,
        display_name: blankToNull(display_name),
        variant_label: blankToNull(variant_label),
        // A cleared picker must clear the link, so `null` goes on the wire.
        variant_of_item_id: variant_of_item_id ?? null,
        // Absent, not null, when this build never saw a category for the row.
        ...(category === undefined
            ? {}
            : { category: category === CATEGORY_UNCLASSIFIED ? null : category }),
    };
}
