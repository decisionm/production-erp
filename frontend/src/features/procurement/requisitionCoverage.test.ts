import { describe, expect, it } from 'vitest';

import { balanceToOrderWords, coverageStatusTag, hasCoverage, quantityWithUom } from './requisitionCoverage';
import type { PurchaseRequisitionLine } from './types';

const line = (over: Partial<PurchaseRequisitionLine> = {}): PurchaseRequisitionLine =>
    ({
        id: 1,
        item: { id: 1, sku: 'RES-1', name: 'Relpet', uom: 'Kgs' },
        quantity: '500.0000',
        notes: null,
        requested_quantity: '500.0000',
        ordered_quantity: '200.0000',
        balance_quantity: '300.0000',
        order_status: 'partially_ordered',
        ...over,
    }) as PurchaseRequisitionLine;

/** A line from a server that does not decorate — the four keys absent, not null. */
const bare = (over: Partial<PurchaseRequisitionLine> = {}): PurchaseRequisitionLine =>
    ({ id: 2, item: { id: 1, sku: 'RES-1', name: 'Relpet', uom: 'Kgs' }, quantity: '500.0000', notes: null, ...over }) as PurchaseRequisitionLine;

describe('coverageStatusTag', () => {
    it('words the three states the server sends', () => {
        expect(coverageStatusTag('not_ordered').label).toBe('Not Ordered');
        expect(coverageStatusTag('partially_ordered').label).toBe('Partially Ordered');
        expect(coverageStatusTag('fully_ordered').label).toBe('Fully Ordered');
    });

    it('a status this build has not heard of still renders, sentence-cased', () => {
        expect(coverageStatusTag('over_ordered').label).toBe('Over ordered');
    });

    it('absent is a dash, never "Not Ordered" — silence is not a claim about the orders', () => {
        expect(coverageStatusTag(undefined).label).toBe('—');
        expect(coverageStatusTag(null).label).toBe('—');
        expect(coverageStatusTag('').label).toBe('—');
    });
});

describe('quantityWithUom', () => {
    it('carries the item unit with every figure', () => {
        expect(quantityWithUom('500.0000', 'Kgs')).toBe('500.0000 Kgs');
    });

    it('an item with no recorded unit prints the bare figure', () => {
        expect(quantityWithUom('500.0000', null)).toBe('500.0000');
        expect(quantityWithUom('500.0000', '   ')).toBe('500.0000');
    });

    it('a missing figure is a dash, and no unit is appended to a dash', () => {
        expect(quantityWithUom(undefined, 'Kgs')).toBe('—');
        expect(quantityWithUom('', 'Kgs')).toBe('—');
    });
});

describe('hasCoverage', () => {
    it('is true only when the server decorated the line', () => {
        expect(hasCoverage(line())).toBe(true);
        expect(hasCoverage(bare())).toBe(false);
    });

    it('a half-served line is not treated as decorated — four cells must agree', () => {
        expect(hasCoverage(bare({ ordered_quantity: '1.0000' }))).toBe(false);
    });
});

describe('balanceToOrderWords', () => {
    it('groups what is left by unit and never adds two units together', () => {
        const words = balanceToOrderWords([
            line({ id: 1, balance_quantity: '300.0000' }),
            line({
                id: 2,
                item: { id: 2, sku: 'CAP-1', name: 'Cap', uom: 'Nos' } as PurchaseRequisitionLine['item'],
                balance_quantity: '40.0000',
            }),
        ]);
        expect(words).toBe('300 Kgs + 40 Nos');
        // 340 — kilograms added to pieces — is exactly what this must not say.
        expect(words).not.toContain('340');
    });

    it('adds decimals on integers — three tenths are 0.3, not 0.30000000000000004', () => {
        // Read as JS numbers these three sum to 0.30000000000000004 and the
        // list cell prints all seventeen digits. The whole reason
        // src/lib/scaledDecimal exists.
        expect(
            balanceToOrderWords([
                line({ id: 1, balance_quantity: '0.1000' }),
                line({ id: 2, balance_quantity: '0.1000' }),
                line({ id: 3, balance_quantity: '0.1000' }),
            ]),
        ).toBe('0.3 Kgs');
    });

    it('a balance that cannot be read is left out, never counted as a NaN', () => {
        expect(balanceToOrderWords([line({ balance_quantity: 'n/a' }), line({ id: 2, balance_quantity: '5.0000' })]))
            .toBe('5 Kgs');
    });

    it('says so in words when every line is covered', () => {
        expect(balanceToOrderWords([line({ balance_quantity: '0.0000', order_status: 'fully_ordered' })]))
            .toBe('Nothing left to order');
    });

    it('skips undecorated lines rather than counting them as zero', () => {
        expect(balanceToOrderWords([line({ balance_quantity: '300.0000' }), bare()])).toBe('300 Kgs');
    });

    it('is a dash when NO line carries figures — the same silence one line gives', () => {
        expect(balanceToOrderWords([bare(), bare({ id: 3 })])).toBe('—');
        expect(balanceToOrderWords([])).toBe('—');
    });
});
