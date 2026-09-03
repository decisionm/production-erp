/**
 * WAITING ON YOU — the strip at the top of the dashboard, and the whole of
 * chapter 1 §1: "Every login needs a dashboard for its own job… Each number
 * must open the exact filtered work queue it counted."
 *
 * Three rules hold this together, and each one is pinned by a test next door.
 *
 * 1. MEMBERSHIP IS PERMISSION, NEVER ROLE NAME. Roles are created and granted
 *    through the Roles UI on the live instance — PermissionSeeder says so
 *    three separate times — so there are role names this code has never seen
 *    and never will. A tile appears when its COUNT was supplied, and a count
 *    is supplied only when the server judged the reader may see the module
 *    (DashboardService gates every block on `<module>.view`/`.manage`). A
 *    login whose role nobody here has heard of therefore gets everything its
 *    permissions allow, which is at worst today's page and never a blank one.
 *
 * 2. ROLE NAME ONLY ORDERS. A Store login should not have to read past four
 *    tiles that are not its job to find the two that are — but ordering is
 *    all a name may do. An unrecognised name gets DEFAULT_ORDER unchanged.
 *
 * 3. EVERY TILE IS A DESTINATION AND ITS NUMBER IS THAT DESTINATION'S ROW
 *    COUNT. Not "roughly the same thing" — the same query. Where a screen's
 *    default view is a narrowing the server does not apply on a bare path
 *    (the store's queue defaults to submitted + partially_issued), the LINK
 *    carries that narrowing so the rows and the number cannot disagree.
 *
 * No prose ships in the strip itself: a figure, two or three words, and
 * colour. The floor does not read paragraphs — a standing rule this file is
 * not allowed to be the exception to.
 */

/** Red acts now, amber is waiting on somebody else, grey is at rest. */
export type Tone = 'act' | 'wait' | 'calm';

export interface Tile {
    key: string;
    /** Two or three words. Never a sentence. */
    label: string;
    count: number;
    /** Where the number's own rows are — including any filter the count assumed. */
    to: string;
    tone: Tone;
}

/**
 * A tile's definition, before it knows its number. `tone` is what the tile
 * looks like when the count is ABOVE zero; at zero every tile is calm,
 * because a nought nobody has to act on should not be shouting in red.
 */
interface TileSpec {
    key: string;
    label: string;
    to: string;
    tone: Exclude<Tone, 'calm'>;
}

/**
 * THE CATALOGUE. Order here is the order a login sees when nothing about its
 * role is recognised — the store's own work first, because that is the queue
 * chapter 1 spells out in full, then the approval chain in the order the
 * paper actually moves, then the rest.
 *
 * `to` is the destination's DEFAULT VIEW. The store issue queue's is a status
 * list rather than a bare path (frontend `queueStatusFilter('open')`), so it
 * is spelled out — tapping the tile must not land on a longer list than the
 * number promised.
 */
const CATALOGUE: readonly TileSpec[] = [
    {
        key: 'issue',
        label: 'To issue',
        // The canonical URL, not the retired /inventory/store-issue-queue that
        // still redirects here — a tile written this week should not be one of
        // the bookmarks that redirect exists for.
        to: '/inventory/store-production?tab=issues&status=open',
        tone: 'act',
    },
    {
        key: 'fulfil',
        label: 'Order lines',
        to: '/inventory/fulfilment',
        tone: 'act',
    },
    {
        key: 'pm',
        label: 'Batches to approve',
        to: '/production/approve-production',
        tone: 'act',
    },
    {
        key: 'accounts',
        label: 'Batches to sign',
        to: '/production/approve-production',
        tone: 'wait',
    },
    {
        key: 'requisitions',
        label: 'Requests to approve',
        to: '/procurement/purchase-requisitions',
        tone: 'act',
    },
    /*
     * NO "TO DISPATCH" TILE, AND IT IS NOT AN OVERSIGHT.
     *
     * The obvious figure for it is `sales.orders_awaiting_delivery`, which the
     * Office band already prints. But DeliveryService::pendingCount() counts
     * SALES ORDERS in confirmed/partially_delivered — a Delivery row has no
     * status, because one only ever represents stock that has already gone
     * out — so the rows behind that number are ORDERS. /sales/deliveries would
     * open a list of dispatches already made, and /sales/sales-orders cannot
     * express "confirmed or partially delivered" in its URL at all.
     *
     * A tile whose number and destination disagree is worse than no tile: it
     * teaches the floor that the figures do not mean anything. Sales' queue is
     * one of the five Q99 asks about, so this waits for that answer rather
     * than shipping a link that lands somewhere else.
     */
    {
        key: 'ncrs',
        label: 'Open NCRs',
        to: '/quality/ncrs',
        tone: 'wait',
    },
    {
        key: 'tally',
        label: 'Vouchers failed',
        to: '/tally-sync',
        tone: 'act',
    },
] as const;

export const DEFAULT_ORDER: readonly string[] = CATALOGUE.map((tile) => tile.key);

/**
 * Which tiles a named role wants at the front. Nothing here ADMITS a tile —
 * every key listed must still have arrived with a count, and a key missing
 * from a role's list is not hidden, only sorted after the ones named.
 *
 * The four names are the ones this codebase already checks by exact string
 * (PermissionSeeder, ShiftProductionEntryController). Everything else — every
 * role an administrator creates on live — falls through to DEFAULT_ORDER.
 */
const ROLE_FIRST: Readonly<Record<string, readonly string[]>> = {
    Store: ['issue', 'fulfil', 'requisitions'],
    'Plant Manager': ['pm', 'issue', 'fulfil'],
    Accounts: ['accounts', 'requisitions', 'tally'],
    Administrator: [],
};

/** The counts a page can offer. A key that is absent is one the reader may not see. */
export type Counts = Partial<Record<string, number>>;

/**
 * The strip, in the order this login should read it.
 *
 * A tile with no count is not rendered at all — absent means "not yours",
 * which is a different fact from zero and must not be drawn as one.
 */
export function waitingOnYou(counts: Counts, roleNames: readonly string[] = []): Tile[] {
    const preferred = roleNames.flatMap((name) => ROLE_FIRST[name] ?? []);

    const rank = (key: string): number => {
        const first = preferred.indexOf(key);
        return first === -1 ? preferred.length + DEFAULT_ORDER.indexOf(key) : first;
    };

    return CATALOGUE.filter((spec) => typeof counts[spec.key] === 'number')
        .map((spec): Tile => {
            const count = counts[spec.key] as number;
            return { key: spec.key, label: spec.label, count, to: spec.to, tone: count > 0 ? spec.tone : 'calm' };
        })
        .sort((a, b) => rank(a.key) - rank(b.key));
}
