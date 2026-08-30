/**
 * THE WORDS AND ARITHMETIC of the incoming-inspection form, pure so they are
 * testable (28-Aug audit, items 10 and 16). The page renders these; it does
 * not re-derive them.
 */

import type { InspectionResult } from './types';

const RESULT_TAG: Record<InspectionResult, { color: string; label: string }> = {
    pass: { color: 'green', label: 'Pass' },
    fail: { color: 'red', label: 'Fail' },
    partial: { color: 'gold', label: 'Partial' },
};

/** Sentence-case result words. An unknown result still renders, readably. */
export function resultTag(result: InspectionResult | string): { color: string; label: string } {
    const known = RESULT_TAG[result as InspectionResult];
    if (known) return known;

    const words = String(result);

    return { color: 'default', label: words.charAt(0).toUpperCase() + words.slice(1) };
}

export type InspectionPreview =
    | { kind: 'incomplete' }
    | { kind: 'unbalanced'; difference: number }
    | { kind: 'result'; result: InspectionResult };

/**
 * What the form's live preview may claim before anything is saved.
 *
 * The old preview defaulted every quantity to 0, judged |0 + 0 − 0| balanced,
 * and showed a green "Result: pass" over an empty form whose submit the
 * server would refuse (`inspected_quantity` must be > 0) — a pass verdict on
 * material nobody had inspected. Empty or all-zero is `incomplete`: no
 * verdict exists yet and none is shown. The result rule itself is the
 * server's, mirrored: rejected 0 → pass, accepted 0 → fail, else partial —
 * and the server's derivation remains the one that is stored.
 */
export function inspectionPreview(values: {
    inspected?: number | null;
    accepted?: number | null;
    rejected?: number | null;
}): InspectionPreview {
    const inspected = values.inspected ?? null;
    const accepted = values.accepted ?? null;
    const rejected = values.rejected ?? null;

    if (inspected === null || accepted === null || rejected === null || inspected <= 0) {
        return { kind: 'incomplete' };
    }

    const difference = accepted + rejected - inspected;
    if (Math.abs(difference) >= 0.0001) return { kind: 'unbalanced', difference };

    if (rejected === 0) return { kind: 'result', result: 'pass' };
    if (accepted === 0) return { kind: 'result', result: 'fail' };

    return { kind: 'result', result: 'partial' };
}
