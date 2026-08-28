import { itemLabel } from '@/lib/itemLabel';

/**
 * WHAT A PURCHASE REQUISITION IS ASKING FOR, in one table cell.
 *
 * The queue used to show the LINE COUNT — "3" — and nothing else, so the one
 * thing a buyer scans the list for, what is being asked for, was the one thing
 * only a drawer could tell them. The data was there all along: the index
 * endpoint already eager-loads `lines.item`.
 *
 * The first line names the row and the rest become a count, because a cell has
 * to stay one line at any width and a requisition of nine materials cannot
 * spell all nine. First rather than "most important": nothing in the data ranks
 * lines, and inventing a rank would put a product at the top of the cell on the
 * app's judgement rather than the requester's order.
 *
 * A requisition with no lines renders the same dash a missing item renders. It
 * is not an error state and does not deserve a different one.
 */
export function requisitionItemsLabel(
    lines: ReadonlyArray<{ item?: { sku?: string | null; name?: string | null; display_name?: string | null } | null }> | null | undefined,
): string {
    if (lines === null || lines === undefined || lines.length === 0) return '—';

    // The first line that actually NAMES something, not simply the first line.
    // A requisition whose opening line points at an item the master no longer
    // serves would otherwise render "—  +2": a dash plus a count, which tells
    // a buyer strictly less than the line count it replaced.
    const named = lines.find((line) => itemLabel(line?.item) !== '—');
    if (named === undefined) return '—';

    const rest = lines.length - 1;

    return rest > 0 ? `${itemLabel(named.item)}  +${rest}` : itemLabel(named.item);
}
