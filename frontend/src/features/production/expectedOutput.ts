import { type PackingRounding, roundPer } from '@/features/production/packing';

/**
 * The client-side MIRROR of the server's expected-output arithmetic, used by
 * the pre-submit "Results — check before you submit" card and the running-
 * batch card. The server's metrics block is authoritative once completed;
 * this only lets the person see, before submitting, the number the server
 * will store — so it must follow the SAME versioned formula the server does
 * (ProductionCalculationEngine):
 *
 *   production_v3_unified   cycles = FLOOR(3600 × hours / cycleTime), pieces = cycles × cavities
 *   production_v2_floor / legacy / unknown (an entry started before v3)
 *                           pieces = 3600 / cycleTime × cavities × hours, unfloored (2 dp)
 *
 * The version comes from the ENTRY (its calculation_version stamp), never
 * from "now" — a batch started under v2 keeps reading v2 numbers here as it
 * does on the server. Boxes round half-up; pouches follow the factory's
 * packing-rounding setting.
 *
 * EXACT WHERE THE SERVER IS EXACT. The server floors a DECIMAL division
 * (bcdiv on the strings it stores); a float division here read 3999 cycles
 * where the server stored 4000 (CT 10.8 × 12 h: 43200 / 10.8 is exactly
 * 4000, and IEEE 10.8 sits a hair above it), and 19,995 pieces beside a
 * card that would say 20,000. So the cycles are floored on INTEGER
 * micro-units (cyclesFloor), and the downtime is netted the way the server
 * nets it (netRunningHours) — no epsilon, no rounding "fix".
 */
export const VERSION_UNIFIED = 'production_v3_unified';

/** One millionth — the six decimal places the server carries hours at. */
const MICRO = 1_000_000;

const toMicro = (value: number): number => Math.round(value * MICRO);

/**
 * FLOOR(3600 × hours ÷ cycleTime) — the whole cycles a span of running time
 * holds — computed on integer micro-units so an exact quotient is exact:
 * hours and cycle time both scaled by 1e6, then integer division on BigInt
 * (3600 × hoursµ ≤ 8.64e10 for a 24 h span, well inside a safe integer, but
 * BigInt keeps the floor exact for any quotient). Mirrors the server's
 * `(int) bcdiv(bcmul(hours, 3600, 6), cycleTime, 0)`.
 *
 * Zero hours is a known zero (a machine down for the whole span). Null for a
 * cycle time that is not positive or hours that are negative — nothing to
 * compute from.
 */
export function cyclesFloor(hours: number, cycleTimeSeconds: number): number | null {
    if (!Number.isFinite(hours) || !Number.isFinite(cycleTimeSeconds) || cycleTimeSeconds <= 0 || hours < 0) return null;
    const cycleMicro = BigInt(toMicro(cycleTimeSeconds));
    if (cycleMicro <= BigInt(0)) return null;
    const hoursMicro = BigInt(toMicro(hours));
    return Number((BigInt(3600) * hoursMicro) / cycleMicro);
}

/**
 * Running hours NET of downtime, exactly as the server nets them
 * (UnifiedEntryMetrics / LegacyEntryMetrics): the downtime hours are
 * minutes ÷ 60 TRUNCATED to 6 dp — bcdiv(minutes, '60', 6), never rounded —
 * then subtracted from the gross hours at 6 dp, floored at zero. One minute
 * is therefore 0.016666 h, and 8 h less one minute is 7.983334 h — the
 * figure the server floors its cycles from, which a float 8 − 1/60 is not.
 * Null without gross hours (the field is still empty).
 */
export function netRunningHours(grossHours: number | null | undefined, downtimeMinutes: number): number | null {
    if (grossHours === null || grossHours === undefined || !Number.isFinite(grossHours)) return null;
    const minutesMicro = Math.round(downtimeMinutes * MICRO);
    // Integer division of micro-minutes by 60 IS the 6-dp truncation of minutes ÷ 60.
    const downtimeMicroHours = minutesMicro > 0 ? Math.floor(minutesMicro / 60) : 0;
    const netMicro = Math.max(toMicro(grossHours) - downtimeMicroHours, 0);
    return netMicro / MICRO;
}

export function expectedOutput(
    version: string | null | undefined,
    cycleTimeSeconds: number | null,
    cavities: number | null | undefined,
    hours: number | null,
    nosPerBox: number | null,
    nosPerPouch: number | null,
    mode?: PackingRounding,
): { pieces: number; boxes: number | null; pouches: number | null } | null {
    if (!cycleTimeSeconds || cycleTimeSeconds <= 0 || !cavities || cavities <= 0 || !hours || hours <= 0) return null;

    const pieces = version === VERSION_UNIFIED
        ? (cyclesFloor(hours, cycleTimeSeconds) ?? 0) * cavities
        : Math.round((3600 / cycleTimeSeconds) * cavities * hours * 100) / 100;

    const boxes = nosPerBox && nosPerBox >= 1 ? Math.round(pieces / nosPerBox) : null;
    const pouches = nosPerPouch && nosPerPouch >= 1 ? roundPer(pieces / nosPerPouch, mode) : null;

    return { pieces, boxes, pouches };
}
