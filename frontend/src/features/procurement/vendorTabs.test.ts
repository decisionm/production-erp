import { describe, expect, it } from 'vitest';
import { vendorActiveTab, vendorTabsFor } from './vendorTabs';

const PROCUREMENT_ONLY = { canSeeMaster: true, canReviewTally: false };
const FINANCE_ONLY = { canSeeMaster: false, canReviewTally: true };
const BOTH = { canSeeMaster: true, canReviewTally: true };
const NEITHER = { canSeeMaster: false, canReviewTally: false };

describe('which vendor surfaces a login is offered', () => {
    it('offers a procurement-only login the master and nothing else', () => {
        expect(vendorTabsFor(PROCUREMENT_ONLY)).toEqual(['master']);
    });

    // The regression this function exists to stop. Folding "Tally Vendor
    // Review" into the Vendors page must not cost a finance-only Accounts
    // login its only path to the review — the API still grants it.
    it('offers a finance-only login the review, which is what it reached before', () => {
        expect(vendorTabsFor(FINANCE_ONLY)).toEqual(['tally-review']);
    });

    it('offers a login holding both an actual choice, master first', () => {
        expect(vendorTabsFor(BOTH)).toEqual(['master', 'tally-review']);
    });

    it('offers a login holding neither nothing at all', () => {
        expect(vendorTabsFor(NEITHER)).toEqual([]);
        expect(vendorActiveTab(null, NEITHER)).toBeNull();
    });
});

describe('which vendor tab opens', () => {
    it('honours the retired review URL for a login that may review', () => {
        expect(vendorActiveTab('tally-review', BOTH)).toBe('tally-review');
        expect(vendorActiveTab('tally-review', FINANCE_ONLY)).toBe('tally-review');
    });

    // A deep link is not a permission. Asking for the review tab as a
    // procurement-only login lands on the master, not on a pane whose every
    // request the server refuses.
    it('refuses to honour a tab the login cannot reach, and falls back to one it can', () => {
        expect(vendorActiveTab('tally-review', PROCUREMENT_ONLY)).toBe('master');
        expect(vendorActiveTab('master', FINANCE_ONLY)).toBe('tally-review');
    });

    it('opens the master by default, and the review when that is all there is', () => {
        expect(vendorActiveTab(null, BOTH)).toBe('master');
        expect(vendorActiveTab(null, PROCUREMENT_ONLY)).toBe('master');
        expect(vendorActiveTab(null, FINANCE_ONLY)).toBe('tally-review');
    });

    it('ignores a tab name nobody defined', () => {
        expect(vendorActiveTab('nonsense', BOTH)).toBe('master');
    });
});
