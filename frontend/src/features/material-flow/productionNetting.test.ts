import { describe, expect, it } from 'vitest';
import { netAgainstProduction } from './productionNetting';
import type { MaterialFlowMaterial } from './types';

/**
 * The three figures DEC-20260831-001 requires on the request screen, and the
 * two ways the arithmetic could quietly ask the store for the wrong number:
 * netting the same standing quantity twice, and netting something that is not
 * usable.
 */
const material = (id: number, available: string, matches = true): MaterialFlowMaterial =>
    ({
        id,
        sku: `SKU-${id}`,
        name: `Material ${id}`,
        uom: 'Kgs.',
        machine_applies: false,
        available_in_production: available,
        production_unit_matches: matches,
    }) as MaterialFlowMaterial;

const by = (...items: MaterialFlowMaterial[]) => new Map(items.map((item) => [item.id, item]));

describe('netAgainstProduction', () => {
    it('asks the store for the balance, not the total', () => {
        const [line] = netAgainstProduction([{ item_id: 1, quantity: 1000 }], by(material(1, '300.0000')));

        expect(line).toEqual({ required: 1000, available: 300, ask: 700, unitMismatch: false });
    });

    it('asks for nothing when the floor already covers the need', () => {
        const [line] = netAgainstProduction([{ item_id: 1, quantity: 1000 }], by(material(1, '1200.0000')));

        // Floored at zero — a negative balance to request is not a number a
        // storekeeper can act on. And `available` is what was actually netted,
        // not the whole 1200 standing there.
        expect(line.ask).toBe(0);
        expect(line.available).toBe(1000);
    });

    it('does not let two lines of one material net the same quantity twice', () => {
        const lines = netAgainstProduction(
            [
                { item_id: 1, quantity: 400 },
                { item_id: 1, quantity: 400 },
            ],
            by(material(1, '300.0000')),
        );

        // 300 kg cannot answer both lines. The first takes it; the second has
        // nothing left and asks for all 400.
        expect(lines[0]).toMatchObject({ available: 300, ask: 100 });
        expect(lines[1]).toMatchObject({ available: 0, ask: 400 });
    });

    it('spends the floor down across three lines and then stops', () => {
        const lines = netAgainstProduction(
            [
                { item_id: 1, quantity: 100 },
                { item_id: 1, quantity: 100 },
                { item_id: 1, quantity: 100 },
            ],
            by(material(1, '150.0000')),
        );

        expect(lines.map((line) => line.available)).toEqual([100, 50, 0]);
        expect(lines.map((line) => line.ask)).toEqual([0, 50, 100]);
    });

    it('nets nothing when the handover unit disagrees with the master', () => {
        // FC-03: 300 of a different thing may not be subtracted from 1000 of
        // this one. The server already reports the usable figure as zero; the
        // flag is what lets the screen say the quantity is nonetheless there.
        const [line] = netAgainstProduction(
            [{ item_id: 1, quantity: 1000 }],
            by(material(1, '0.0000', false)),
        );

        expect(line).toEqual({ required: 1000, available: 0, ask: 1000, unitMismatch: true });
    });

    it('nets nothing for a material that is not on the floor at all', () => {
        const [line] = netAgainstProduction([{ item_id: 9 }, { item_id: 1, quantity: 500 }].slice(1) as never, by());

        expect(line).toMatchObject({ required: 500, available: 0, ask: 500 });
    });

    it('leaves a line with no material chosen alone', () => {
        const [line] = netAgainstProduction([{ item_id: null, quantity: 500 }], by(material(1, '300.0000')));

        expect(line).toEqual({ required: 500, available: 0, ask: 500, unitMismatch: false });
    });

    it('treats an empty quantity as nothing required', () => {
        const [line] = netAgainstProduction([{ item_id: 1, quantity: null }], by(material(1, '300.0000')));

        expect(line).toEqual({ required: 0, available: 0, ask: 0, unitMismatch: false });
    });
});
