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
        // Through num() like every sibling sum. The type says number|null
        // today, but every other quantity on this resource arrives as a decimal
        // STRING — the day this one follows, a bare `add` would silently
        // concatenate instead of adding.
        if (quality?.checked) summary.qcRejectedPieces = add(summary.qcRejectedPieces, num(quality.rejected_nos));

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

/**
 * WHAT UNIT TODAY'S FIGURES ARE IN.
 *
 * `quantity_produced` is NOT a piece count by definition — it is denominated in
 * the finished item's own unit of measure. The completion posts it straight to
 * stock (`recordReceipt(quantity: quantity_produced)`), so whatever the item
 * master calls that unit is what the number means. On this factory's data that
 * is `Nos.` for 77 items — while this screen printed the literal `pcs`. Two
 * spellings of one thing here, but the same literal on a `kg`-denominated
 * product would be a wrong unit stated with confidence, which is the class of
 * error that gets a figure withdrawn.
 *
 * So the unit is READ, never assumed, and it is only ever stated when every row
 * agrees on it. Spellings are compared case-folded and trimmed and NOTHING
 * else: `Nos.` and `pcs` may well be the same unit to the factory, but deciding
 * that is the owner's call and not a display function's — they read as
 * different here, which withholds the total rather than mislabelling it.
 */
export interface CompletedTodayUnits {
    /** The unit every row shares, spelled as the item master spells it. Null when they disagree, or when no row says. */
    uom: string | null;
    /** The rows disagree — there is no one unit these sums can be labelled with. */
    mixed: boolean;
    /** Every distinct spelling seen, in first-seen order. */
    uoms: string[];
}

export function completedTodayUnits(entries: readonly ShiftProductionEntry[] | null | undefined): CompletedTodayUnits {
    const seen = new Map<string, string>();
    for (const entry of entries ?? []) {
        const raw = (entry.item?.uom ?? '').trim();
        if (raw === '') continue;
        const key = raw.toLowerCase();
        if (!seen.has(key)) seen.set(key, raw);
    }
    const uoms = [...seen.values()];
    return {
        uom: uoms.length === 1 ? uoms[0] : null,
        mixed: uoms.length > 1,
        uoms,
    };
}

/**
 * IS THIS ROW'S CREATION INSTANT ACTUALLY INSIDE THE SHIFT IT IS FILED UNDER?
 *
 * There is no `started_at` column on shift_production_entries — `created_at` is
 * the row-creation instant — so a start time can only be shown when the two
 * cannot disagree. A BACK-DATED batch (Start Batch's date picker, or a
 * Configure-Recipe round trip that crosses a boundary) is created today and
 * filed under an earlier shift, and printing its creation time would name a
 * moment the machine was not running.
 *
 * THE GATE THIS REPLACES COMPARED `productionDateFor(shift, created)` AGAINST
 * `production_date`, AND FOR AN OVERNIGHT SHIFT THAT IS ALMOST NO GATE AT ALL:
 * `productionDateFor` maps EVERY clock time before an overnight shift's start
 * back to the previous day — that is its documented job, so that a Night batch
 * recorded at 02:00, at 06:10 in the grace window, or at 10:00 as late
 * paperwork all file under yesterday. The consequence is that a Night batch
 * filed under the 18th and created at 10:00 on the 19th passed the check and
 * rendered "started 10:00" — a time outside the 22:00–06:00 window entirely,
 * with no date beside it. Exactly the back-dating the gate exists to suppress.
 *
 * So the question asked here is the direct one: did this row come into
 * existence DURING the shift window it claims? Start is the production date at
 * the shift's start time; the window runs forward by the shift's own length, so
 * an overnight shift ends on the following calendar day. A 24-hour or malformed
 * span, an absent shift and an unparseable date all answer FALSE — the time is
 * simply not shown, and the Carryover tag already states the date and shift for
 * those runs.
 *
 * Compared in the browser's local zone, which is the zone the time is PRINTED
 * in — comparing in one zone and rendering in another is how a correct check
 * still shows a wrong number.
 */
export function createdWithinShiftWindow(args: {
    createdAt: string | null | undefined;
    productionDate: string | null | undefined;
    shift: { start_time: string; end_time: string } | null | undefined;
}): boolean {
    const { createdAt, productionDate, shift } = args;
    if (!createdAt || !productionDate || !shift) return false;

    const created = new Date(createdAt);
    if (Number.isNaN(created.getTime())) return false;

    const minutes = (value: string): number | null => {
        const match = /^(\d{1,2}):(\d{2})/.exec((value ?? '').trim());
        if (!match) return null;
        const h = Number(match[1]);
        const m = Number(match[2]);
        if (h > 23 || m > 59) return null;
        return h * 60 + m;
    };

    const startMinutes = minutes(shift.start_time);
    const endMinutes = minutes(shift.end_time);
    if (startMinutes === null || endMinutes === null) return false;

    // The shift's own length, rolling past midnight for an overnight window. A
    // zero-length span (start === end) is not a window anybody ran a batch in.
    const lengthMinutes = (endMinutes - startMinutes + 1440) % 1440;
    if (lengthMinutes === 0) return false;

    // Local midnight of the production date — built from parts rather than
    // parsed, so it can never be read as UTC.
    const [y, mo, d] = productionDate.split('-').map(Number);
    if (!y || !mo || !d) return false;
    const start = new Date(y, mo - 1, d, 0, 0, 0, 0);
    if (Number.isNaN(start.getTime())) return false;
    start.setMinutes(startMinutes);

    const end = new Date(start.getTime() + lengthMinutes * 60_000);
    return created >= start && created <= end;
}
