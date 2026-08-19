/**
 * THE STATE OF ONE SUPPLIER LOT'S BAG SCANNING.
 *
 * Pure, and separate from the form, because the rule it encodes is a safety
 * contract rather than a rendering detail: **a scan is never destroyed as a
 * side effect of something else.**
 *
 * Three things went wrong before this existed and each is pinned by a test:
 *
 *  · a PART-scanned lot was submitted as "no barcodes at all", so the server
 *    minted identities and the supplier's own barcodes — already read off the
 *    physical bags — were silently dropped;
 *  · reducing `bag_count` BELOW the number already scanned left the screen
 *    reading "All bags scanned" (because remaining floored at zero) while the
 *    payload quietly fell back to generated identities;
 *  · there was no explicit way to say "generate them after all", so the only
 *    route to that outcome was the silent one.
 *
 * The contract now: **none, or all.** Zero scans means "generate them", which is
 * the ordinary case for a supplier who barcodes nothing. Anything between is
 * refused, and the only way to reach "generated" from a part-scanned lot is a
 * deliberate, confirmed discard.
 */
export type LotScanStatus = 'none' | 'complete' | 'incomplete' | 'over';

export interface LotScanState {
    /** Bags the receipt line says arrived. */
    expected: number;
    /** Supplier barcodes read so far. */
    scanned: number;
    /** Still to scan; never negative. */
    remaining: number;
    status: LotScanStatus;
    /** True when the lot may be part of a submitted receipt. */
    submittable: boolean;
    /** What the receiver is told, or null when nothing needs saying. */
    message: string | null;
}

export function lotScanState(bagCount: number | null | undefined, barcodes: readonly string[] | null | undefined): LotScanState {
    const expected = Number(bagCount ?? 0);
    const scanned = (barcodes ?? []).length;
    const remaining = Math.max(expected - scanned, 0);

    if (scanned === 0) {
        return {
            expected,
            scanned,
            remaining,
            status: 'none',
            // Nothing has been read off a bag, so nothing can be lost. The
            // server generates an identity per bag.
            submittable: true,
            message: null,
        };
    }

    if (expected > 0 && scanned === expected) {
        return { expected, scanned, remaining: 0, status: 'complete', submittable: true, message: null };
    }

    if (scanned > expected) {
        return {
            expected,
            scanned,
            remaining: 0,
            status: 'over',
            submittable: false,
            // The bag count was reduced under the scans. Saying "all scanned"
            // here — which is what a floored remaining produced — invited a
            // submit that threw the extra scans away.
            message: `${scanned} bags have been scanned but the line says ${expected}. Raise the bag count, or remove the scans that do not belong.`,
        };
    }

    return {
        expected,
        scanned,
        remaining,
        status: 'incomplete',
        submittable: false,
        message: `${remaining} bag${remaining === 1 ? '' : 's'} still to scan. A part-scanned lot cannot be submitted — finish it, or discard the scans to have barcodes generated.`,
    };
}

/**
 * Is this lot allowed into a submitted receipt?
 *
 * The server requires exactly one barcode per bag when any are supplied, so
 * "none or all" is its rule too — this refuses the same shapes it would, but
 * before the receiver has walked away from the trolley.
 */
export function lotIsSubmittable(bagCount: number | null | undefined, barcodes: readonly string[] | null | undefined): boolean {
    return lotScanState(bagCount, barcodes).submittable;
}
