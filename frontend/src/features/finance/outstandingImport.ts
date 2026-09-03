import { hasManageAccess } from '@/features/auth/permissions';
import type { User } from '@/features/auth/types';
import type { ClientOutstandingImportResult } from './types';

/**
 * UPLOADING A TALLY XML EXPORT ONTO THE CLIENT-OUTSTANDING PAGE — everything
 * about it that is a function of its arguments, so it can be pinned without a
 * DOM (outstandingImport.test.ts).
 *
 * Nothing here calls the API and nothing here reads the XML. The one network
 * call is `importClientOutstanding` in api.ts, and the file's contents are
 * never opened in the browser: the server owns the reading of Tally's shape.
 */

// ---- who may press it -------------------------------------------------------

/**
 * Whether THIS login may import a position.
 *
 * `finance.manage` — the one permission the endpoint is gated on. Deliberately
 * NOT tally-sync's two-part test: that press releases vouchers OUT to the
 * accountant's live books and carries FC-06's Owner/Accounts pair on top of
 * the queue's own gate. This one writes a read-only mirror INTO the ERP, and
 * borrowing a stricter rule from a different decision would lock out the
 * Accounts logins the server lets through.
 *
 * A COURTESY, NEVER THE GATE. This only decides whether the control is drawn.
 * The server 403s the request regardless of what the page shows, so a stale
 * page, a hand-built request or another API client is refused by the backend
 * and not by this function.
 */
export function canImportClientOutstanding(user: User | null): boolean {
    return hasManageAccess(user, 'finance');
}

/**
 * What the file picker offers. Tally's "Group Outstandings" export is XML; the
 * server is what actually decides, so this narrows the dialog and claims
 * nothing.
 */
export const OUTSTANDING_IMPORT_ACCEPT = '.xml';

// ---- what to say afterwards -------------------------------------------------

export interface OutstandingImportOutcome {
    /** `warning` is reserved for a 200 that changed nothing — see below. */
    tone: 'success' | 'warning';
    text: string;
}

/**
 * The honest sentence for what the server just answered.
 *
 * TWO OUTCOMES, KEPT APART, because one of them is a 200 that is not a win.
 *
 *   read     rows came out of the file, and the counts say how many of each.
 *   skipped  `skipped_empty` — Tally's export held nothing usable, so the ERP
 *            KEPT the position it already had. Green-ticking that would tell
 *            an accountant the debtor book had just been refreshed when in
 *            fact nothing moved, and the figures on screen are as old as they
 *            were before the upload. It is not an error either: refusing it
 *            in red would send someone hunting for a fault in a file that
 *            simply had no pending bills in it.
 *
 * IT NEVER SAYS "SYNCED", "SENT" OR "POSTED". This traffic runs INWARD — a
 * file is read into the ERP's mirror of Tally. Nothing was written to Tally,
 * and the outward words belong to the sync queue, which is a different act.
 *
 * `as_of` is treated as possibly empty. That is not padding: the premise of
 * this whole control is a page that has NOTHING pulled yet, so the likeliest
 * first real `skipped_empty` is the owner exporting the wrong Tally report
 * onto a position with no prior date at all — "the position as at  is
 * unchanged" is not a sentence to put in front of them.
 */
export function outstandingImportOutcome(result: ClientOutstandingImportResult): OutstandingImportOutcome {
    const asAt = typeof result.as_of === 'string' ? result.as_of.trim() : '';

    if (result.skipped_empty) {
        return {
            tone: 'warning',
            text: asAt === ''
                ? 'Nothing usable in that file — the position is unchanged.'
                : `Nothing usable in that file — the position as at ${asAt} is unchanged.`,
        };
    }

    const counts = [
        countOf(result.bills, 'bill'),
        countOf(result.orders, 'order'),
        countOf(result.parties, 'party', 'parties'),
    ].join(', ');

    return {
        tone: 'success',
        text: asAt === '' ? `${counts}.` : `${counts} — position as at ${asAt}.`,
    };
}

/** "1 bill", "37 bills" — the count is always printed, zero included. */
function countOf(count: number, singular: string, many = `${singular}s`): string {
    return `${count} ${count === 1 ? singular : many}`;
}
