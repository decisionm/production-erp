import { describe, expect, it } from 'vitest';
import {
    CATEGORY_FACET_ALL,
    catalogueEmptyText,
    CATEGORY_FACET_UNCLASSIFIED,
    categoryFacets,
    matchesCategoryFacet,
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

    it('keeps the SELECTED category even after its last item leaves it', () => {
        // Reclassify the only packing item while standing in Packing: the
        // control must not drop the option under the user's cursor.
        const keys = categoryFacets([item('finished_good')], 'packing_material').map((f) => f.key);

        expect(keys).toContain('packing_material');
        expect(new Map(keys.map((k, i) => [k, i])).get('packing_material')).toBeDefined();
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
    it('treats an absent category as unclassified, not as "other"', () => {
        // The distinction the column exists for: `other` is an answer,
        // null is nobody having said yet.
        expect(matchesCategoryFacet(item(null), CATEGORY_FACET_UNCLASSIFIED)).toBe(true);
        expect(matchesCategoryFacet(item('other'), CATEGORY_FACET_UNCLASSIFIED)).toBe(false);
        expect(matchesCategoryFacet(item(null), 'other')).toBe(false);
    });

    it('lets everything through All', () => {
        expect(matchesCategoryFacet(item(null), CATEGORY_FACET_ALL)).toBe(true);
        expect(matchesCategoryFacet(item('finished_good'), CATEGORY_FACET_ALL)).toBe(true);
    });
});

describe('skuPresentation', () => {
    it('marks a SKU the sync invented from the Tally name', () => {
        const shown = skuPresentation({
            sku: '100ML ROUND',
            name: '100ML ROUND',
            sku_provisional: true,
        } as never);

        expect(shown.provisional).toBe(true);
        expect(shown.redundant).toBe(true);
        expect(shown.text).toBe('100ML ROUND');
    });

    it('leaves a SKU a person chose alone', () => {
        const shown = skuPresentation({
            sku: 'BTL-100-RND-840',
            name: '100ML ROUND - 840 Nos',
            sku_provisional: false,
        } as never);

        expect(shown.provisional).toBe(false);
        expect(shown.redundant).toBe(false);
    });

    it('reads redundancy through case and spacing, the way this catalogue drifts', () => {
        // '100ml' and '100 Ml' are the same thing in these books.
        expect(skuPresentation({ sku: '100ml round', name: '100 Ml Round' } as never).redundant).toBe(true);
    });

    it('says nothing is redundant when either side is blank', () => {
        expect(skuPresentation({ sku: '', name: 'Bottle' } as never).redundant).toBe(false);
        expect(skuPresentation({ sku: 'BTL-1', name: '' } as never).redundant).toBe(false);
    });

    it('treats a missing flag as "not provisional" rather than guessing', () => {
        expect(skuPresentation({ sku: 'BTL-1', name: 'Bottle' } as never).provisional).toBe(false);
    });
});

describe('catalogueEmptyText', () => {
    it('names the search first, because that is what a person undoes first', () => {
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, null, ' amber ')).toBe('Nothing matches "amber".');
        expect(catalogueEmptyText('packing_material', null, 'amber'))
            .toBe('Nothing matches "amber" in packing.');
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
