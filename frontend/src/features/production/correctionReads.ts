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
 * WHY "TODAY" IS NO LONGER SUBTRACTED HERE (post-review fix, 03-Sep-2026,
 * correctable-filters Task 2). `correctableEarlier` used to drop whatever id
 * the Completed Today read already held (`shownToday`), so the two sections
 * never showed the same row twice. That was safe while `correctable=1` came
 * back as ONE unpaginated walk of up to 500 rows (§walkEntryPages) —
 * subtracting today's handful still left the rest visible. It stopped being
 * safe the moment that read became a real 25-row PAGE: today's batches
 * satisfy `correctable=1` and sort first under the default `newest`, so on a
 * busy day they could fill page 1 entirely, `shownToday` would drop every
 * row on it, and the whole section — heading, control row and pager
 * together, since all three are gated on this list being non-empty — would
 * read as empty with a real earlier-days backlog sitting unreachable on
 * page 2. The disjointness this existed for is now enforced AT THE SOURCE
 * instead: `correctableQuery()` (correctableFilters.ts) sends `date_to`
 * capped to the day before the page's own production day, so the server
 * never returns a today-dated row here in the first place. This module no
 * longer needs to know what Completed Today is showing.
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
}

export interface CorrectionLists {
    /** Sent back by quality and not yet re-submitted — the amber panel, in server order. */
    awaitingCorrection: ShiftProductionEntry[];
    /**
     * Still amendable by the floor and not sent back — the "completed
     * earlier and still correctable" line. Date-disjoint from Completed
     * Today by construction of the `correctable` READ itself
     * (correctableQuery's `date_to` cap, correctableFilters.ts), not by
     * anything filtered here — see the module docblock.
     */
    correctableEarlier: ShiftProductionEntry[];
}

/**
 * The two lists the page renders, from the two server reads. The predicates
 * are the entry's OWN fields (isAwaitingCorrection / canAmendCompletion) —
 * the same rule the server applied, kept here as the parity guard the
 * module docblock describes; the sent-back exclusion reads the entry's own
 * flag too, so a batch never appears in both lists. Does NOT know or care
 * what Completed Today is showing — that boundary is the caller's query,
 * not this function's job (see "WHY 'TODAY' IS NO LONGER SUBTRACTED HERE"
 * above).
 */
export function correctionLists(reads: CorrectionReads): CorrectionLists {
    return {
        awaitingCorrection: (reads.awaiting ?? []).filter(isAwaitingCorrection),
        correctableEarlier: (reads.correctable ?? []).filter(
            (entry) => canAmendCompletion(entry) && !isAwaitingCorrection(entry),
        ),
    };
}
