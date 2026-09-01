/**
 * WHICH VENDOR SURFACES A LOGIN IS OFFERED, and which one opens first.
 *
 * Vendor used to be two menu entries — "Vendors" and "Tally Vendor Review" —
 * which read as two vendor masters. There is one master (DEC-20260825-003),
 * and the review is a tab of it. Folding two entries into one page is where a
 * gate gets lost, so the rule is pinned here as a pure function rather than
 * left implicit in JSX.
 *
 * TWO GATES, NOT ONE, because the two tabs are two different modules:
 *   master  module:procurement — the configured vendor master
 *   review  module:finance     — supplier identity, Owner/Accounts only (FC-06)
 *
 * The contract this must keep is the OLD refusal set: a login that reached
 * exactly one of these before must still reach exactly that one, and must
 * never be offered a tab whose API will refuse it.
 */
export type VendorTab = 'master' | 'tally-review';

export interface VendorTabAccess {
    canSeeMaster: boolean;
    canReviewTally: boolean;
}

/** The tabs to offer, in display order. Empty when the login reaches neither. */
export function vendorTabsFor({ canSeeMaster, canReviewTally }: VendorTabAccess): VendorTab[] {
    const tabs: VendorTab[] = [];
    if (canSeeMaster) tabs.push('master');
    if (canReviewTally) tabs.push('tally-review');
    return tabs;
}

/**
 * The tab that opens, given what the URL asked for (`?tab=`) and what the
 * login reaches. A request for a tab the login cannot reach is not honoured —
 * it falls back to the first tab they do reach, never to an empty page.
 */
export function vendorActiveTab(requested: string | null, access: VendorTabAccess): VendorTab | null {
    const tabs = vendorTabsFor(access);
    if (tabs.length === 0) return null;
    if (requested === 'tally-review' && access.canReviewTally) return 'tally-review';
    if (requested === 'master' && access.canSeeMaster) return 'master';
    return tabs[0];
}
