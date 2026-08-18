import type { ShiftProductionEntry } from './types';

/**
 * Completed batches ↔ Shift Summary reconcile (Phase 5.7, WS-C).
 *
 * The Shift Summary's `actual_production_kg` is, on the server, exactly
 * Σ quantity_produced_kg over the date/shift's COMPLETED entries
 * (ShiftSummaryService::report — bcadd at 4 dp, null read as "0", only
 * batch_status = completed). Completed Today reads the same entries through
 * the entries index. This module re-adds the index's figures the same way
 * so the page can show the two side by side and say "equal" or name the
 * difference — it is a CHECK that two server reads agree, not a third
 * source of the figure. Nothing here multiplies, divides or estimates a
 * factory quantity.
 *
 * WHY STRINGS AND NOT NUMBERS: the kilograms are 4-dp decimal columns and
 * the page shows them verbatim ("61.9200"). Summing them as IEEE floats
 * would call 0.1 + 0.2 ≠ 0.3 a discrepancy, so the arithmetic is fixed-point
 * on the digits — each string scaled to an integer of ten-thousandths
 * (BigInt, so no magnitude ever drifts either), summed, and formatted back
 * with exactly four decimals.
 *
 * Pure, so vitest pins it without rendering the page.
 */

const SCALE_DIGITS = 4;
const SCALE = 10n ** BigInt(SCALE_DIGITS);

/**
 * "61.9200" → 619200n (ten-thousandths). Reads what a decimal(…,4) column
 * can send — an optional sign, digits, at most four decimals — and NOTHING
 * else: thousands separators, exponents or a fifth decimal are not a kg
 * column value, and guessing at them would be inventing a figure, so they
 * return null. `null` / `undefined` / "" read as 0, exactly the server's
 * `quantity_produced_kg ?? '0'`.
 */
export function parseKg4(value: string | number | null | undefined): bigint | null {
    if (value === null || value === undefined) return 0n;
    const text = typeof value === 'number' ? String(value) : value.trim();
    if (text === '') return 0n;
    const match = /^([+-])?(\d*)(?:\.(\d{0,4}))?$/.exec(text);
    if (!match) return null;
    const [, sign, whole = '', fraction = ''] = match;
    if (whole === '' && fraction === '') return null;
    const scaled = BigInt(whole || '0') * SCALE + BigInt((fraction + '0000').slice(0, SCALE_DIGITS));
    return sign === '-' ? -scaled : scaled;
}

/** 619200n → "61.9200" — always exactly four decimals, sign kept. */
export function formatKg4(scaled: bigint): string {
    const negative = scaled < 0n;
    const magnitude = negative ? -scaled : scaled;
    const whole = magnitude / SCALE;
    const fraction = (magnitude % SCALE).toString().padStart(SCALE_DIGITS, '0');
    return `${negative ? '-' : ''}${whole.toString()}.${fraction}`;
}

/**
 * Σ of 4-dp kg strings as a 4-dp string; "0.0000" for none (the server's
 * reduce seed). Null when any operand is unreadable — a sum with a hole in
 * it is not a sum.
 */
export function addKg(values: ReadonlyArray<string | number | null | undefined>): string | null {
    let total = 0n;
    for (const value of values) {
        const scaled = parseKg4(value);
        if (scaled === null) return null;
        total += scaled;
    }
    return formatKg4(total);
}

export interface ShiftSummaryReconcile {
    /** Σ quantity_produced_kg over the completed entries, 4 dp ("4120.0000"). */
    sumKg: string;
    /** The summary's actual_production_kg, normalised to 4 dp. */
    summaryKg: string;
    /** How many completed batches the sum covers. */
    batches: number;
    equal: boolean;
    /** |sumKg − summaryKg| as a 4-dp string; "0.0000" when equal. */
    difference: string;
    /** Which side is larger when they differ; null when equal. */
    direction: 'batches_over' | 'summary_over' | null;
}

/**
 * The verdict for one date/shift: the entries index's completed batches
 * (already filtered server-side; re-filtered here to batch_status =
 * completed so an index that returned more never inflates the sum) against
 * the summary's actual_production_kg. Null — never a wrong verdict — when
 * either side is unreadable.
 */
export function reconcileShiftSummary(
    summaryActualKg: string | number | null | undefined,
    entries: ReadonlyArray<ShiftProductionEntry> | null | undefined,
): ShiftSummaryReconcile | null {
    if (summaryActualKg === null || summaryActualKg === undefined) return null;
    const summary = parseKg4(summaryActualKg);
    if (summary === null) return null;

    const completed = (entries ?? []).filter((entry) => entry.batch_status === 'completed');
    let sum = 0n;
    for (const entry of completed) {
        const scaled = parseKg4(entry.quantity_produced_kg);
        if (scaled === null) return null;
        sum += scaled;
    }

    const delta = sum - summary;
    return {
        sumKg: formatKg4(sum),
        summaryKg: formatKg4(summary),
        batches: completed.length,
        equal: delta === 0n,
        difference: formatKg4(delta < 0n ? -delta : delta),
        direction: delta === 0n ? null : delta > 0n ? 'batches_over' : 'summary_over',
    };
}
