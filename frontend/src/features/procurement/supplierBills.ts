/**
 * THE WORDS AND ARITHMETIC of the supplier-bill screen, pure so they are
 * testable (28-Aug audit finding 10). The page renders these; it does not
 * re-derive them.
 */
import type { SupplierBillStatus } from './types';

const STATUS: Record<SupplierBillStatus, { color: string; label: string }> = {
    draft: { color: 'default', label: 'Draft' },
    recorded: { color: 'green', label: 'Recorded' },
    cancelled: { color: 'red', label: 'Cancelled' },
};

/** Sentence-case status words. An unknown status still renders, readably. */
export function billStatusTag(status: SupplierBillStatus | string): { color: string; label: string } {
    const known = STATUS[status as SupplierBillStatus];
    if (known) return known;

    const words = String(status);

    return { color: 'default', label: words.charAt(0).toUpperCase() + words.slice(1) };
}

/**
 * The one honest sentence about Tally: a Purchase Invoice voucher is not
 * posted from here, and the reason is a set of OPEN questions, not a
 * missing feature — how the purchase ledger and rate are chosen (Q39),
 * where GST is filed from (Q41). Said once, everywhere the same.
 */
export function billTallyLine(): string {
    return 'Not sent to Tally — Purchase Invoice posting awaits the accountant’s answers (Q39/Q41).';
}

export type BillArithmetic =
    | { kind: 'incomplete' }
    | { kind: 'lines_mismatch'; linesSum: string }
    | { kind: 'total_mismatch'; expectedTotal: string }
    | { kind: 'balanced' };

/**
 * The two equations the server enforces (SupplierBillService), previewed
 * live so the accountant sees the gap before the refusal:
 *
 *   subtotal = Σ line amounts
 *   total    = subtotal + CGST + SGST + IGST + rounding
 *
 * Everything to the paisa. Incomplete figures give no verdict — an empty
 * form must not claim it is balanced (the inspection preview's rule).
 */
export function billArithmetic(values: {
    lines: { amount: number | null }[];
    subtotal: number | null;
    cgst: number | null;
    sgst: number | null;
    igst: number | null;
    rounding: number | null;
    total: number | null;
}): BillArithmetic {
    if (values.subtotal === null || values.total === null) return { kind: 'incomplete' };
    if (values.lines.length === 0 || values.lines.some((line) => line.amount === null)) return { kind: 'incomplete' };

    // Scale 4, matching the server's bc math exactly (guardArithmetic
    // compares at four decimals): a preview that rounded to whole paise
    // could claim "balanced" over sub-paisa dust the server then refuses.
    const scaled = (value: number) => Math.round(value * 10_000);
    // Whole-paisa figures print as money (999.00); sub-paisa dust keeps its
    // four decimals so the named gap is visibly a real gap.
    const words = (units: number) => {
        const value = units / 10_000;
        return Number.isInteger(units / 100) ? value.toFixed(2) : value.toFixed(4);
    };

    const linesSum = values.lines.reduce((sum, line) => sum + scaled(line.amount as number), 0);
    if (linesSum !== scaled(values.subtotal)) {
        return { kind: 'lines_mismatch', linesSum: words(linesSum) };
    }

    const expected = scaled(values.subtotal)
        + scaled(values.cgst ?? 0)
        + scaled(values.sgst ?? 0)
        + scaled(values.igst ?? 0)
        + scaled(values.rounding ?? 0);
    if (expected !== scaled(values.total)) {
        return { kind: 'total_mismatch', expectedTotal: words(expected) };
    }

    return { kind: 'balanced' };
}
