import type { Item } from '@/features/inventory/types';

/**
 * DEC-20260902-023. The picker offers Raw Material and Packing Material by
 * default. Behind "Show additional purchasable items" it also offers Other
 * (consumables, spares, tooling) and unclassified items, the latter flagged
 * because the document will demand a reason. A finished good never appears.
 * The category is `items.category` (DEC-20260827-001); nothing is inferred
 * from a name. The server enforces the same rule (StorePurchaseRequisitionRequest,
 * StorePurchaseOrderRequest); this is the courtesy half.
 */
export interface PurchasePickerItem {
    id: number;
    item: Item;
    warning?: string;
}

export const DEFAULT_PURCHASE_CATEGORIES = ['raw_material', 'packing_material'] as const;

/**
 * The one wording for "this line needs a reason" — spelled once so a
 * caller that re-derives the flag from `isUnclassified` on its own (a kept
 * item a category/showAdditional filter would otherwise have dropped, for
 * instance) cannot drift from what this file prints for an offered one.
 */
export const UNCLASSIFIED_WARNING = 'Unclassified — reason required';

export function isUnclassified(item: Pick<Item, 'category'>): boolean {
    return item.category === null || item.category === undefined;
}

export function purchasePickerItems(items: readonly Item[] | undefined | null, showAdditional: boolean): PurchasePickerItem[] {
    const out: PurchasePickerItem[] = [];
    for (const item of items ?? []) {
        if (!item.is_active) continue;
        if (item.category === 'finished_good') continue;
        const isDefault = (DEFAULT_PURCHASE_CATEGORIES as readonly string[]).includes(item.category ?? '');
        if (!isDefault && !showAdditional) continue;
        out.push(isUnclassified(item) ? { id: item.id, item, warning: UNCLASSIFIED_WARNING } : { id: item.id, item });
    }
    return out;
}
