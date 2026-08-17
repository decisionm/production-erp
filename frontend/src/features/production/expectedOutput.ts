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
 */
export const VERSION_UNIFIED = 'production_v3_unified';

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
        ? Math.floor((3600 * hours) / cycleTimeSeconds) * cavities
        : Math.round((3600 / cycleTimeSeconds) * cavities * hours * 100) / 100;

    const boxes = nosPerBox && nosPerBox >= 1 ? Math.round(pieces / nosPerBox) : null;
    const pouches = nosPerPouch && nosPerPouch >= 1 ? roundPer(pieces / nosPerPouch, mode) : null;

    return { pieces, boxes, pouches };
}
