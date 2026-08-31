import type { MaterialFlowMaterial } from './types';

/**
 * WHAT THE FLOOR NEEDS, WHAT IT ALREADY HAS, AND WHAT IT ASKS THE STORE FOR.
 *
 * DEC-20260831-001: material not returned at the end of production stays
 * available in Production/WIP and is the next day's opening material, so the
 * next request must take account of it. The screen shows three figures —
 * total required, quantity already available in Production/WIP, and the
 * balance to request — where the balance is the first minus the second,
 * floored at zero.
 *
 * THIS IS THE DISPLAY'S COPY OF THE ARITHMETIC, NOT THE AUTHORITY. The server
 * recomputes all three when the request is written, against the floor as it
 * stands at that moment: a tab left open since the morning would otherwise
 * net against material that has since been consumed or returned. The two
 * agree in every ordinary case, and where they cannot, the server's figure is
 * the one recorded.
 *
 * ONE STANDING QUANTITY, HOWEVER MANY LINES ASK FOR IT. Two lines of the same
 * material must not each net off the whole of what is on the floor — 300 kg
 * cannot answer two 400 kg lines twice. Lines are netted in the order they
 * appear and the standing quantity is spent down, which is exactly what
 * MaterialRequestService does.
 *
 * Numbers, not decimal strings, and deliberately: these figures are typed
 * into an InputNumber and displayed, never written. Every stored quantity is
 * computed server-side in bcmath.
 */
export interface NettedLine {
    /** What production needs — what the storekeeper typed. */
    required: number;
    /** What is usably standing on the floor and may be netted off this line. */
    available: number;
    /** The balance to request from the store: required − available, min 0. */
    ask: number;
    /**
     * The material is standing on the floor but in a unit the item master no
     * longer agrees with (FC-03), so nothing is netted. The quantity is real
     * — the screen must not read as "the floor is empty".
     */
    unitMismatch: boolean;
    /**
     * What is ACTUALLY standing there, netted or not, negative included. Shown
     * whenever it differs from what was netted, which is exactly the two cases
     * the owner asked to stay visible: a negative balance and a unit mismatch.
     */
    standing: number;
}

interface Line {
    item_id: number | null;
    quantity: number | null;
}

/**
 * @param lines the draft lines, in the order they are shown
 * @param materials the requestable materials, by id, carrying what is standing
 * @returns one NettedLine per input line, in the same order
 */
export function netAgainstProduction(
    lines: Line[],
    materials: Map<number, MaterialFlowMaterial>,
): NettedLine[] {
    const spent = new Map<number, number>();

    return lines.map((line) => {
        const required = line.quantity ?? 0;

        if (line.item_id === null) {
            return { required, available: 0, ask: required, unitMismatch: false, standing: 0 };
        }

        const material = materials.get(line.item_id);
        const unitMismatch = material?.production_unit_matches === false;
        const usable = Number(material?.available_in_production ?? 0);
        // What is really there — negative included — for the display only.
        const actuallyStanding = Number(material?.standing_in_production ?? 0);

        // A negative reaches here as 0 from the server, which already refuses
        // to call a discrepancy "available". Guarded again so a future shape
        // change cannot make the floor ask for MORE than it needs.
        const left = Math.max(0, usable - (spent.get(line.item_id) ?? 0));
        const available = Math.min(Math.max(0, required), left);

        spent.set(line.item_id, (spent.get(line.item_id) ?? 0) + available);

        return {
            required,
            available,
            ask: Math.max(0, required - available),
            unitMismatch,
            standing: actuallyStanding,
        };
    });
}
