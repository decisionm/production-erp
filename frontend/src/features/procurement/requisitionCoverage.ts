/**
 * HOW MUCH OF A REQUISITION HAS BEEN ORDERED, in the words a screen prints.
 *
 * The arithmetic is the server's (RequisitionCoverageService): it groups by
 * item across every order raised from the requisition, and it is the same sum
 * the backend refuses an over-order on — so nothing here recomputes a figure,
 * and the screen can never disagree with the rule. This file only chooses
 * words and handles the honest absences.
 *
 * THE FIGURES MAY BE ABSENT. The server OMITS the four keys — never nulls
 * them — when a line was not decorated, and an older backend does not send
 * them at all. Absent is reported as absent (a dash), because "not computed"
 * and "nothing ordered" are different facts about a purchase and a buyer acts
 * on them differently.
 */

import { fromScaled, toScaled, trimQuantity } from '@/lib/scaledDecimal';

import type { CoverageStatus, PurchaseRequisitionLine } from './types';

export type { CoverageStatus };

const COVERAGE_WORDS: Record<CoverageStatus, { color: string; label: string }> = {
    not_ordered: { color: 'default', label: 'Not Ordered' },
    partially_ordered: { color: 'gold', label: 'Partially Ordered' },
    fully_ordered: { color: 'green', label: 'Fully Ordered' },
};

/**
 * The status tag. An unknown value still renders — the requisitionStatusTag
 * rule: a word the server sends and this build has not heard of is shown as
 * sentence-cased text rather than swallowed.
 */
export function coverageStatusTag(status: CoverageStatus | string | undefined | null): { color: string; label: string } {
    if (status === undefined || status === null || status === '') {
        return { color: 'default', label: '—' };
    }

    const known = COVERAGE_WORDS[status as CoverageStatus];
    if (known) return known;

    const words = String(status).replaceAll('_', ' ');

    return { color: 'default', label: words.charAt(0).toUpperCase() + words.slice(1) };
}

/**
 * A line's quantity with its item's unit — "500.0000 Kgs", the spelling the
 * requisition drawer has always used for the ask, now applied to all four
 * figures so the columns read as one row of the same kind of thing.
 *
 * Four places are kept, unlike the goods-receipt picker's one-line label: a
 * quantity COLUMN is read for precision, and the space is there.
 *
 * A dash for an absent figure (see the file note) and for one that is not a
 * number: the unit is not appended to a dash.
 */
export function quantityWithUom(quantity: string | undefined | null, uom: string | undefined | null): string {
    const figure = (quantity ?? '').trim();
    if (figure === '') return '—';

    const unit = (uom ?? '').trim();

    return unit === '' ? figure : `${figure} ${unit}`;
}

/**
 * Whether this line carries the coverage figures at all — one predicate, so a
 * table's four cells agree about it rather than each testing a different key
 * and disagreeing on a partially-served row.
 */
export function hasCoverage(line: PurchaseRequisitionLine): boolean {
    return line.ordered_quantity !== undefined
        && line.balance_quantity !== undefined
        && line.order_status !== undefined;
}

/**
 * The one-line summary a requisition's row shows beside its orders: what is
 * still to order, unit-wise, or the plain words when there is nothing left.
 *
 * UNIT-WISE and never a total (the server's rule, kept here): a requisition
 * for resin in Kgs and caps in Nos has no single balance, so this groups the
 * outstanding lines by their item's unit exactly as the goods-receipt picker
 * groups an order's.
 *
 * Lines with no figures are skipped rather than counted as zero, and when NO
 * line carries figures the answer is a dash — the same silence a single line
 * gives.
 */
export function balanceToOrderWords(lines: readonly PurchaseRequisitionLine[]): string {
    const withFigures = lines.filter(hasCoverage);
    if (withFigures.length === 0) return '—';

    // Scaled integers, never floats: three lines of '0.1000' added as JS
    // numbers print 0.30000000000000004 in a list cell. The same guarantee
    // quantitiesByUom gives, from the same helpers.
    const byUom = new Map<string, bigint>();
    for (const line of withFigures) {
        const balance = toScaled(line.balance_quantity);
        if (balance === null || balance <= 0n) continue;

        const uom = (line.item?.uom ?? '').trim();
        byUom.set(uom, (byUom.get(uom) ?? 0n) + balance);
    }

    if (byUom.size === 0) return 'Nothing left to order';

    return [...byUom]
        .map(([uom, balance]) => {
            const figure = trimQuantity(fromScaled(balance));

            return uom === '' ? figure : `${figure} ${uom}`;
        })
        .join(' + ');
}
