import type { Item, ItemCategoryValue, ItemRow } from '@/features/inventory/types';

/**
 * THE CATALOGUE, AS A STOREKEEPER READS IT.
 *
 * 624 items, and most of the names differ by a colour word and a gram figure:
 * "B.100 Ml Round Pet Bottle Amber 12.9 Gms - 812 Nos" against the same line
 * ending "- 840 Nos". Reading a Category tag on every row to find the packing
 * material is work; picking the category and seeing only those is not. So the
 * category stops being a column to scan and becomes the way in, with its count
 * on the face of it — the count is what tells somebody there is nothing to
 * look for before they look.
 *
 * UNCLASSIFIED IS A FACET, NOT AN ABSENCE. 556 of the catalogue has no
 * category recorded, and that is the single largest thing a person can fix
 * here, so it is offered as a place to go rather than left as the residue of
 * filtering everything else out.
 */

export const CATEGORY_FACET_ALL = 'all';
export const CATEGORY_FACET_UNCLASSIFIED = 'unclassified';

export type CategoryFacetKey = typeof CATEGORY_FACET_ALL | typeof CATEGORY_FACET_UNCLASSIFIED | ItemCategoryValue;

export interface CategoryFacet {
    key: CategoryFacetKey;
    label: string;
    count: number;
}

/** The order the factory works in: what it makes, then what it makes it from. */
const FACET_ORDER: { key: CategoryFacetKey; label: string }[] = [
    { key: CATEGORY_FACET_ALL, label: 'All' },
    { key: 'finished_good', label: 'Finished goods' },
    { key: 'raw_material', label: 'Raw material' },
    { key: 'packing_material', label: 'Packing' },
    { key: 'work_in_progress', label: 'Work in progress' },
    { key: 'consumable', label: 'Consumable' },
    { key: 'spare_tooling', label: 'Spare / tooling' },
    { key: 'other', label: 'Other' },
    { key: CATEGORY_FACET_UNCLASSIFIED, label: 'Unclassified' },
];

export function matchesCategoryFacet(item: Pick<Item, 'category'>, facet: CategoryFacetKey): boolean {
    if (facet === CATEGORY_FACET_ALL) return true;
    if (facet === CATEGORY_FACET_UNCLASSIFIED) return item.category === null || item.category === undefined;
    return item.category === facet;
}

/**
 * Every facet that has something in it, plus All, plus whichever facet is
 * currently selected.
 *
 * An empty facet is HIDDEN rather than shown as a zero: a factory with no
 * work-in-progress items should not be offered a door into an empty room every
 * time it opens the catalogue. The selected one survives that rule so the
 * control never drops the option under the user's cursor when the last item in
 * it is reclassified.
 */
export function categoryFacets(items: Pick<Item, 'category'>[], selected: CategoryFacetKey): CategoryFacet[] {
    return FACET_ORDER
        .map((facet) => ({
            ...facet,
            count: facet.key === CATEGORY_FACET_ALL
                ? items.length
                : items.filter((item) => matchesCategoryFacet(item, facet.key)).length,
        }))
        .filter((facet) => facet.count > 0 || facet.key === CATEGORY_FACET_ALL || facet.key === selected);
}

/**
 * WHAT THE SKU COLUMN SHOULD SAY, which is not always the SKU.
 *
 * Q42 settled what a SKU is FOR — internal mapping and easier lookup, not a
 * barcode and not a Tally key. What it did not do is make every row's SKU a
 * chosen one: the masters pull seeds a SKU from the Tally NAME for anything it
 * creates and marks the row `sku_provisional`, so most of this catalogue's
 * "SKUs" are the product name again, in a column headed as though somebody
 * decided it.
 *
 * Two honest states, then:
 *   * CHOSEN — a person set it. Shown plainly, and it is worth copying.
 *   * SEEDED — the pull invented it from the name. Shown quietly and marked,
 *     because a placeholder that looks like a decision is how a placeholder
 *     survives for a year.
 *
 * `redundant` says the string merely repeats the name once case and spacing
 * are folded away — this catalogue's normal case, and the reason the shared
 * item label drops it. The column keeps showing it (somebody types it into the
 * delivery scanner) but it need not shout.
 */
export interface SkuPresentation {
    text: string;
    provisional: boolean;
    redundant: boolean;
}

export function skuPresentation(item: Pick<ItemRow, 'sku' | 'name' | 'sku_provisional'>): SkuPresentation {
    const sku = (item.sku ?? '').trim();
    const name = (item.name ?? '').trim();
    const bare = (value: string) => value.toLowerCase().replace(/\s+/g, '');

    return {
        text: sku,
        provisional: item.sku_provisional === true,
        redundant: sku !== '' && name !== '' && bare(sku) === bare(name),
    };
}

/**
 * WHICH FILTER EMPTIED THE TABLE.
 *
 * Two filters stack on this screen — a category and an identity warning — and
 * a search box sits above both. "No data" leaves a person clearing all three
 * to find out which one was holding everything back, which is the moment a
 * screen stops being trusted. So the empty state names the narrowest thing
 * that is currently on, in the order a person would undo them.
 */
export function catalogueEmptyText(
    facet: CategoryFacetKey,
    warning: string | null,
    search: string,
): string {
    const inCategory = facet === CATEGORY_FACET_ALL
        ? ''
        : ` in ${(FACET_ORDER.find((f) => f.key === facet)?.label ?? facet).toLowerCase()}`;

    if (search.trim() !== '') return `Nothing matches "${search.trim()}"${inCategory}.`;
    if (warning !== null) return `Nothing flagged${inCategory}.`;
    if (inCategory !== '') return `No items${inCategory} yet.`;

    return 'The catalogue is empty.';
}
