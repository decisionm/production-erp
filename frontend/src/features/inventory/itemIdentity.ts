import type {
    IdentityItem,
    IdentityWarningClass,
    IdentityWarningCount,
    IdentityWarningKey,
    Item,
    ItemCategory,
    ItemCategoryValue,
    ItemRow,
} from './types';

/**
 * THE VOCABULARY OF THE ITEM IDENTITY HEALTH READ — labels, colours and the
 * one sentence each warning is allowed, plus the two merges the Items table
 * needs. Pure, and here rather than in the page, so there is exactly one copy
 * of it on this side of the wire and it can be tested without a browser.
 *
 * (Not to be confused with `trackingIdentity.ts`, which is about a movement's
 * batch or serial identity. This file is about the ITEM MASTER's identity.)
 *
 * Every string here WARNS. Nothing in this module classifies an item, merges
 * two masters, or decides what a document may carry — Q43 and Q59 are open
 * owner questions and the software does not get to answer them by rendering.
 * What an item IS was answered by DEC-20260827-001, and even that is applied
 * by a person through `inventory:classify-items`, never by this screen.
 */

/**
 * The order the strip reads in — worst-consequence first. `missing_tally_mapping`
 * and `outbound_ambiguity` are the two that stop a voucher posting; the rest
 * are things a person should look at.
 *
 * The counts do NOT partition the catalogue: `duplicate_name` and
 * `outbound_ambiguity` describe overlapping sets by construction (the second
 * is the first, narrowed to sets with a Tally-linked member). So these are
 * independent badges and there is deliberately no total.
 */
export const WARNING_CLASS_ORDER: IdentityWarningClass[] = [
    'missing_tally_mapping',
    'outbound_ambiguity',
    'duplicate_name',
    'possible_duplicate_master',
    'unclassified',
    'variant_uom_conflict',
    'fg_purchase_conflict',
    'inactive_referenced',
];

const WARNING_LABEL: Record<IdentityWarningClass, string> = {
    missing_tally_mapping: 'No Tally item',
    outbound_ambiguity: 'Outbound ambiguous',
    duplicate_name: 'Duplicate name',
    possible_duplicate_master: 'Possible duplicate',
    unclassified: 'Unclassified',
    variant_uom_conflict: 'Variant UOM conflict',
    fg_purchase_conflict: 'FG on a purchase',
    inactive_referenced: 'Inactive, still referenced',
};

const WARNING_COLOR: Record<IdentityWarningClass, string> = {
    missing_tally_mapping: 'red',
    outbound_ambiguity: 'volcano',
    duplicate_name: 'orange',
    possible_duplicate_master: 'gold',
    unclassified: 'blue',
    variant_uom_conflict: 'magenta',
    fg_purchase_conflict: 'purple',
    inactive_referenced: 'geekblue',
};

/**
 * The one line each badge is allowed, and it lives in a TOOLTIP — the page
 * itself carries no prose. Q numbers are cited here because a warning nobody
 * can trace back to an open question reads as a rule that has been decided.
 */
const WARNING_TOOLTIP: Record<IdentityWarningClass, string> = {
    missing_tally_mapping: 'Active item with no Tally stock item — a voucher naming it cannot post.',
    outbound_ambiguity: 'Posting cannot tell which Tally item this line means.',
    duplicate_name: 'Q43 open — duplicate names warn here, they do not block.',
    possible_duplicate_master: 'Names match once case, spacing and punctuation are folded (DEC-20260819-001). A suggestion, never a merge.',
    unclassified: 'No category recorded yet — the group mapping is settled (DEC-20260827-001).',
    variant_uom_conflict: 'Pack variants of one base product carry different units.',
    fg_purchase_conflict: 'Q59 open — a finished good appears on a purchase order line.',
    inactive_referenced: 'Deactivated, but open sales-order lines still name it.',
};

/** `possible_duplicate_master` → `Possible duplicate master`. */
export function humanizeKey(key: string): string {
    const words = key.replace(/[_-]+/g, ' ').trim();
    if (words === '') return key;
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The badge's words WHEN THE SERVER SENT NONE. The server states a label for
 * every class it reports and that one wins — this map is the fallback for a
 * row that carries no label, and an unrecognised class from a newer server
 * falls through as its own humanised key rather than to a blank.
 */
export function warningLabel(key: IdentityWarningKey): string {
    return WARNING_LABEL[key as IdentityWarningClass] ?? humanizeKey(key);
}

/** The server's word for a class, or this build's, or the key itself. Never blank. */
export function badgeLabel(key: IdentityWarningKey, label: string | null | undefined): string {
    return label !== null && label !== undefined && label.trim() !== '' ? label : warningLabel(key);
}

/** The badge's colour; an unknown class gets the neutral one, never a red guess. */
export function warningColor(key: IdentityWarningKey): string {
    return WARNING_COLOR[key as IdentityWarningClass] ?? 'default';
}

/** The badge's tooltip, or `null` for a class this build has no words for. */
export function warningTooltip(key: IdentityWarningKey): string | null {
    return WARNING_TOOLTIP[key as IdentityWarningClass] ?? null;
}

/**
 * The classes to render, in reading order: the ones this build knows first,
 * then anything else the server sent, in the order it sent it.
 *
 * ONLY WHAT THE SERVER ACTUALLY REPORTED. A class it did not mention is not
 * shown as a zero — "the server counted none" and "this build invented a
 * badge" would look identical, and only one of them is a fact. (The server
 * does report all eight, zeros included, so a healthy catalogue reads as
 * eight quiet badges rather than a row that shifts as counts cross zero.)
 */
export function orderedWarningCounts(
    warnings: IdentityWarningCount[] | undefined,
): IdentityWarningCount[] {
    if (!warnings || warnings.length === 0) return [];
    const rank = new Map<string, number>(
        WARNING_CLASS_ORDER.map((key, index) => [key as string, index]),
    );
    return [...warnings].sort((a, b) => {
        const left = rank.get(a.class) ?? Number.MAX_SAFE_INTEGER;
        const right = rank.get(b.class) ?? Number.MAX_SAFE_INTEGER;
        return left - right;
    });
}

// ------------------------------------------------------------ categories --

const CATEGORY_LABEL: Record<ItemCategory, string> = {
    raw_material: 'Raw material',
    packing_material: 'Packing material',
    finished_good: 'Finished good',
    work_in_progress: 'Work in progress',
    consumable: 'Consumable',
    spare_tooling: 'Spare / tooling',
    other: 'Other',
};

const CATEGORY_COLOR: Record<ItemCategory, string> = {
    raw_material: 'green',
    packing_material: 'cyan',
    finished_good: 'blue',
    work_in_progress: 'gold',
    consumable: 'lime',
    spare_tooling: 'purple',
    other: 'default',
};

/**
 * The word for a category. `null` — nobody has said yet — is the caller's to
 * render, not this function's to name: the table shows it as a warning tag and
 * the form shows it as a choice, and neither wants the other's wording.
 */
export function categoryLabel(value: ItemCategoryValue): string {
    return CATEGORY_LABEL[value as ItemCategory] ?? humanizeKey(value);
}

export function categoryColor(value: ItemCategoryValue): string {
    return CATEGORY_COLOR[value as ItemCategory] ?? 'default';
}

/**
 * The category choices, in the order the enum declares them. The three added
 * cases (work_in_progress, consumable, spare_tooling) sit with the rest — an
 * item is one kind of thing and the form does not rank them.
 */
export const CATEGORY_OPTIONS: { value: ItemCategory; label: string }[] = (
    [
        'raw_material',
        'packing_material',
        'finished_good',
        'work_in_progress',
        'consumable',
        'spare_tooling',
        'other',
    ] as ItemCategory[]
).map((value) => ({ value, label: CATEGORY_LABEL[value] }));

// ------------------------------------------------------------- the rows ---

/**
 * Tally's stock group name for an item, whichever way the endpoint spelled it
 * — a nested `{ id, name }`, a bare string, or a flat `item_group_name`.
 * `null` when the item has no group or the field was not served; the caller
 * shows a dash and claims nothing.
 */
export function itemGroupName(item: Pick<Item, 'item_group' | 'item_group_name'>): string | null {
    const group = item.item_group;
    if (typeof group === 'string') return group.trim() === '' ? null : group;
    if (group && typeof group === 'object') {
        return group.name === null || group.name === undefined || group.name.trim() === '' ? null : group.name;
    }
    const flat = item.item_group_name;
    return flat === null || flat === undefined || flat.trim() === '' ? null : flat;
}

/**
 * WHAT TO CALL AN ITEM ON SCREEN — its ERP label if it has been given one,
 * otherwise its Tally name. Never both, and never a blank: `display_name` is
 * an addition to `name`, never a replacement for it, because `name` is what
 * every voucher line carries.
 *
 * NOT `itemLabel` — deliberately, and the name matters. `@/lib/itemLabel`
 * exports an `itemLabel()` that ~20 files already import: it answers a
 * different question ("{sku} — {name}", deduped, a dash for a missing item)
 * and knows nothing about `display_name`. Two same-named helpers with
 * different output and no shared type is a silently-wrong render waiting for
 * whoever reaches for the wrong import, and TypeScript would not say a word.
 * The two compose instead — see ItemIdentityFields, which passes this
 * function's answer INTO the shared one so a picker gets the ERP label with
 * the shared dedupe applied.
 */
export function itemDisplayName(item: Pick<Item, 'name' | 'display_name'>): string {
    const display = item.display_name;
    if (display !== null && display !== undefined && display.trim() !== '') return display;
    return item.name;
}

/**
 * ONE ROW OF THE FILTERED TABLE, out of two reads of the same item.
 *
 * The identity endpoint answers the identity question; the items list carries
 * the Configuration Lifecycle `can` block (DEC-20260817-002), and without it
 * `configurationActions` offers NOTHING — no Edit button on exactly the rows a
 * person opened the filter to fix. So the identity row is laid over the list
 * row, and a key the identity row left `undefined` does not overwrite a fact
 * the list row knows. A row the list has never seen is returned as it came:
 * fewer actions, never invented ones.
 */
export function mergeIdentityRow(row: IdentityItem, known: Item | undefined): ItemRow {
    if (!known) return row;
    const stated = Object.fromEntries(
        Object.entries(row).filter(([, value]) => value !== undefined),
    ) as Partial<IdentityItem>;

    return { ...known, ...stated };
}

/**
 * The filter that means "every item tripping anything", which is what
 * `GET /inventory/identity/items` serves when no class is named. A sentinel
 * rather than a second piece of state, so one value always says what the
 * table is showing; it is never put on the wire — see `listIdentityItems`.
 */
export const ANY_WARNING = 'any_warning';

/** What the table is filtered to: one class, everything flagged, or nothing. */
export type WarningFilter = IdentityWarningKey | typeof ANY_WARNING | null;

/**
 * The word for how far the server stands behind a suggestion. `firm` says
 * nothing out loud — a suggestion is already only a suggestion; `low` has to
 * be visible or a judgement call reads as a finding.
 */
export function confidenceNote(confidence: string | null | undefined): string | null {
    // No group carries `low` since DEC-20260827-001 settled the mapping —
    // masterbatch, the one that did, is firm now. Kept because the SERVER
    // decides the confidence, and a future group the owner marks as a
    // judgement call must still say so rather than render as a bare fact.
    if (confidence === 'low') return 'A judgement call rather than a rule — check it before applying.';
    return null;
}
