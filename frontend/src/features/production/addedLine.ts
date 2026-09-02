/**
 * DEC-20260902-019: an off-plan spare, tooling or unclassified item is
 * accepted with a reason and an authorised person, its category shown
 * clearly with a warning. Nothing here blocks; the server keeps its
 * refusal set (finished goods and the run's own product only).
 */
export function addedLineWarning(category: string | null | undefined): string | null {
    if (category === null || category === undefined) return 'Unclassified';
    if (category === 'other') return 'Other: spare, tooling or consumable';
    return null;
}
