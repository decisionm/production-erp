import type { Item, ItemCategoryValue, ItemRow } from '@/features/inventory/types';

/**
 * THE CATALOGUE, AS A STOREKEEPER READS IT.
 *
 * Most of the names differ by a colour word and a gram figure: "B.100 Ml
 * Round Pet Bottle Amber 12.9 Gms - 812 Nos" against the same line ending
 * "- 840 Nos". Reading a Category tag on every row to find the packing
 * material is work; picking the category and seeing only those is not. So the
 * category stops being a column to scan and becomes the way in, with its count
 * on the face of it — the count is what tells somebody there is nothing to
 * look for before they look.
 *
 * UNCLASSIFIED IS A FACET, NOT AN ABSENCE. Most of this catalogue has no
 * category recorded (no figure is quoted here on purpose — the live one moves,
 * and `items:summary` is where it is counted), and it is the single largest
 * thing a person can fix on this screen. So it is offered as a place to go
 * rather than left as the residue of filtering everything else away.
 */

export const CATEGORY_FACET_ALL = 'all';
export const CATEGORY_FACET_UNCLASSIFIED = 'unclassified';

/**
 * EVERYTHING THE STORE HOLDS THAT IS NOT A FINISHED GOOD — the store's own
 * view of the catalogue, and the DEFAULT one.
 *
 * The owner asked for finished goods to come off the Inventory item-master
 * surface. Taken literally that could mean archiving them, refusing them on
 * documents, or dropping them from the API, and every one of those destroys
 * something: a finished good is a real master with real stock, real history
 * and a live Tally identity, it is what Sales sells and what a batch produces,
 * and which categories each document may use is still an OPEN question (Q59,
 * left open by DEC-20260827-002). So none of that is done here.
 *
 * WHAT IS DONE IS THE SAFE HALF OF IT, and the boundary is worth stating
 * because the next reader will be tempted to go further: the item master
 * OPENS on materials instead of on everything, and "Finished goods" is still
 * a facet one click away with its count on it. Nothing is archived, nothing
 * is deleted, no API changes, search still reaches every item, item detail
 * still opens, and the Stock, movement, sales and production screens are
 * untouched. A person who wants finished goods clicks the word.
 *
 * If the owner meant something stronger, that is a decision to record and
 * then build — not one to reach by widening this filter.
 */
export const CATEGORY_FACET_MATERIALS = 'materials';

export type CategoryFacetKey =
    | typeof CATEGORY_FACET_ALL
    | typeof CATEGORY_FACET_MATERIALS
    | typeof CATEGORY_FACET_UNCLASSIFIED
    | ItemCategoryValue;

export interface CategoryFacet {
    key: CategoryFacetKey;
    label: string;
    count: number;
}

/** The order the factory works in: what it makes, then what it makes it from. */
const FACET_ORDER: { key: CategoryFacetKey; label: string }[] = [
    // Materials first: it is where the screen opens, so it is where a reader's
    // eye should already be when they arrive.
    { key: CATEGORY_FACET_MATERIALS, label: 'Materials' },
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
    // NOT-A-FINISHED-GOOD, which deliberately KEEPS the unclassified items
    // (category null) and the ones a server did not serve a category for
    // (undefined). Reading either as a finished good would hide, behind a
    // filter nobody chose, the exact rows DEC-20260827-002 says are "not
    // recorded yet" and are the largest thing a person can fix here.
    if (facet === CATEGORY_FACET_MATERIALS) return item.category !== 'finished_good';
    // `null` is "nobody has said yet"; `undefined` is a server that did not
    // serve the field at all. types.ts states that three-state rule and the
    // Category column already honours it, so the facet must not collapse the
    // two and report a whole unserved catalogue as unclassified.
    if (facet === CATEGORY_FACET_UNCLASSIFIED) return item.category === null;
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
 */
export interface SkuPresentation {
    text: string;
    provisional: boolean;
}

export function skuPresentation(item: Pick<ItemRow, 'sku' | 'sku_provisional'>): SkuPresentation {
    return {
        text: (item.sku ?? '').trim(),
        provisional: item.sku_provisional === true,
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
    requestable: RequestableFilterKey = REQUESTABLE_ALL,
): string {
    const inCategory = facet === CATEGORY_FACET_ALL
        ? ''
        : ` in ${(FACET_ORDER.find((f) => f.key === facet)?.label ?? facet).toLowerCase()}`;

    if (search.trim() !== '') return `Nothing matches "${search.trim()}"${inCategory}.`;
    if (warning !== null) return `Nothing flagged${inCategory}.`;

    // NARROWEST FILTER FIRST, the rule this function already followed for
    // three controls and now follows for four: an empty table must name the
    // one that emptied it. "No packing material is switched off" is the
    // ANSWER to the worklist question, not a dead end, so it has to be said
    // rather than left as a bare "no items".
    if (requestable === 'not_requestable') return `Nothing${inCategory} is switched off — all of it is requestable.`;
    if (requestable === 'requestable') return `Nothing${inCategory} is switched on as requestable yet.`;

    if (inCategory !== '') return `No items${inCategory} yet.`;

    return 'The catalogue is empty.';
}
/* ----------------------------- requestable ------------------------------ */

/**
 * WHICH ITEMS THE FLOOR MAY ASK THE STORE FOR — the switch, made filterable.
 *
 * `items.is_production_input` decides what the Request Material picker offers
 * (MaterialRequestService::requestableMaterials). The backfill that set it
 * derived it from EVIDENCE — BOM lines, the packing register, the colourant
 * register, and what the factory had actually requested and issued — and left
 * anything it could not prove OFF rather than guessing. Q56(a) named the
 * consequence in advance: "a material the store needs to hand over that the
 * floor cannot ask for", to be fixed by somebody running the item master once
 * and flipping the ones that belong.
 *
 * That reading was impossible on a 625-row catalogue: the column is on every
 * row, but nothing let a person ask for the rows where it is OFF. This filter
 * is that question, and it composes with the category facet, so "packing
 * material the floor cannot ask for" — the exact worklist Q56 asks for — is
 * two clicks.
 */
export const REQUESTABLE_ALL = 'all';

export type RequestableFilterKey = typeof REQUESTABLE_ALL | 'requestable' | 'not_requestable';

export const REQUESTABLE_FILTERS: readonly { key: RequestableFilterKey; label: string }[] = [
    { key: REQUESTABLE_ALL, label: 'Any' },
    { key: 'not_requestable', label: 'Not requestable' },
    { key: 'requestable', label: 'Requestable' },
];

/**
 * A row missing the field entirely is NOT counted as "not requestable".
 * `undefined` means this payload did not state it — the identity read serves a
 * narrower shape — and treating "unknown" as "off" would put rows on a
 * worklist that asks somebody to switch on what may already be on.
 */
export function matchesRequestable(
    item: { is_production_input?: boolean },
    filter: RequestableFilterKey,
): boolean {
    if (filter === REQUESTABLE_ALL) return true;
    if (item.is_production_input === undefined) return false;

    return filter === 'requestable' ? item.is_production_input : !item.is_production_input;
}


/**
 * THE CATEGORY THE LAST PERSON PICKED, remembered per browser.
 *
 * A storekeeper opens this screen to find packing material and lands in a
 * catalogue where most rows are not what they came for. Remembering the choice
 * lets the store's own machine open where the store works, without anyone being
 * given a setting to configure. (What the catalogue actually holds is counted
 * by `php artisan items:summary` — a figure written into a comment here would
 * be a live number frozen at the moment someone typed it.)
 *
 * PER BROWSER, DELIBERATELY, not per login: what is being remembered is where
 * THIS machine is used — the store's PC opens on packing, the sales desk's on
 * finished goods — and two people sharing a shift also share a screen. It is a
 * convenience and never a permission: every category stays one click away and
 * the count of each one is on the face of the row.
 *
 * A REMEMBERED FILTER MUST NEVER READ AS THE WHOLE CATALOGUE. That is the real
 * risk here — somebody returns to a narrowed list they did not narrow, and
 * takes it for everything the factory has. Two things prevent it: the chosen
 * facet is marked with "All" beside it carrying the full count, and anything
 * unreadable or unrecognised falls back to All rather than to a filter nobody
 * chose.
 */
const REMEMBERED_FACET_KEY = 'erp.inventory.items.facet';

/** Every key the row can offer, so a stale or hand-edited value cannot filter the catalogue away. */
function isKnownFacet(value: string): value is CategoryFacetKey {
    return FACET_ORDER.some((facet) => facet.key === value);
}

/**
 * Storage is reached through `globalThis` rather than `window`, and read
 * inside the callers' own try/catch — THE CALL SITES BELOW ARE WHAT MAKE THIS
 * SAFE, not this function. It matters because storage does not merely return
 * null where it is unavailable: in a private window and wherever site data is
 * blocked, the PROPERTY ACCESS itself throws, before any method is reached.
 * Keep every call to this inside a try, or the item master goes down for a
 * preference nobody asked for. Absent storage is simply a browser that will
 * not remember, which is not an error worth showing anyone.
 */
function facetMemory(): Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null;
    }
}

/**
 * WHAT THE ITEM MASTER OPENS ON when nobody has chosen — Materials, not All.
 *
 * This USED TO BE `all`, and the swap is the whole of the finished-goods
 * change on this screen (see CATEGORY_FACET_MATERIALS for the boundary and
 * for what was deliberately not done). It moves no data and removes no facet:
 * "All" and "Finished goods" are both still one click away, with their counts.
 */
const DEFAULT_FACET: CategoryFacetKey = CATEGORY_FACET_MATERIALS;

export function readRememberedFacet(): CategoryFacetKey {
    try {
        const stored = facetMemory()?.getItem(REMEMBERED_FACET_KEY);
        return stored != null && isKnownFacet(stored) ? stored : DEFAULT_FACET;
    } catch {
        return DEFAULT_FACET;
    }
}

export function rememberFacet(facet: CategoryFacetKey): void {
    try {
        // THE SENTINEL IS THE DEFAULT, WHICHEVER FACET THAT IS — it was "All"
        // only because "All" was the default. Now that Materials is, choosing
        // "All" has to be STORED like any other deliberate choice: leave it as
        // the removed-key case and a storekeeper who picked All would find
        // themselves back on Materials next time, which is the screen quietly
        // overruling them.
        if (facet === DEFAULT_FACET) {
            facetMemory()?.removeItem(REMEMBERED_FACET_KEY);
            return;
        }
        facetMemory()?.setItem(REMEMBERED_FACET_KEY, facet);
    } catch {
        // Storage that refuses to be written (private window, quota) leaves the
        // screen working exactly as before; it simply opens on the default
        // next time.
    }
}
