/**
 * DEC-20260902-019: an off-plan spare, tooling or unclassified item is
 * accepted with a reason and an authorised person, its category shown
 * CLEARLY — the real category, not a paraphrase — with a warning. Nothing
 * here blocks; the server keeps its refusal set (finished goods and the
 * run's own product only).
 *
 * `ItemCategory` (backend/app/Modules/Inventory/Models/Enums/ItemCategory.php)
 * has seven cases. raw_material and packing_material are the ordinary,
 * planned consumption this run expects, so they stay silent; finished_good
 * is never offered here at all (the server refuses it), so it stays silent
 * too rather than warning about a case that can't occur. The remaining four
 * — unclassified (null), other, spare_tooling, work_in_progress, consumable
 * — are exactly what a supervisor adding a line should be told plainly.
 */
export function addedLineWarning(category: string | null | undefined): string | null {
    if (category === null || category === undefined) return 'Unclassified';
    switch (category) {
        case 'other':
            return 'Other';
        case 'spare_tooling':
            return 'Spare or tooling';
        case 'work_in_progress':
            return 'Work in progress';
        case 'consumable':
            return 'Consumable';
        default:
            return null;
    }
}
