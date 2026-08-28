import { describe, expect, it } from 'vitest';
// grnQcSummary / inspectionsByLine were removed with their implementation:
// they derived a receipt's QC state from the inspections register because
// "the backend keeps no reverse link" — which stopped being true when GRN
// lines gained a server-side `qc` (inspection + bag hold). grnQc.ts is the
// one vocabulary now, pinned by grnQc.test.ts.
import {
    bagLabelsDrawerTitle,
    grnDrawerTitle,
    grnNumber,
    prDrawerTitle,
    prNumber,
    requisitionStatusTag,
    vendorLedgerWords,
} from '@/features/procurement/documentWords';

describe('document numbers', () => {
    it('spells a requisition PR-{id}, from a number or a record', () => {
        expect(prNumber(7)).toBe('PR-7');
        expect(prNumber({ id: 12 })).toBe('PR-12');
    });

    it("uses the server's document_number for a receipt, and builds the same spelling without one", () => {
        expect(grnNumber({ id: 3, document_number: 'GRN-3' })).toBe('GRN-3');
        expect(grnNumber({ id: 3 })).toBe('GRN-3');
        expect(grnNumber({ id: 3, document_number: '  ' })).toBe('GRN-3');
    });
});

/**
 * THE `#undefined` CONTRACT (28-Aug audit, item 1). A Drawer's title keeps
 * rendering through the close animation after the page nulls its record, so
 * the title functions must be honest for null — never interpolate an absent
 * record.
 */
describe('drawer titles', () => {
    it('never says undefined, whatever the record state', () => {
        for (const title of [
            grnDrawerTitle(null),
            prDrawerTitle(null),
            bagLabelsDrawerTitle(null),
            grnDrawerTitle({ id: 4, document_number: 'GRN-4' }),
            prDrawerTitle({ id: 9 }),
            bagLabelsDrawerTitle({ id: 4 }),
        ]) {
            expect(title).not.toContain('undefined');
            expect(title).not.toContain('#');
        }
    });

    it('names the document when it is there and stays a plain word when it is not', () => {
        expect(grnDrawerTitle({ id: 4 })).toBe('Goods Receipt GRN-4');
        expect(grnDrawerTitle(null)).toBe('Goods Receipt');
        expect(prDrawerTitle({ id: 9 })).toBe('Purchase Requisition PR-9');
        expect(prDrawerTitle(null)).toBe('Purchase Requisition');
        expect(bagLabelsDrawerTitle({ id: 4, document_number: 'GRN-4' })).toBe('GRN-4 — bag labels ready');
        expect(bagLabelsDrawerTitle(null)).toBe('Bag labels');
    });
});

describe('requisitionStatusTag', () => {
    it('gives sentence-case words, never the raw enum', () => {
        expect(requisitionStatusTag('draft')).toEqual({ color: 'default', label: 'Draft' });
        expect(requisitionStatusTag('approved')).toEqual({ color: 'green', label: 'Approved' });
        expect(requisitionStatusTag('rejected')).toEqual({ color: 'red', label: 'Rejected' });
    });

    it('still renders an unknown status readably rather than throwing', () => {
        expect(requisitionStatusTag('partially_ordered')).toEqual({ color: 'default', label: 'Partially ordered' });
    });
});

describe('vendorLedgerWords', () => {
    it('says not mapped for an absent or blank ledger name', () => {
        expect(vendorLedgerWords({ name: 'Reliance Polymers' }).kind).toBe('not_mapped');
        expect(vendorLedgerWords({ name: 'Reliance Polymers', tally_ledger_name: null }).kind).toBe('not_mapped');
        expect(vendorLedgerWords({ name: 'Reliance Polymers', tally_ledger_name: '  ' }).kind).toBe('not_mapped');
    });

    it('collapses a ledger that matches the name — ignoring case and all whitespace', () => {
        expect(vendorLedgerWords({ name: 'Reliance Polymers', tally_ledger_name: 'Reliance Polymers' }).kind).toBe('same_as_name');
        expect(vendorLedgerWords({ name: 'Reliance Polymers', tally_ledger_name: 'RELIANCE  polymers' }).kind).toBe('same_as_name');
    });

    it('shows a genuinely different ledger name in full — the row the column exists for', () => {
        expect(vendorLedgerWords({ name: 'Reliance Polymers', tally_ledger_name: 'Reliance Industries Ltd (Polymer Div)' }))
            .toEqual({ kind: 'differs', text: 'Reliance Industries Ltd (Polymer Div)' });
    });
});
