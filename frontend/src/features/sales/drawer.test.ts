import { describe, expect, it } from 'vitest';
import {
    cartonSummary,
    documentTitle,
    tallyLinkTag,
    unvalidatedBuilderTag,
    unwrapMirrorResponse,
    unwrapShowResponse,
} from './drawer';
import type { TallyLink } from './types';

/**
 * The words the Sales pages use for a document's Tally state, pinned:
 * the SAME words the Tally Sync page uses (one status vocabulary across the
 * app), the unvalidated-builder warning always naming the decision, and a
 * missing entry rendered as "no entry", never as a state.
 */

const link = (over: Partial<TallyLink> = {}): TallyLink => ({
    entry_id: 41,
    voucher_type: 'Sales',
    status: 'pending',
    voucher_number: 'INV-3',
    synced_at: null,
    flags: {
        unvalidated_builder: {
            note: 'The Sales voucher XML is a best-effort template, not yet validated against a real Tally instance, and carries no GST.',
            builder: 'sales.ts',
            decision: 'DEC-20260809-003',
        },
    },
    link: '/tally-sync?entry=41',
    ...over,
});

describe('tallyLinkTag', () => {
    it('uses the Tally Sync page\'s own words and colours for each status', () => {
        expect(tallyLinkTag(link({ status: 'pending' }))).toEqual({ color: 'default', label: 'Waiting for agent' });
        expect(tallyLinkTag(link({ status: 'synced' }))).toEqual({ color: 'green', label: 'In Tally' });
        expect(tallyLinkTag(link({ status: 'failed' }))).toEqual({ color: 'red', label: 'FAILED' });
        expect(tallyLinkTag(link({ status: 'dismissed' }))).toEqual({ color: 'default', label: 'Dismissed — never sent' });
    });

    it('is null when there is no entry — a dash, not a state', () => {
        expect(tallyLinkTag(null)).toBeNull();
        expect(tallyLinkTag(undefined)).toBeNull();
    });
});

describe('unvalidatedBuilderTag', () => {
    it('names the decision the server put on the flag and carries the server\'s note as the hover text', () => {
        expect(unvalidatedBuilderTag(link())).toEqual({
            text: 'unvalidated builder — Tally is the sales system of record (DEC-20260809-003)',
            note: 'The Sales voucher XML is a best-effort template, not yet validated against a real Tally instance, and carries no GST.',
        });
    });

    it('still says whose system of record it is when the flag carries no decision id', () => {
        const flagged = link({ flags: { unvalidated_builder: { note: 'best effort', builder: 'deliveryNote.ts' } } });

        expect(unvalidatedBuilderTag(flagged)).toEqual({
            text: 'unvalidated builder — Tally is the sales system of record',
            note: 'best effort',
        });
    });

    it('is null when the flag is not raised, or there is no entry', () => {
        expect(unvalidatedBuilderTag(link({ flags: {} }))).toBeNull();
        expect(unvalidatedBuilderTag(link({ flags: undefined as never }))).toBeNull();
        expect(unvalidatedBuilderTag(null)).toBeNull();
    });
});

describe('documentTitle', () => {
    it('reads the number off the document when the server sent one, else spells it itself', () => {
        expect(documentTitle('sales_order', { id: 12, document_number: 'SO-12' })).toBe('SO-12');
        expect(documentTitle('delivery', { id: 5 })).toBe('DN-5');
        expect(documentTitle('invoice', null, 3)).toBe('INV-3');
    });

    it('is the bare kind while nothing is known yet', () => {
        expect(documentTitle('sales_order', null)).toBe('Sales order');
        expect(documentTitle('delivery', null)).toBe('Delivery');
        expect(documentTitle('invoice', undefined)).toBe('Invoice');
    });
});

describe('cartonSummary', () => {
    it('counts boxes and pieces, and names the batches — a typed delivery has none to name', () => {
        expect(cartonSummary([
            { carton_no: 'C-1', pieces: '2400', shift_production_entry_id: 9, batch_no: 'B-102' },
            { carton_no: 'C-2', pieces: '2400.0000', shift_production_entry_id: 9, batch_no: 'B-102' },
            { carton_no: 'C-3', pieces: '1200', shift_production_entry_id: 10, batch_no: 'B-103' },
        ])).toEqual({ cartons: 3, pieces: 6000, batches: ['B-102', 'B-103'] });

        expect(cartonSummary([])).toEqual({ cartons: 0, pieces: 0, batches: [] });
        expect(cartonSummary(undefined)).toEqual({ cartons: 0, pieces: 0, batches: [] });
    });

    it('does not invent a batch for a carton that carries none', () => {
        expect(cartonSummary([{ carton_no: 'C-1', pieces: '10', shift_production_entry_id: null, batch_no: null }]).batches).toEqual([]);
    });
});

describe('unwrapShowResponse', () => {
    it('reads the document out of `data` and takes `trace` from wherever the server put it', () => {
        expect(unwrapShowResponse({ data: { id: 1, trace: { deliveries: [] } } })).toEqual({ id: 1, trace: { deliveries: [] } });
        expect(unwrapShowResponse({ data: { id: 1 }, trace: { deliveries: [] } })).toEqual({ id: 1, trace: { deliveries: [] } });
    });

    it('leaves trace undefined when neither place carries one', () => {
        expect(unwrapShowResponse({ data: { id: 1 } })).toEqual({ id: 1 });
    });
});

describe('unwrapMirrorResponse', () => {
    const mirror = {
        mirrored: false,
        decision: 'DEC-20260809-003',
        headline: 'Real sales are invoiced in Tally',
        body: 'Tally-side Sales and Sales Order vouchers are not mirrored into this ERP.',
        erp_invoice_builder: { validated: false, note: 'not validated' },
        payments_recorded_here: false,
        payments_note: 'An invoice is never marked paid by this ERP — receipts live in Tally.',
    };

    it('accepts the object bare or wrapped in `data`', () => {
        expect(unwrapMirrorResponse(mirror)).toEqual(mirror);
        expect(unwrapMirrorResponse({ data: mirror })).toEqual(mirror);
    });
});
