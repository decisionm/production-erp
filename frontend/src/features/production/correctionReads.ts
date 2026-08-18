import type { Paginated } from '@/lib/types';
import { canAmendCompletion, isAwaitingCorrection, type ShiftProductionEntry } from './types';

/**
 * The Shift Floor's two correction reads (Phase 7, WS-C) — what replaced
 * the `?status=pending` walk to page 25.
 *
 * The page has two things to say about a completed batch that outlive the
 * day it was made: quality sent it back (the amber panel), or the floor may
 * still amend it (the quiet "completed earlier" line). Both used to be
 * derived on the client from the WHOLE approval backlog — every pending
 * entry, 20 a page, up to 25 pages, every minute. The entries index now
 * answers each question itself (`awaiting_correction=1`, `correctable=1`;
 * ListShiftProductionEntriesRequest, Phase 7 WS-B), so each read is one
 * request in the normal case and the walk below only continues if the
 * server says there is more.
 *
 * WHY THE CLIENT PREDICATES STAY. `correctionLists` still runs
 * `isAwaitingCorrection` / `canAmendCompletion` over the rows — as a PARITY
 * GUARD, not a filter: the server derives both flags from the same fields
 * (ShiftProductionEntryService::correctionHistory; amendCompletion's
 * preconditions), so on a matching backend the guard is a no-op, and on a
 * backend that does not know the two query keys yet (it ignores keys nobody
 * documented, and would answer with the whole pending list the reads also
 * ask for) the panel still shows exactly the right batches rather than every
 * pending batch on the floor.
 *
 * Pure, so vitest pins the walk and the derivation without rendering the
 * page or touching the network.
 */

/** The server's page ceiling — one request reads the whole list in the normal case. */
export const CORRECTION_READ_PER_PAGE = 100;

/**
 * The second bound on the walk — a malformed `meta` cannot spin it, and it
 * is the honest limit of the read: 5 × 100 = 500 batches, the same 500 the
 * old 25 × 20 pending walk carried. A correction backlog deeper than that
 * would leave the oldest unlisted, which is itself a sign the approval
 * chain has stopped moving.
 */
export const CORRECTION_READ_MAX_PAGES = 5;

export interface EntryWalk {
    entries: ShiftProductionEntry[];
    /** True when the page cap — not meta.last_page — ended the walk; the list is then partial. */
    truncated: boolean;
}

/**
 * Read page 1, then on to `meta.last_page`, never past `maxPages`. A page
 * that comes back malformed (no data, no meta) ends the walk after that
 * request — reading it as "one page" is the only bounded interpretation.
 */
export async function walkEntryPages(
    fetchPage: (page: number) => Promise<Paginated<ShiftProductionEntry> | null | undefined>,
    maxPages: number = CORRECTION_READ_MAX_PAGES,
): Promise<EntryWalk> {
    const entries: ShiftProductionEntry[] = [];
    let page = 1;
    let lastPage = 1;

    do {
        const response = await fetchPage(page);
        entries.push(...(response?.data ?? []));
        lastPage = response?.meta?.last_page ?? 1;
        page += 1;
    } while (page <= lastPage && page <= maxPages);

    return { entries, truncated: page <= lastPage };
}

export interface CorrectionReads {
    /** The `awaiting_correction=1` read — undefined while loading or refused. */
    awaiting: ShiftProductionEntry[] | undefined;
    /** The `correctable=1` read — undefined while loading or refused. */
    correctable: ShiftProductionEntry[] | undefined;
    /** What the Completed Today table is already showing — those need no second listing. */
    completedToday: ShiftProductionEntry[];
}

export interface CorrectionLists {
    /** Sent back by quality and not yet re-submitted — the amber panel, in server order. */
    awaitingCorrection: ShiftProductionEntry[];
    /**
     * Still amendable by the floor, not sent back, and not on Completed
     * Today — the "completed earlier and still correctable" line (the
     * night shift's paperwork at 06:45 files under yesterday's date).
     */
    correctableEarlier: ShiftProductionEntry[];
}

/**
 * The two lists the page renders, from the two server reads. The predicates
 * are the entry's OWN fields (isAwaitingCorrection / canAmendCompletion) —
 * the same rule the server applied, kept here as the parity guard the
 * module docblock describes; the sent-back exclusion reads the entry's own
 * flag too, so a batch never appears in both lists.
 */
export function correctionLists(reads: CorrectionReads): CorrectionLists {
    const shownToday = new Set(reads.completedToday.map((entry) => entry.id));

    return {
        awaitingCorrection: (reads.awaiting ?? []).filter(isAwaitingCorrection),
        correctableEarlier: (reads.correctable ?? []).filter(
            (entry) => canAmendCompletion(entry) && !isAwaitingCorrection(entry) && !shownToday.has(entry.id),
        ),
    };
}
