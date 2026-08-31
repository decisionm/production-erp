import type { TallyPurchaseRateQuote, TallyVendorItemRate } from '@/features/procurement/types';

/**
 * THE PURE DECISIONS BEHIND THE TALLY RATE PANEL — kept out of the component
 * so they can be tested without rendering anything, the way grnTally.ts and
 * requisitionCoverage.ts already are in this feature.
 *
 * The one that matters is `prefillFrom`. The server decides whether a rate's
 * basis was confirmed (`may_prefill`); this decides whether the FORM should
 * act on it, and it adds one condition the server cannot see — whether the
 * person has already typed a price. A suggestion that overwrites a rate
 * somebody negotiated this morning is not a suggestion.
 */

/** A rate as a person reads it: `674.000000 / Kgs.` — or just the number. */
export function formatRate(quote: TallyPurchaseRateQuote): string {
    return quote.rate_unit !== null ? `${quote.rate_value} / ${quote.rate_unit}` : quote.rate_value;
}

/** "PO 77 · 01 Jul 2026" — which voucher this figure came off. */
export function describeVoucher(quote: TallyPurchaseRateQuote): string {
    const kind = quote.voucher_type === 'purchase_order' ? 'Tally PO' : 'Tally Purchase Invoice';
    const number = quote.voucher_reference ?? quote.voucher_number;

    return [kind, number !== null ? `#${number}` : null, quote.voucher_date].filter(Boolean).join(' · ');
}

/** The GST heads that carry a rate, as `CGST 9% + SGST 9%`. Empty when none do. */
export function describeGst(quote: TallyPurchaseRateQuote): string {
    const heads: [string, string | null][] = [
        ['CGST', quote.gst.cgst_rate],
        ['SGST', quote.gst.sgst_rate],
        ['IGST', quote.gst.igst_rate],
        ['Cess', quote.gst.cess_rate],
    ];

    return heads
        .filter(([, rate]) => rate !== null && Number(rate) !== 0)
        .map(([head, rate]) => `${head} ${Number(rate)}%`)
        .join(' + ');
}

/**
 * The number to put in the line's price field, or null to leave it alone.
 *
 * THREE CONDITIONS, ALL REQUIRED, and each one is a defect that has a name:
 *
 *  1 · there is a suggestion at all;
 *  2 · the server confirmed its BASIS matches the item's unit. Tally quotes
 *      `674.000/Kgs.` and Q40 records 28 of 382 purchase-order lines carrying
 *      two units — a rate per kilogram dropped onto a line counted in pieces
 *      restates the price of a real order and nothing on screen would show it;
 *  3 · the field is still EMPTY. Prefilling is help for a blank form, never a
 *      correction of a person's own typing.
 */
export function prefillFrom(lookup: TallyVendorItemRate | null | undefined, currentValue: number | null | undefined): number | null {
    const suggestion = lookup?.suggestion;

    if (!suggestion || !suggestion.may_prefill) return null;
    if (currentValue !== null && currentValue !== undefined) return null;

    const value = Number(suggestion.rate_value);

    return Number.isFinite(value) ? value : null;
}

/**
 * What the panel says when it cannot suggest anything — the server's own
 * words where it gave them, so the reason a person sees is the reason the
 * server had, never a second guess written here.
 */
export function unavailableMessage(lookup: TallyVendorItemRate | null | undefined): string | null {
    if (!lookup) return null;
    if (lookup.unavailable_reason !== null) return lookup.unavailable_reason;

    return lookup.suggestion?.prefill_blocked_reason ?? null;
}
