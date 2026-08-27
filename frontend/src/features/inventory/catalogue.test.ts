import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    CATEGORY_FACET_ALL,
    catalogueEmptyText,
    CATEGORY_FACET_UNCLASSIFIED,
    categoryFacets,
    matchesCategoryFacet,
    readRememberedFacet,
    rememberFacet,
    skuPresentation,
} from '@/features/inventory/catalogue';
import type { Item, ItemCategoryValue } from '@/features/inventory/types';

const item = (category: ItemCategoryValue | null, over: Partial<Item> = {}) =>
    ({ id: 1, sku: 'SKU-1', name: 'Item', category, ...over }) as Item;

describe('categoryFacets', () => {
    it('counts each category and totals them under All', () => {
        const facets = categoryFacets(
            [item('finished_good'), item('finished_good'), item('packing_material'), item(null)],
            CATEGORY_FACET_ALL,
        );
        const byKey = new Map(facets.map((f) => [f.key, f.count]));

        expect(byKey.get(CATEGORY_FACET_ALL)).toBe(4);
        expect(byKey.get('finished_good')).toBe(2);
        expect(byKey.get('packing_material')).toBe(1);
        expect(byKey.get(CATEGORY_FACET_UNCLASSIFIED)).toBe(1);
    });

    it('hides a category the factory has nothing in', () => {
        // A door into an empty room, offered on every visit, is what makes a
        // filter row noise. Live carries no work_in_progress items at all.
        const keys = categoryFacets([item('finished_good')], CATEGORY_FACET_ALL).map((f) => f.key);

        expect(keys).not.toContain('work_in_progress');
        expect(keys).not.toContain('consumable');
        expect(keys).toContain('finished_good');
    });

    it('keeps the SELECTED category even after its last item leaves it, showing zero', () => {
        // Reclassify the only packing item while standing in Packing: the
        // control must not drop the option under the user's cursor, and it
        // must say plainly that there is now nothing in it.
        const facets = categoryFacets([item('finished_good')], 'packing_material');
        const packing = facets.find((f) => f.key === 'packing_material');

        expect(packing).toBeDefined();
        expect(packing?.count).toBe(0);
    });

    it('always offers All, even for an empty catalogue', () => {
        expect(categoryFacets([], CATEGORY_FACET_ALL).map((f) => f.key)).toEqual([CATEGORY_FACET_ALL]);
    });

    it('orders the facets the way the factory works — what it makes, then what it makes it from', () => {
        const keys = categoryFacets(
            [item('finished_good'), item('raw_material'), item('packing_material'), item('other'), item(null)],
            CATEGORY_FACET_ALL,
        ).map((f) => f.key);

        expect(keys).toEqual([
            CATEGORY_FACET_ALL,
            'finished_good',
            'raw_material',
            'packing_material',
            'other',
            CATEGORY_FACET_UNCLASSIFIED,
        ]);
    });
});

describe('matchesCategoryFacet', () => {
    it('treats a null category as unclassified, not as "other"', () => {
        // The distinction the column exists for: `other` is an answer,
        // null is nobody having said yet.
        expect(matchesCategoryFacet(item(null), CATEGORY_FACET_UNCLASSIFIED)).toBe(true);
        expect(matchesCategoryFacet(item('other'), CATEGORY_FACET_UNCLASSIFIED)).toBe(false);
        expect(matchesCategoryFacet(item(null), 'other')).toBe(false);
    });

    it('does NOT report an unserved field as unclassified', () => {
        // types.ts keeps three states apart: a value, "nobody has said yet"
        // (null), and "the server did not serve the field" (undefined).
        // Collapsing the last two would call a whole catalogue unclassified on
        // a server that simply omits the column.
        expect(matchesCategoryFacet({ category: undefined } as never, CATEGORY_FACET_UNCLASSIFIED)).toBe(false);
    });

    it('lets everything through All', () => {
        expect(matchesCategoryFacet(item(null), CATEGORY_FACET_ALL)).toBe(true);
        expect(matchesCategoryFacet(item('finished_good'), CATEGORY_FACET_ALL)).toBe(true);
    });
});

describe('skuPresentation', () => {
    it('marks a SKU the sync invented from the Tally name', () => {
        const shown = skuPresentation({ sku: '100ML ROUND', sku_provisional: true } as never);

        expect(shown.provisional).toBe(true);
        expect(shown.text).toBe('100ML ROUND');
    });

    it('leaves a SKU a person chose alone', () => {
        expect(skuPresentation({ sku: 'BTL-100-RND-840', sku_provisional: false } as never).provisional)
            .toBe(false);
    });

    it('treats a missing flag as "not provisional" rather than guessing', () => {
        // The server always sends the field, but a screen must not invent a
        // factory fact from its absence.
        expect(skuPresentation({ sku: 'BTL-1' } as never).provisional).toBe(false);
    });

    it('trims, so a padded code does not render as a wider one', () => {
        expect(skuPresentation({ sku: '  BTL-1  ' } as never).text).toBe('BTL-1');
    });
});

describe('catalogueEmptyText', () => {
    it('names the search first, because that is what a person undoes first', () => {
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, null, ' amber ')).toBe('Nothing matches "amber".');
        expect(catalogueEmptyText('packing_material', null, 'amber'))
            .toBe('Nothing matches "amber" in packing.');
    });

    it('puts the search ahead of the warning when both are passed', () => {
        // The precedence the first test looks like it pins but does not: both
        // of its cases pass `warning: null`, so it would pass against an
        // implementation that checked the warning first.
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, 'duplicate_name', 'amber'))
            .toBe('Nothing matches "amber".');
    });

    it('names the warning filter when there is no search', () => {
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, 'duplicate_name', '')).toBe('Nothing flagged.');
        expect(catalogueEmptyText('finished_good', 'duplicate_name', ''))
            .toBe('Nothing flagged in finished goods.');
    });

    it('names the category when it is the only filter on', () => {
        expect(catalogueEmptyText('raw_material', null, '')).toBe('No items in raw material yet.');
    });

    it('says the catalogue itself is empty when nothing is filtering', () => {
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, null, '')).toBe('The catalogue is empty.');
    });
});

describe('remembering the category', () => {
    const KEY = 'erp.inventory.items.facet';

    /** A stand-in for the browser's own store — the suite runs without a DOM by design. */
    const fakeStorage = () => {
        const held = new Map<string, string>();
        return {
            getItem: (key: string) => held.get(key) ?? null,
            setItem: (key: string, value: string) => void held.set(key, value),
            removeItem: (key: string) => void held.delete(key),
            held,
        };
    };

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('opens where the last person left it', () => {
        vi.stubGlobal('localStorage', fakeStorage());

        rememberFacet('packing_material');

        expect(readRememberedFacet()).toBe('packing_material');
    });

    it('opens on All when nothing has been chosen', () => {
        vi.stubGlobal('localStorage', fakeStorage());

        expect(readRememberedFacet()).toBe(CATEGORY_FACET_ALL);
    });

    it('forgets rather than stores when All is chosen', () => {
        const storage = fakeStorage();
        vi.stubGlobal('localStorage', storage);

        rememberFacet('raw_material');
        rememberFacet(CATEGORY_FACET_ALL);

        // A browser that never chose and one that chose All must behave alike.
        expect(storage.held.has(KEY)).toBe(false);
        expect(readRememberedFacet()).toBe(CATEGORY_FACET_ALL);
    });

    it('ignores a value it does not recognise rather than filtering the catalogue away', () => {
        const storage = fakeStorage();
        // A key left by an older build, or a hand-edited one. Honouring it
        // would show an empty table and read as an empty factory.
        storage.held.set(KEY, 'obsolete_category');
        vi.stubGlobal('localStorage', storage);

        expect(readRememberedFacet()).toBe(CATEGORY_FACET_ALL);
    });

    it('survives a browser that refuses storage entirely', () => {
        // Private windows and blocked site data THROW on access rather than
        // returning null; an exception would take the item master down.
        vi.stubGlobal('localStorage', {
            getItem: () => {
                throw new Error('SecurityError');
            },
            setItem: () => {
                throw new Error('SecurityError');
            },
            removeItem: () => {
                throw new Error('SecurityError');
            },
        });

        expect(readRememberedFacet()).toBe(CATEGORY_FACET_ALL);
        expect(() => rememberFacet('packing_material')).not.toThrow();
    });

    it('survives a browser with no storage at all', () => {
        vi.stubGlobal('localStorage', undefined);

        expect(readRememberedFacet()).toBe(CATEGORY_FACET_ALL);
        expect(() => rememberFacet('packing_material')).not.toThrow();
    });
});
