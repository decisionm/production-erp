import type { FulfilmentHold, FulfilmentQueueRow, FulfilmentState } from './types';

/**
 * THE STORE FULFILMENT QUEUE, in words and in the two numbers this screen is
 * allowed to work out for itself.
 *
 * WHAT IS NOT HERE, deliberately:
 *
 *  - NO ORDERING. Over-reserved rows come first because
 *    FulfilmentQueueService sorted them that way (S8), across the WHOLE
 *    queue rather than the page in front of the reader. A comparator on this
 *    side would sort one page of 25 and quietly defeat the thing it looks
 *    like it is implementing, so the table carries no `sorter` at all.
 *  - NO STATE DERIVATION. `fulfilment_state` and `can{}` ride on the wire
 *    from the same service the writes refuse in, so a button and its 422
 *    cannot disagree. Nothing here recomputes either.
 *  - NO READY-FOR-DISPATCH. That is a server bool on the order (S1's
 *    coverage rule is judged on the LINE), not an ∀ over the rows on screen.
 *
 * What IS here is the prefill arithmetic — what to put in the box before the
 * storekeeper types — and the vocabulary the queue and its holds are read in.
 */

// ------------------------------------------------------------- quantities --

/**
 * A quantity as bcmath will accept it: digits and an optional point, never
 * `1e-7` and never `1e+21`.
 *
 * `App\Rules\PlainDecimal` refuses exponential notation on every quantity
 * this flow posts, and JavaScript reaches for it on its own — `Number`
 * stringifies anything below 1e-6 or at/above 1e21 that way, so an
 * InputNumber the floor typed into can hand a perfectly ordinary-looking
 * value straight into a 422. `toFixed(4)` is the whole fix: 4dp is the
 * column's own precision (decimal(15,4)), so nothing is lost that the
 * database would have kept.
 *
 * NULL IS NOT ZERO and never becomes it — an unknown quantity is not posted.
 */
export function plainDecimal(value: string | number | null | undefined): string | null {
    if (value === null || value === undefined || value === '') return null;

    const parsed = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(parsed)) return null;

    /*
     * `toFixed` IS NOT THE WHOLE FIX, and finding that out cost a red test.
     * It formats plainly only below 1e21 — at or above it, toFixed hands back
     * the exponent it was called to remove ("1e+21"). A double that large has
     * no fractional part left, so BigInt expands it to its exact digits.
     *
     * The server refuses the figure either way (`max:99999999999`), and that
     * is the point: it should refuse it as a number that is too big, with the
     * limit in the message, rather than as a malformed one.
     */
    if (Math.abs(parsed) >= 1e21) return `${BigInt(parsed)}.0000`;

    return parsed.toFixed(4);
}

/**
 * A decimal string read as a number, for comparison and prefill only.
 *
 * Returns null — never 0 — for anything unreadable, because a prefill of zero
 * is a proposal to reserve nothing, and a storekeeper who accepts it has been
 * told a lie by an empty box that looked filled in.
 */
function figure(value: string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * WHAT TO PUT IN THE RESERVE BOX: min(free, shortfall).
 *
 * Both caps are real refusals on the server — free stock (there is nothing
 * else to hold) and the line's remaining demand (S5: ordered − delivered −
 * already held) — so the larger of the two is a number the store would type
 * and be refused for. The smaller is the whole of what this click can achieve.
 *
 * NULL WHEN THERE IS NOTHING TO PROPOSE: either figure missing, or the answer
 * being zero or less. The box opens empty and the store types, rather than
 * being shown a 0 it has to clear.
 */
export function reservePrefill(row: Pick<FulfilmentQueueRow, 'free' | 'shortfall'>): number | null {
    const free = figure(row.free);
    const shortfall = figure(row.shortfall);
    if (free === null || shortfall === null) return null;

    const take = Math.min(free, shortfall);

    return take > 0 ? Number(take.toFixed(4)) : null;
}

/**
 * WHAT TO PUT IN THE SEND-TO-PRODUCTION BOX: the whole shortfall.
 *
 * Not `shortfall − free`: free stock is held by RESERVING it, which is the
 * other button, and a store that sends only the uncovered part while never
 * pressing Reserve has asked the floor for too little. The server caps this
 * at the line's real shortfall recomputed under a lock (S14) either way.
 */
export function sendToProductionPrefill(row: Pick<FulfilmentQueueRow, 'shortfall'>): number | null {
    const shortfall = figure(row.shortfall);
    if (shortfall === null || shortfall <= 0) return null;

    return Number(shortfall.toFixed(4));
}

// ------------------------------------------------------------ vocabulary --

/**
 * The five states in the words the store reads them in, with the tone each
 * carries. `over_reserved` is the only red one: it is the only state that
 * says a decision is owed about whose order gives way.
 */
export const FULFILMENT_STATE_LABEL: Record<FulfilmentState, string> = {
    untouched: 'Nothing held',
    partially_allocated: 'Part held',
    awaiting_production: 'With production',
    over_reserved: 'Promised twice',
    fully_allocated: 'Covered',
};

export const FULFILMENT_STATE_TONE: Record<FulfilmentState, string> = {
    untouched: 'default',
    partially_allocated: 'gold',
    awaiting_production: 'blue',
    over_reserved: 'red',
    fully_allocated: 'green',
};

/**
 * A state's label, and an UNKNOWN token passed through unchanged rather than
 * blanked — the rule `salesRateSourceLabel` follows. A state this build has
 * not been taught is still better evidence than an empty cell, and it is
 * exactly the row worth looking at.
 */
export function fulfilmentStateLabel(state: FulfilmentState | string): string {
    return FULFILMENT_STATE_LABEL[state as FulfilmentState] ?? state;
}

export function fulfilmentStateTone(state: FulfilmentState | string): string {
    return FULFILMENT_STATE_TONE[state as FulfilmentState] ?? 'default';
}

/**
 * "held for {customer} since {date}" — one hold, said the way the brief asks
 * for it.
 *
 * A hold with no customer and no date still says what it is holding: the
 * sentence degrades, it never disappears, because a hold nobody can name is
 * the one somebody most needs to see.
 */
export function holdSentence(hold: Pick<FulfilmentHold, 'customer' | 'held_since'>): string {
    const customer = hold.customer?.name ?? 'an unnamed customer';
    const since = hold.held_since ? factoryCalendarDate(hold.held_since) : null;

    return since ? `held for ${customer} since ${since}` : `held for ${customer}`;
}

/**
 * The CALENDAR DATE of an instant at the factory, not at Greenwich. The
 * server sends UTC instants; a hold taken late in an IST evening belongs to
 * that evening's date, and slicing the ISO string put it on the day before.
 * en-CA because its date form IS YYYY-MM-DD. An unparseable value falls back
 * to the raw slice rather than to nothing — a wrong-looking date is more
 * useful on this screen than a hold with no date at all.
 */
export function factoryCalendarDate(isoInstant: string): string {
    const parsed = new Date(isoInstant);

    if (Number.isNaN(parsed.getTime())) {
        return isoInstant.slice(0, 10);
    }

    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(parsed);
}

/**
 * THE RE-POINT TARGETS a hold may legally be moved to.
 *
 * `StockReservationService::repoint` refuses three ways, and offering a
 * choice the server will reject is worse than offering none: the SAME ITEM
 * (repointItemMismatch), a DIFFERENT LINE (cannotRepointToSameLine), and an
 * order that is still open — which every row in this queue already is.
 *
 * Filtered here rather than asked of the server because the queue read is the
 * only list of open lines this screen has, and the modal opens over it.
 */
export function repointTargets(rows: FulfilmentQueueRow[], source: FulfilmentQueueRow): FulfilmentQueueRow[] {
    if (source.item === null) return [];

    return rows.filter(
        (row) =>
            row.line_id !== source.line_id &&
            row.item?.id === source.item?.id &&
            // A line with no remaining demand refuses every positive
            // re-point (the S5 cap), so offering it guarantees a 422
            // (Codex P2, PR #33). ordered − delivered − reserved, in the
            // payload's own 4dp strings.
            Number(row.ordered) - Number(row.delivered) - Number(row.reserved) > 0,
    );
}
