import { describe, expect, it } from 'vitest';
import { billArithmetic, billStatusTag, billTallyLine } from './supplierBills';

describe('billArithmetic', () => {
    const base = { lines: [{ amount: 1000 }], subtotal: 1000, cgst: null, sgst: null, igst: 180, rounding: null, total: 1180 };

    it('a bill that adds up is balanced', () => {
        expect(billArithmetic(base)).toEqual({ kind: 'balanced' });
    });

    it('an empty form gives no verdict — incomplete, never balanced', () => {
        expect(billArithmetic({ ...base, subtotal: null })).toEqual({ kind: 'incomplete' });
        expect(billArithmetic({ ...base, lines: [{ amount: null }] })).toEqual({ kind: 'incomplete' });
        expect(billArithmetic({ ...base, lines: [] })).toEqual({ kind: 'incomplete' });
    });

    it('lines that do not sum to the subtotal name their own sum', () => {
        expect(billArithmetic({ ...base, lines: [{ amount: 999 }] })).toEqual({ kind: 'lines_mismatch', linesSum: '999.00' });
    });

    it('a total that does not add up names the expected figure', () => {
        expect(billArithmetic({ ...base, total: 1200 })).toEqual({ kind: 'total_mismatch', expectedTotal: '1180.00' });
    });

    it('rounding is signed and counts', () => {
        expect(billArithmetic({ ...base, rounding: -0.4, total: 1179.6 })).toEqual({ kind: 'balanced' });
    });

    it('paise precision — 0.1 + 0.2 style float dust does not unbalance a bill', () => {
        expect(billArithmetic({ lines: [{ amount: 0.1 }, { amount: 0.2 }], subtotal: 0.3, cgst: null, sgst: null, igst: null, rounding: null, total: 0.3 })).toEqual({ kind: 'balanced' });
    });
});

describe('billStatusTag', () => {
    it('speaks sentence case, never the raw enum', () => {
        expect(billStatusTag('draft')).toEqual({ color: 'default', label: 'Draft' });
        expect(billStatusTag('recorded')).toEqual({ color: 'green', label: 'Recorded' });
        expect(billStatusTag('something_new')).toEqual({ color: 'default', label: 'Something_new' });
    });
});

describe('billTallyLine', () => {
    it('names the open questions rather than claiming a missing feature', () => {
        expect(billTallyLine()).toContain('Q39');
        expect(billTallyLine()).toContain('Not sent to Tally');
    });
});
