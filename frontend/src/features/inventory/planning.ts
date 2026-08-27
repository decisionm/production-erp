import type { FulfilmentPlanningBasis, FulfilmentPlanningRow } from './types';

/**
 * THE PLANNING DASHBOARD'S WORDS AND ITS FOOTER — everything on that screen
 * that is not a figure the server already computed.
 *
 * NO DATE IS WORKED OUT HERE, and none is stored anywhere (S11). The ETA walk
 * lives in Production's FulfilmentPlanningService, is computed on read, and is
 * gone again — a saved date is wrong the moment somebody reorders the queue.
 * This module reads `estimated_ready_date` or it prints the refusal; it never
 * makes a third answer out of `shifts_needed` and a guess.
 */

/**
 * WHY A LINE HAS NO DATE, in the reader's words.
 *
 * The middle one is the CASCADE (S12): once anything ahead in the queue cannot
 * be estimated, nothing behind it can be either, and the server refuses to
 * quote a caveat-date instead of admitting that. The sentence says WHERE the
 * problem is, because the row it is printed on is not the row to fix.
 */
export const CANNOT_ESTIMATE_REASON: Record<string, string> = {
    no_production_standard: 'no standard for this product',
    items_ahead_without_standard: 'a job ahead of it has no standard',
    no_active_shift_hours: 'no active shift carries a clock',
};

/**
 * A reason token in words, with an UNKNOWN token passed through UNCHANGED.
 *
 * The same rule `salesRateSourceLabel` follows: a reason this build has not
 * been taught is still better evidence than a blank, and blanking it would
 * make an unestimable row look like an estimable one that simply has no date.
 */
export function cannotEstimateReason(reason: string | null | undefined): string {
    if (!reason) return 'cannot estimate';

    return CANNOT_ESTIMATE_REASON[reason] ?? reason;
}

/**
 * The four fields an ETA cell is decided from — the planning dashboard's rows
 * and the Production Queue's `planning` block both satisfy this.
 *
 * Narrowed to what the function READS rather than typed as the whole planning
 * row, so the two screens computing the same cell share one implementation
 * instead of forking the vocabulary. A second copy is how "cannot estimate"
 * ends up worded two ways in one app.
 */
export type PlanningEtaSource = Pick<
    FulfilmentPlanningRow,
    'cannot_estimate' | 'estimated_ready_date' | 'shifts_needed' | 'reason'
>;

/** One planning row's ETA cell, as the table renders it. */
export interface PlanningEtaCell {
    /** True when a date exists — the discriminator, never `reason === null`. */
    dated: boolean;
    /** YYYY-MM-DD, or null. */
    date: string | null;
    /** "cannot estimate — {reason}" when there is no date; null when there is. */
    refusal: string | null;
    /** "3 shifts" / "1 shift", or null when the server could not say. */
    shifts: string | null;
}

/**
 * WHAT ONE ROW'S ETA CELL SAYS.
 *
 * `cannot_estimate` is the server's own flag and is read as the discriminator
 * — not `estimated_ready_date === null`, and not `reason !== null`. The three
 * can only disagree if the server changes, and if it does the flag is the one
 * that carries the decision.
 *
 * A DATE AND A REFUSAL ARE NEVER BOTH SHOWN. S12 is explicit that an
 * unestimable line gets no date and no caveat-date, so a row that somehow
 * arrived with both is rendered as the refusal: the honest half wins.
 */
export function planningEtaCell(row: PlanningEtaSource): PlanningEtaCell {
    const dated = !row.cannot_estimate && row.estimated_ready_date !== null;
    const shifts =
        row.shifts_needed === null ? null : `${row.shifts_needed} shift${row.shifts_needed === 1 ? '' : 's'}`;

    return {
        dated,
        date: dated ? row.estimated_ready_date : null,
        // A refusal with NO token says the refusal once. `cannotEstimateReason`
        // falls back to the words "cannot estimate" for its own standalone
        // callers, and pasting that after the same phrase read "cannot
        // estimate — cannot estimate". The planning walk always names a
        // reason; a row it never reached at all does not, and the Production
        // Queue's defensive branch is exactly that row.
        refusal: dated ? null : row.reason ? `cannot estimate — ${cannotEstimateReason(row.reason)}` : 'cannot estimate',
        shifts,
    };
}

/**
 * THE BASIS FOOTER — the numbers those ETAs stand on, as a single line of
 * figures under the table.
 *
 * FIGURES, NOT PROSE. Nobody on a factory floor reads a paragraph explaining a
 * date, and a paragraph is also where an unverified claim hides; a row of
 * numbers can be checked against the shift master in ten seconds.
 *
 * `no_active_shifts` is the one case that is a WORD rather than a number, and
 * it must not be dressed up as "0 shifts/day": the factory has not been told
 * its own shifts, which is why every row below says it cannot estimate.
 */
export function planningBasisLine(basis: FulfilmentPlanningBasis): string {
    if (basis.source === 'no_active_shifts' || basis.shifts_per_day <= 0) {
        return `No active shifts · ${basis.timezone}`;
    }

    const parts = [
        `${basis.shifts_per_day} shift${basis.shifts_per_day === 1 ? '' : 's'}/day`,
        basis.shift_hours === null ? 'shift hours —' : `${basis.shift_hours} h/shift`,
        `${basis.parallel_lines} line${basis.parallel_lines === 1 ? '' : 's'}`,
        basis.timezone,
    ];

    return parts.join(' · ');
}
