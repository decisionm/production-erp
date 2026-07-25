import type { Shift } from './types';

/**
 * Which shift is running right now, and which production date an entry made
 * right now belongs to. Shifts carry "HH:MM[:SS]" wall-clock windows; the
 * Night shift (e.g. 22:00–06:00) crosses midnight, and by factory convention
 * everything a night shift produces is filed under the date the shift
 * STARTED — so a batch logged at 02:00 belongs to yesterday's night shift,
 * not to a new day. The backend applies the same rule when defaulting
 * production_date (Shift::productionDateFor) — keep the two in sync.
 */

function toMinutes(time: string): number {
    const [h, m] = time.split(':');
    return Number(h) * 60 + Number(m);
}

export function isOvernight(shift: Shift): boolean {
    return toMinutes(shift.start_time) > toMinutes(shift.end_time);
}

function shiftContains(shift: Shift, now: Date): boolean {
    const minutes = now.getHours() * 60 + now.getMinutes();
    const start = toMinutes(shift.start_time);
    const end = toMinutes(shift.end_time);
    return isOvernight(shift) ? minutes >= start || minutes < end : minutes >= start && minutes < end;
}

/** The active shift for the current wall-clock time, if any window matches. */
export function currentShift(shifts: Shift[], now: Date = new Date()): Shift | undefined {
    return shifts.find((s) => shiftContains(s, now));
}

/** Local (not UTC) YYYY-MM-DD — toISOString() is UTC and is already on the
 * wrong calendar day before 05:30 IST. */
function localDateString(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

/**
 * The production date an entry made now belongs to, for the given shift:
 * in the after-midnight tail of an overnight shift, that's YESTERDAY (the
 * shift's start date); otherwise it's today.
 */
export function productionDateFor(shift: Shift | undefined, now: Date = new Date()): string {
    if (shift && isOvernight(shift)) {
        const minutes = now.getHours() * 60 + now.getMinutes();
        if (minutes < toMinutes(shift.end_time)) {
            const yesterday = new Date(now);
            yesterday.setDate(yesterday.getDate() - 1);
            return localDateString(yesterday);
        }
    }
    return localDateString(now);
}
