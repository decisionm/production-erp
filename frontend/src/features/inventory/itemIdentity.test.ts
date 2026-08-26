import { describe, expect, it } from 'vitest';
import {
    badgeLabel,
    CATEGORY_OPTIONS,
    categoryColor,
    categoryLabel,
    confidenceNote,
    itemDisplayName,
    itemGroupName,
    mergeIdentityRow,
    orderedWarningCounts,
    warningColor,
    warningLabel,
    warningTooltip,
} from './itemIdentity';
import type { IdentityItem, IdentityWarningCount, Item } from './types';

/**
 * The identity strip's vocabulary and its two merges, pinned:
 *
 *  - a warning class this build does not know still reaches the screen as
 *    itself, never as a blank or a red guess;
 *  - the strip shows the classes the SERVER sent and does not invent zeros;
 *  - a warning's one sentence names the open question behind it (Q43, Q59,
 *    Q60) so nothing here reads as a rule that has been decided;
 *  - the identity row laid over the list row keeps the lifecycle `can` block,
 *    which is the difference between an Edit button and no row actions at all.
 */

const item = (over: Partial<Item> = {}) =>
    ({ id: 1, sku: 'SKU-1', name: 'Base product', uom: 'Nos', ...over }) as unknown as Item;

describe('warning vocabulary', () => {
    it('names every class this build knows', () => {
        expect(warningLabel('missing_tally_mapping')).toBe('No Tally item');
        expect(warningLabel('unclassified')).toBe('Unclassified');
        expect(warningLabel('outbound_ambiguity')).toBe('Outbound ambiguous');
    });

    it('falls a newer server\'s class through as itself rather than to a blank', () => {
        expect(warningLabel('hsn_missing')).toBe('Hsn missing');
        expect(warningLabel('some_new_class')).toBe('Some new class');
        expect(warningColor('some_new_class')).toBe('default');
        expect(warningTooltip('some_new_class')).toBeNull();
    });

    it('colours the two that stop a voucher posting, and never guesses a colour', () => {
        expect(warningColor('missing_tally_mapping')).toBe('red');
        expect(warningColor('outbound_ambiguity')).toBe('volcano');
    });

    it('names the open owner question behind each warning it explains', () => {
        expect(warningTooltip('duplicate_name')).toContain('Q43');
        expect(warningTooltip('unclassified')).toContain('Q60');
        expect(warningTooltip('fg_purchase_conflict')).toContain('Q59');
        expect(warningTooltip('possible_duplicate_master')).toContain('DEC-20260819-001');
    });
});

describe('badgeLabel', () => {
    it('prefers the server\'s word for a class', () => {
        expect(badgeLabel('outbound_ambiguity', 'Ambiguous to Tally')).toBe('Ambiguous to Tally');
    });

    it('falls back to this build\'s word, then to the key itself', () => {
        expect(badgeLabel('outbound_ambiguity', '')).toBe('Outbound ambiguous');
        expect(badgeLabel('outbound_ambiguity', null)).toBe('Outbound ambiguous');
        expect(badgeLabel('some_new_class', undefined)).toBe('Some new class');
    });
});

describe('orderedWarningCounts', () => {
    const count = (name: string, n: number): IdentityWarningCount =>
        ({ class: name, label: name, count: n });

    it('reads known classes in reading order, whatever order they arrived in', () => {
        expect(orderedWarningCounts([
            count('unclassified', 458),
            count('missing_tally_mapping', 3),
            count('duplicate_name', 2),
        ]).map((entry) => entry.class)).toEqual([
            'missing_tally_mapping',
            'duplicate_name',
            'unclassified',
        ]);
    });

    it('appends a class this build does not know, in the order the server sent it', () => {
        expect(orderedWarningCounts([
            count('zzz_new_class', 1),
            count('unclassified', 2),
            count('aaa_other_new', 4),
        ]).map((entry) => entry.class)).toEqual(['unclassified', 'zzz_new_class', 'aaa_other_new']);
    });

    it('keeps the zeros the server reports, and invents no class it did not', () => {
        expect(orderedWarningCounts([count('unclassified', 0)])).toEqual([count('unclassified', 0)]);
        expect(orderedWarningCounts([])).toEqual([]);
        expect(orderedWarningCounts(undefined)).toEqual([]);
    });
});

describe('confidenceNote', () => {
    it('says a judgement call out loud, and stays quiet otherwise', () => {
        expect(confidenceNote('low')).toContain('Q60');
        expect(confidenceNote('firm')).toBeNull();
        expect(confidenceNote(null)).toBeNull();
        expect(confidenceNote(undefined)).toBeNull();
    });
});

describe('categories', () => {
    it('offers the three added cases alongside the original four', () => {
        expect(CATEGORY_OPTIONS.map((option) => option.value)).toEqual([
            'raw_material',
            'packing_material',
            'finished_good',
            'work_in_progress',
            'consumable',
            'spare_tooling',
            'other',
        ]);
    });

    it('names a category, and passes an unknown one through', () => {
        expect(categoryLabel('spare_tooling')).toBe('Spare / tooling');
        expect(categoryLabel('work_in_progress')).toBe('Work in progress');
        expect(categoryLabel('future_kind')).toBe('Future kind');
        expect(categoryColor('future_kind')).toBe('default');
    });
});

describe('itemDisplayName', () => {
    it('prefers the ERP label and falls back to the Tally name', () => {
        expect(itemDisplayName(item({ display_name: 'Amber 200 ML' }))).toBe('Amber 200 ML');
        expect(itemDisplayName(item({ display_name: null }))).toBe('Base product');
        expect(itemDisplayName(item({ display_name: '   ' }))).toBe('Base product');
        expect(itemDisplayName(item({}))).toBe('Base product');
    });
});

describe('itemGroupName', () => {
    it('reads the group whichever way the endpoint spelled it', () => {
        expect(itemGroupName(item({ item_group: { id: 4, name: 'Packing Material' } }))).toBe('Packing Material');
        expect(itemGroupName(item({ item_group: 'Raw Material' }))).toBe('Raw Material');
        expect(itemGroupName(item({ item_group_name: 'Finished Goods' }))).toBe('Finished Goods');
    });

    it('is null when there is no group to name — never an empty string', () => {
        expect(itemGroupName(item({ item_group: null }))).toBeNull();
        expect(itemGroupName(item({ item_group: '   ' }))).toBeNull();
        expect(itemGroupName(item({ item_group: { name: null } }))).toBeNull();
        expect(itemGroupName(item({}))).toBeNull();
    });
});

describe('mergeIdentityRow', () => {
    const flagged = {
        id: 7,
        sku: 'SKU-7',
        name: 'Tray pack',
        uom: 'Nos',
        warnings: [{ class: 'unclassified', label: 'Unclassified', note: 'No category recorded.' }],
        suggested_category: null,
    } as unknown as IdentityItem;

    it('keeps the lifecycle `can` block the identity read does not carry', () => {
        const known = item({ id: 7, can: { edit: true, activate: false, archive: true, delete: null } });
        const merged = mergeIdentityRow(flagged, known);

        expect(merged.can).toEqual({ edit: true, activate: false, archive: true, delete: null });
        expect(merged.warnings?.map((entry) => entry.class)).toEqual(['unclassified']);
    });

    it('lets the identity read win on what it actually stated', () => {
        const known = item({ id: 7, name: 'Stale name', category: 'other' });
        const merged = mergeIdentityRow({ ...flagged, category: null }, known);

        expect(merged.name).toBe('Tray pack');
        expect(merged.category).toBeNull();
    });

    it('does not let a key the identity read left undefined overwrite a known fact', () => {
        const known = item({ id: 7, is_production_input: true, hsn_sac_code: '3923' });
        const merged = mergeIdentityRow(
            { ...flagged, is_production_input: undefined } as unknown as IdentityItem,
            known,
        );

        expect(merged.is_production_input).toBe(true);
        expect(merged.hsn_sac_code).toBe('3923');
    });

    it('returns a row the list has never seen exactly as it came — fewer actions, never invented ones', () => {
        const merged = mergeIdentityRow(flagged, undefined);

        expect(merged).toBe(flagged);
        expect(merged.can).toBeUndefined();
    });
});
