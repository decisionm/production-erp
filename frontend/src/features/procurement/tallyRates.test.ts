import { describe, expect, it } from 'vitest';
import { describeGst, describeVoucher, formatRate, prefillFrom, unavailableMessage } from '@/features/procurement/tallyRates';
import type { TallyPurchaseRateQuote, TallyVendorItemRate } from '@/features/procurement/types';

/**
 * The purchase-order form's Tally rate panel, decided in pure functions.
 *
 * THE PREFILL RULE IS WHAT THIS SUITE IS FOR. Tally quotes a rate as
 * `674.000/Kgs.` — a number and the basis it is per — and Q40 records 28 of
 * 382 purchase-order lines carrying two units. A rate per kilogram dropped
 * onto a line counted in pieces silently restates the price of a real order.
 * Every value below is synthetic (FC-06).
 */

function quote(overrides: Partial<TallyPurchaseRateQuote> = {}): TallyPurchaseRateQuote {
    return {
        voucher_type: 'purchase_invoice',
        voucher_number: '77',
        voucher_reference: 'REF-77',
        voucher_date: '2026-07-01',
        party_ledger_name: 'SYNTHETIC SUPPLIES',
        party_gstin: '33AAAAA0000A1ZA',
        stock_item_name: 'ITEM_A',
        rate_value: '674.000000',
        rate_unit: 'Kgs.',
        quantity: '48.0000',
        quantity_unit: 'Kgs.',
        amount: '32352.0000',
        gst: { cgst_rate: '9.0000', sgst_rate: '9.0000', igst_rate: '18.0000', cess_rate: null, hsn_code: '39076190', purchase_ledger_name: 'Interstate Purchase Taxable' },
        item_uom: 'Kgs.',
        unit_matches: true,
        may_prefill: true,
        prefill_blocked_reason: null,
        source: 'tally',
        tally_synced_at: '2026-08-30T09:00:00+00:00',
        ...overrides,
    };
}

function lookup(overrides: Partial<TallyVendorItemRate> = {}): TallyVendorItemRate {
    return {
        vendor: { id: 1, name: 'Synthetic Supplies', tally_ledger_name: 'SYNTHETIC SUPPLIES' },
        item: { id: 2, name: 'ITEM_A', uom: 'Kgs.' },
        purchase_order: null,
        purchase_invoice: quote(),
        suggestion: quote(),
        unavailable_reason: null,
        last_synced_at: '2026-08-30T09:00:00+00:00',
        ...overrides,
    };
}

describe('prefillFrom', () => {
    it('offers the suggested rate on an empty price field', () => {
        expect(prefillFrom(lookup(), null)).toBe(674);
        expect(prefillFrom(lookup(), undefined)).toBe(674);
    });

    it('refuses when the rate is quoted per a unit the item is not held in', () => {
        // The Q40 case: bought by weight, counted in pieces.
        const blocked = quote({ unit_matches: false, may_prefill: false, item_uom: 'Nos.', prefill_blocked_reason: 'Tally quotes this rate per Kgs. and the item is held in Nos.' });

        expect(prefillFrom(lookup({ suggestion: blocked }), null)).toBeNull();
    });

    it('never overwrites a rate the buyer has already typed', () => {
        // Including a deliberate zero — a free line is a decision, not a blank.
        expect(prefillFrom(lookup(), 600)).toBeNull();
        expect(prefillFrom(lookup(), 0)).toBeNull();
    });

    it('offers nothing when Tally has nothing for this vendor and item', () => {
        expect(prefillFrom(lookup({ suggestion: null }), null)).toBeNull();
        expect(prefillFrom(null, null)).toBeNull();
        expect(prefillFrom(undefined, null)).toBeNull();
    });

    it('refuses an unreadable rate rather than filling in a zero', () => {
        expect(prefillFrom(lookup({ suggestion: quote({ rate_value: 'n/a' }) }), null)).toBeNull();
    });
});

describe('what the panel shows', () => {
    it('shows the rate with the basis it is quoted per', () => {
        expect(formatRate(quote())).toBe('674.000000 / Kgs.');
        expect(formatRate(quote({ rate_unit: null }))).toBe('674.000000');
    });

    it('names the voucher a figure came off, and which kind it is', () => {
        expect(describeVoucher(quote())).toBe('Tally Purchase Invoice · #REF-77 · 2026-07-01');
        expect(describeVoucher(quote({ voucher_type: 'purchase_order', voucher_reference: null, voucher_number: '77' })))
            .toBe('Tally PO · #77 · 2026-07-01');
    });

    it('lists only the GST heads that actually carry a rate', () => {
        expect(describeGst(quote())).toBe('CGST 9% + SGST 9% + IGST 18%');
        // A head Tally listed without a rate is absent, not 0%.
        expect(describeGst(quote({ gst: { ...quote().gst, cgst_rate: null, sgst_rate: null } }))).toBe('IGST 18%');
    });

    it("gives the server's own reason when nothing can be suggested", () => {
        expect(unavailableMessage(lookup({ suggestion: null, purchase_invoice: null, unavailable_reason: 'No Tally purchase order or purchase invoice has been synced for this vendor and item.' })))
            .toContain('No Tally purchase order');

        expect(unavailableMessage(lookup({ suggestion: quote({ may_prefill: false, prefill_blocked_reason: 'Tally recorded no unit for this rate, so the basis cannot be confirmed.' }) })))
            .toContain('no unit');

        expect(unavailableMessage(lookup())).toBeNull();
    });
});
