/**
 * The double-post guard, as a pure function.
 *
 * WHY ITS OWN MODULE. This is the most safety-critical decision the agent
 * makes — post, ack, report, or refuse — and it was the one piece with no
 * test, because sync.ts pulls in axios and electron-store and requiring it
 * from a test downloads an Electron binary. Everything here is a pure read of
 * two plain objects, and both imports are TYPE-only (erased at compile time),
 * so dist/postDecision.js can be required with no runtime dependency at all.
 * Same reasoning as the voucherBuilders tree.
 */
import type { TallySyncEntry } from './cloudApi';
import type * as journal from './postJournal';

export type SyncAction =
    | { kind: 'post' }
    | { kind: 'ack-only'; record: journal.PostRecord }
    | { kind: 'report-only'; record: journal.PostRecord }
    | { kind: 'refuse'; reason: string };

/**
 * What to do with one queued entry — the whole double-post guard, as a pure
 * function of what the cloud sent us and what this machine remembers doing.
 *
 * Five cases:
 *
 *   1. Tally confirmed the import → only the acknowledgement is outstanding.
 *      Ack it; never rebuild the voucher. This one beats everything else,
 *      including a cleared stamp: Tally having said "created" is a harder
 *      fact than any judgement made from the dashboard.
 *   2. We sent it and never heard back, OR the cloud has handed it to an
 *      agent before and we have no memory of it (a reinstall, a wiped
 *      profile, a second agent) → we cannot tell "never posted" from
 *      "posted, answer lost". Refuse both ways: posting risks a duplicate in
 *      the live books, acking risks marking a voucher synced that Tally
 *      never received.
 *   3. Either of those, but the entry arrives with delivered_at cleared →
 *      somebody hit Retry on the dashboard, which is a person saying "I have
 *      looked in Tally and this voucher is not there". That is the only
 *      thing that can resolve an ambiguity this agent cannot, and it is why
 *      Retry nulls the stamp. Post it. (A stale "unverified" note needs no
 *      cleanup: the post overwrites it, and a clean rejection takes the
 *      entry out of the pending queue, where prune() drops it.)
 *   4. Tally answered and REJECTED it, and the cloud was not reachable to be
 *      told (a deploy's maintenance window answers 503 on every route) →
 *      nothing was created, so there is no ambiguity to protect: say it
 *      again. Without this the entry sits pending with a delivered_at stamp
 *      this agent cannot explain, is refused by case 2 forever, and never
 *      reaches the dashboard's failed list, so nobody knows to retry it
 *      (issue #168).
 *   5. Fresh entry, first delivery → post it.
 */
export function decideAction(entry: TallySyncEntry, remembered: journal.PostRecord | undefined): SyncAction {
    if (remembered?.outcome === 'posted') {
        return { kind: 'ack-only', record: remembered };
    }

    // A cleared stamp is a human's re-authorisation and outranks this
    // agent's "I don't know" — without this, one timed-out post (or one
    // mistyped tallyHost, which times out for every voucher in the queue)
    // strands entries until someone deletes post-journal.json by hand.
    if (entry.delivered_at === null) {
        return { kind: 'post' };
    }

    // Tally said no, so nothing is in the books and re-reporting cannot
    // create a duplicate. Placed BELOW the cleared-stamp check on purpose: a
    // human's Retry outranks this and re-posts.
    if (remembered?.outcome === 'rejected') {
        return { kind: 'report-only', record: remembered };
    }

    if (remembered?.outcome === 'unverified') {
        return {
            kind: 'refuse',
            reason: `this agent sent the voucher to Tally at ${remembered.at} and never got an answer, so it may already be in the books`,
        };
    }

    return {
        kind: 'refuse',
        reason: `the cloud already handed this voucher to an agent at ${entry.delivered_at} and this agent has no record of what happened to it`,
    };
}
