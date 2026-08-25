import { describe, expect, it } from 'vitest';
import {
    canEditSalesOrder,
    expectedDateCell,
    hasSalesOrderEdits,
    overdueBadge,
    salesOrderEditPayload,
} from './salesOrder';

/**
 * THE EXPECTED DATE, as the Sales Orders list and drawer treat it.
 *
 * Two things are pinned here because getting either wrong is silent:
 *
 *  - OVERDUE IS THE SERVER'S ANSWER. It is computed on the factory's
 *    calendar (IST) and arrives as a flag. Nothing here reads the browser's
 *    clock, so a laptop in another timezone — or one with the wrong date —
 *    cannot make an on-time order read late.
 *  - AN UNTOUCHED FIELD IS NOT SENT. The endpoint reads an absent key as
 *    "leave it alone" and an explicit null as "clear it". A payload builder
 *    that echoed every field back would clobber a note somebody else typed
 *    while the drawer was open, and one that dropped nulls could never
 *    clear a date at all.
 */

const order = {
    expected_date: '2026-08-20' as string | null,
    is_overdue: false,
    notes: 'Ship with the Friday load.' as string | null,
    can_edit: true,
};

describe('canEditSalesOrder', () => {
    it('offers Edit only when the server said so', () => {
        expect(canEditSalesOrder({ can_edit: true })).toBe(true);
        expect(canEditSalesOrder({ can_edit: false })).toBe(false);
    });

    it('hides Edit when there is no order and when the flag is missing', () => {
        expect(canEditSalesOrder(null)).toBe(false);
        expect(canEditSalesOrder(undefined)).toBe(false);
        // An older cached row without the flag: hide the action rather than
        // offer a write the server would refuse.
        expect(canEditSalesOrder({} as { can_edit: boolean })).toBe(false);
    });
});

describe('expectedDateCell', () => {
    it('shows the date the server spelled', () => {
        expect(expectedDateCell({ expected_date: '2026-08-20', is_overdue: false })).toEqual({
            text: '2026-08-20',
            overdue: false,
        });
    });

    it('shows a dash for an order with no promise date, and never flags it', () => {
        expect(expectedDateCell({ expected_date: null, is_overdue: false })).toEqual({ text: '—', overdue: false });
        // Belt to the server's brace: no date, nothing to be late for.
        expect(expectedDateCell({ expected_date: null, is_overdue: true }).overdue).toBe(false);
    });

    it('flags only what the server flagged', () => {
        expect(expectedDateCell({ expected_date: '2026-08-20', is_overdue: true }).overdue).toBe(true);
    });
});

describe('overdueBadge', () => {
    it('says the word, not just the colour, and names the date it is about', () => {
        expect(overdueBadge({ expected_date: '2026-08-20', is_overdue: true })).toEqual({
            text: 'Overdue',
            label: 'Overdue — expected 2026-08-20',
        });
    });

    it('is absent for an on-time order and for an undated one', () => {
        expect(overdueBadge({ expected_date: '2026-08-30', is_overdue: false })).toBeNull();
        expect(overdueBadge({ expected_date: null, is_overdue: false })).toBeNull();
    });
});

describe('salesOrderEditPayload', () => {
    it('sends nothing when nothing was changed', () => {
        const payload = salesOrderEditPayload(order, { expected_date: '2026-08-20', notes: order.notes });
        expect(payload).toEqual({});
        expect(hasSalesOrderEdits(payload)).toBe(false);
    });

    it('sends only the date when only the date moved', () => {
        expect(salesOrderEditPayload(order, { expected_date: '2026-09-01', notes: order.notes })).toEqual({
            expected_date: '2026-09-01',
        });
    });

    it('sends only the notes when only the notes changed', () => {
        expect(salesOrderEditPayload(order, { expected_date: '2026-08-20', notes: 'Customer called.' })).toEqual({
            notes: 'Customer called.',
        });
    });

    it('sends an explicit null to clear the date — not an absent key', () => {
        const payload = salesOrderEditPayload(order, { expected_date: null, notes: order.notes });
        expect(payload).toEqual({ expected_date: null });
        expect('expected_date' in payload).toBe(true);
        expect(hasSalesOrderEdits(payload)).toBe(true);
    });

    it('treats an empty picker value the same as a cleared one', () => {
        expect(salesOrderEditPayload(order, { expected_date: '', notes: order.notes })).toEqual({ expected_date: null });
    });

    it('gives a date to an order that had none', () => {
        expect(
            salesOrderEditPayload({ expected_date: null, notes: null }, { expected_date: '2026-08-30', notes: null }),
        ).toEqual({ expected_date: '2026-08-30' });
    });

    it('clears the notes with an explicit null, and reads blank text as cleared', () => {
        expect(salesOrderEditPayload(order, { expected_date: '2026-08-20', notes: '' })).toEqual({ notes: null });
        expect(salesOrderEditPayload(order, { expected_date: '2026-08-20', notes: '   ' })).toEqual({ notes: null });
    });

    it('does not report a change when only surrounding whitespace differs', () => {
        expect(
            salesOrderEditPayload(order, { expected_date: '2026-08-20', notes: `  ${order.notes}  ` }),
        ).toEqual({});
    });

    it('sends both when both changed', () => {
        expect(salesOrderEditPayload(order, { expected_date: null, notes: 'Held.' })).toEqual({
            expected_date: null,
            notes: 'Held.',
        });
    });

    it('never carries a status, a customer or lines — only the two fields the desk owns', () => {
        const payload = salesOrderEditPayload(order, { expected_date: '2026-09-02', notes: 'Moved.' });
        expect(Object.keys(payload).sort()).toEqual(['expected_date', 'notes']);
    });
});
