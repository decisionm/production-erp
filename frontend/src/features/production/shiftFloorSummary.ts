import type { ShiftProductionEntry } from './types';

/**
 * The Shift Floor's two summaries, as pure functions (UX-SHIFT-FLOOR-2026-08-18).
 *
 * NOTHING HERE IS A NEW FACTORY FIGURE. Every quantity is read off an entry the
 * server already sent — `metrics.expected_pieces`, `quantity_produced`,
 * `quantity_scrap`, `quality.rejected_nos` — and the only arithmetic is addition
 * of those figures and one ratio of two sums. No estimation formula, no
 * per-piece derivation, no weight, no dose. The estimation formula lives in ONE
 * place on the server (and, for the live running projection, in the already
 * validated `expectedOutput()`); a second copy here would be exactly the
 * divergence Phase 5.5 closed.
 *
 * Pure so the whole summary is pinned by vitest without rendering the
 * 8,600-line page it is read into.
 */

/**
 * The state a machine card paints, in the SAME priority order the card has
 * always used: a breakdown or an in-progress mold change outranks "Running",
 * because those are the states that need someone next.
 */
export type MachineFloorState = 'down' | 'mold_change' | 'running_other_shift' | 'running' | 'idle';

export interface MachineFloorStateInput {
    /** An OPEN downtime log for this machine. */
    down: boolean;
    /** An OPEN mold-change log for this machine. */
    moldChange: boolean;
    /** An in-progress batch on this machine (any shift, any date). */
    running: boolean;
    /** That batch is filed under a different shift and has not been handed over. */
    runningForOtherShift: boolean;
}

export function machineFloorState(input: MachineFloorStateInput): MachineFloorState {
    if (input.down) return 'down';
    if (input.moldChange) return 'mold_change';
    if (!input.running) return 'idle';
    return input.runningForOtherShift ? 'running_other_shift' : 'running';
}

/**
 * WHOSE MACHINE IS THIS — extracted verbatim from the card so the status tiles
 * and the grid can never disagree about it.
 *
 * A running batch belongs to the shift it is FILED under. Viewing shift S: a
 * batch filed under S is ours; a batch still filed under another shift that has
 * a tab here has not been handed over yet and must not read as ours.
 *
 * Deliberately shift-only, NOT shift-and-date: a batch left running since
 * yesterday's Morning is still Morning's to complete, and gating on the date as
 * well would leave it completable from no tab at all.
 *
 * Every unknown — no shift on the payload, no shift picked yet, a batch filed
 * under a shift that has no tab here — falls through to "ours", so a machine can
 * never become uncompletable because this page could not work out whose it is.
 */
export function isRunningForOtherShift(args: {
    runningShiftId: number | null | undefined;
    effectiveShiftId: number | undefined;
    shiftTabIds: ReadonlySet<number>;
    hasRunning: boolean;
}): boolean {
    const { runningShiftId, effectiveShiftId, shiftTabIds, hasRunning } = args;
    return (
        hasRunning &&
        typeof runningShiftId === 'number' &&
        effectiveShiftId !== undefined &&
        runningShiftId !== effectiveShiftId &&
        shiftTabIds.has(runningShiftId)
    );
}

/** How the floor stands right now — one count per state, plus the total. */
export interface FloorStatusCounts {
    total: number;
    running: number;
    idle: number;
    down: number;
    moldChange: number;
    /** Running, but filed under another shift and not handed over yet. */
    runningOtherShift: number;
}

export function floorStatusCounts(states: readonly MachineFloorState[]): FloorStatusCounts {
    const counts: FloorStatusCounts = { total: states.length, running: 0, idle: 0, down: 0, moldChange: 0, runningOtherShift: 0 };
    for (const state of states) {
        if (state === 'down') counts.down += 1;
        else if (state === 'mold_change') counts.moldChange += 1;
        else if (state === 'idle') counts.idle += 1;
        else if (state === 'running_other_shift') counts.runningOtherShift += 1;
        else counts.running += 1;
    }
    return counts;
}

/**
 * A decimal string as the server sends it ("5121.95") to a number; null for
 * anything that is not one. Never NaN, never 0 for null — a missing figure stays
 * missing rather than becoming a zero that sums.
 */
function num(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined || value === '') return null;
    const n = typeof value === 'number' ? value : parseFloat(value);
    return Number.isNaN(n) ? null : n;
}

/**
 * Completed Today's totals — computed over EXACTLY the rows in the table beneath
 * it, which is the server's `production_date = today, batch_status = completed`
 * read. It spans every shift of the factory day, so it is labelled "today" and
 * never "this shift": tiles showing one shift above a table showing three is a
 * discrepancy a supervisor would reasonably read as a bug.
 */
export interface CompletedTodaySummary {
    /** Rows in the list. */
    batches: number;
    /** Σ quantity_produced — net of any quality rejection, as the column carries it. */
    goodPieces: number | null;
    /** Σ metrics.expected_pieces, over the rows that HAVE one. */
    expectedPieces: number | null;
    /** Σ quantity_scrap — the pieces rejected at completion. */
    rejectPieces: number | null;
    /** Σ quality.rejected_nos, over checked rows only; null when nothing was checked. */
    qcRejectedPieces: number | null;
    /**
     * Σ good ÷ Σ expected × 100, 1dp — over ONLY the rows that carry an expected
     * figure, on BOTH sides of the ratio. Null when no row does.
     *
     * NOT an efficiency and deliberately not called one: the server rules
     * efficiency per entry (`efficiency_pct` + `efficiency_band` against the
     * deployment's `tolerances.efficiency_over`), and an average of ratios is not
     * a ratio of sums. This is "output vs expected" for the rows that have a
     * target, and the excluded count is published beside it so the figure can be
     * read honestly.
     */
    outputVsExpectedPct: number | null;
    /** Rows left OUT of the ratio because they carry no expected figure. */
    withoutExpected: number;
}

export function completedTodaySummary(entries: readonly ShiftProductionEntry[] | null | undefined): CompletedTodaySummary {
    const rows = entries ?? [];
    const summary: CompletedTodaySummary = {
        batches: rows.length,
        goodPieces: null,
        expectedPieces: null,
        rejectPieces: null,
        qcRejectedPieces: null,
        outputVsExpectedPct: null,
        withoutExpected: 0,
    };

    // Both sides of the ratio are summed over the SAME subset — the rows that
    // carry an expected figure — so a floor running unconfigured products cannot
    // read as under-performing simply because its output has no target to sit
    // against.
    let ratioGood: number | null = null;
    let ratioExpected: number | null = null;

    const add = (carry: number | null, value: number | null): number | null =>
        value === null ? carry : (carry ?? 0) + value;

    for (const entry of rows) {
        const good = num(entry.quantity_produced);
        const expected = num(entry.metrics?.expected_pieces);
        const quality = entry.quality ?? null;

        summary.goodPieces = add(summary.goodPieces, good);
        summary.rejectPieces = add(summary.rejectPieces, num(entry.quantity_scrap));
        if (quality?.checked) summary.qcRejectedPieces = add(summary.qcRejectedPieces, quality.rejected_nos ?? null);

        if (expected === null) {
            summary.withoutExpected += 1;
            continue;
        }
        summary.expectedPieces = add(summary.expectedPieces, expected);
        ratioExpected = add(ratioExpected, expected);
        // A row with a target but no produced count contributes 0 to the output
        // side, not nothing: the target was set and nothing was recorded against
        // it, which is a real (and visible) shortfall rather than a row to drop.
        ratioGood = (ratioGood ?? 0) + (good ?? 0);
    }

    if (ratioExpected !== null && ratioExpected > 0 && ratioGood !== null) {
        summary.outputVsExpectedPct = Math.round((ratioGood / ratioExpected) * 1000) / 10;
    }

    return summary;
}
