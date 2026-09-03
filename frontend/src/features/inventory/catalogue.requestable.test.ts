import { describe, expect, it } from 'vitest';
import {
    CATEGORY_FACET_ALL,
    REQUESTABLE_ALL,
    catalogueEmptyText,
    matchesRequestable,
} from '@/features/inventory/catalogue';

/**
 * Q56(a) made answerable. The switch that decides what the Request Material
 * picker offers is on every row; until this filter existed nothing could ask
 * for the rows where it is OFF, which is what made "run through the item
 * master and flip the ones that belong" unperformable on 625 rows.
 */
describe('matchesRequestable', () => {
    it('finds the rows the floor cannot ask for', () => {
        expect(matchesRequestable({ is_production_input: false }, 'not_requestable')).toBe(true);
        expect(matchesRequestable({ is_production_input: true }, 'not_requestable')).toBe(false);
    });

    it('finds the rows the floor can ask for', () => {
        expect(matchesRequestable({ is_production_input: true }, 'requestable')).toBe(true);
        expect(matchesRequestable({ is_production_input: false }, 'requestable')).toBe(false);
    });

    it('keeps everything when the filter is off', () => {
        expect(matchesRequestable({ is_production_input: false }, REQUESTABLE_ALL)).toBe(true);
        expect(matchesRequestable({}, REQUESTABLE_ALL)).toBe(true);
    });

    /**
     * UNKNOWN IS NOT OFF. A payload that does not state the field (the
     * identity read serves a narrower shape) must not land on a worklist that
     * asks somebody to switch on what may already be on.
     */
    it('never puts an unstated row on either worklist', () => {
        expect(matchesRequestable({}, 'not_requestable')).toBe(false);
        expect(matchesRequestable({}, 'requestable')).toBe(false);
    });
});

describe('catalogueEmptyText with the requestable filter', () => {
    it('answers the worklist question instead of saying nothing is here', () => {
        // "No packing material is switched off" is the ANSWER — the job is
        // done — not a dead end the reader has to interpret.
        expect(catalogueEmptyText('packing_material', null, '', 'not_requestable'))
            .toBe('Nothing in packing is switched off — all of it is requestable.');
    });

    it('names the requestable filter when it is the one that emptied the table', () => {
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, null, '', 'requestable'))
            .toContain('switched on as requestable');
    });

    it('still blames the search first, which is the narrowest control', () => {
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, null, 'widget', 'not_requestable'))
            .toBe('Nothing matches "widget".');
    });

    it('is unchanged when the filter is off', () => {
        expect(catalogueEmptyText(CATEGORY_FACET_ALL, null, '')).toBe('The catalogue is empty.');
    });
});
