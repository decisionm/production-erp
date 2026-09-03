import { describe, expect, it } from 'vitest';
import { QUEUE_DEFAULT_STATUS, QUEUE_LIST_SPEC } from '@/features/material-flow/lists';
import { DEFAULT_ORDER, type Counts, waitingOnYou } from './waitingOnYou';

/**
 * The strip's three rules, each pinned so it cannot be undone quietly.
 *
 * The one that matters most is membership: a role name must never decide
 * whether a tile EXISTS, only where it sits. Roles are created on the live
 * instance through the Roles UI, so a dashboard that recognised names would
 * hand every such login a blank page — and it would look completely fine in
 * development, where the only roles are the four this repo seeds.
 */

const everything: Counts = {
    issue: 7,
    fulfil: 5,
    pm: 2,
    accounts: 1,
    requisitions: 4,
    deliveries: 3,
    ncrs: 1,
    tally: 0,
};

describe('membership comes from the counts, never from the role name', () => {
    it('gives a role this code has never heard of everything its permissions allowed', () => {
        const unknown = waitingOnYou(everything, ['Night Store Supervisor']);

        expect(unknown.map((tile) => tile.key)).toEqual([...DEFAULT_ORDER]);
    });

    it('gives a login with no role at all the same tiles', () => {
        expect(waitingOnYou(everything, []).map((t) => t.key)).toEqual([...DEFAULT_ORDER]);
    });

    it('drops a tile whose count was withheld, and keeps one that is zero', () => {
        // Absent is "not yours to see"; zero is "yours, and clear".
        const tiles = waitingOnYou({ issue: 0, fulfil: 5 });

        expect(tiles.map((t) => t.key)).toEqual(['issue', 'fulfil']);
        expect(waitingOnYou({ fulfil: 5 }).map((t) => t.key)).toEqual(['fulfil']);
    });

    it('shows nothing rather than an empty frame when no count arrived', () => {
        expect(waitingOnYou({})).toEqual([]);
    });
});

describe('a known role only reorders', () => {
    it("puts the store's own work first without removing anything", () => {
        const store = waitingOnYou(everything, ['Store']);

        expect(store.slice(0, 2).map((t) => t.key)).toEqual(['issue', 'fulfil']);
        expect([...store.map((t) => t.key)].sort()).toEqual([...DEFAULT_ORDER].sort());
    });

    it('puts the approval a Plant Manager owes at the front', () => {
        expect(waitingOnYou(everything, ['Plant Manager'])[0].key).toBe('pm');
    });

    it('leaves the Administrator reading the factory in the default order', () => {
        expect(waitingOnYou(everything, ['Administrator']).map((t) => t.key)).toEqual([...DEFAULT_ORDER]);
    });

    it('honours the first role when a login carries several', () => {
        expect(waitingOnYou(everything, ['Store', 'Accounts'])[0].key).toBe('issue');
    });
});

describe('colour is the only signal besides the number', () => {
    it('never shouts about a queue that is empty', () => {
        const tiles = waitingOnYou({ issue: 0, pm: 0, ncrs: 0 });

        expect(tiles.every((tile) => tile.tone === 'calm')).toBe(true);
    });

    it('marks work that is this reader’s own act red, and a wait amber', () => {
        const byKey = Object.fromEntries(waitingOnYou(everything).map((t) => [t.key, t.tone]));

        expect(byKey.issue).toBe('act');
        expect(byKey.deliveries).toBe('wait');
        // Zero outranks the spec's tone — nothing is owed, so nothing is red.
        expect(byKey.tally).toBe('calm');
    });
});

describe('every tile opens the rows it counted', () => {
    it('gives each tile a destination and a label short enough to read at a glance', () => {
        for (const tile of waitingOnYou(everything)) {
            expect(tile.to.startsWith('/')).toBe(true);
            // Two or three words. A sentence on a tile is a sentence nobody reads.
            expect(tile.label.split(' ').length).toBeLessThanOrEqual(3);
        }
    });

    it("carries the store queue's default narrowing in the link, not just in the count", () => {
        // The count is submitted + partially_issued. A bare path would land on
        // every request that ever reached the store — a longer list than the
        // number promised, which is the exact failure this rule exists for.
        const issue = waitingOnYou({ issue: 7 })[0];
        const query = new URLSearchParams(issue.to.split('?')[1]);

        expect(query.get('status')).toBe(QUEUE_DEFAULT_STATUS);
        expect(query.get('tab')).toBe('issues');
        // And the value is one the queue screen will actually accept.
        expect(QUEUE_LIST_SPEC.allowed?.status).toContain(query.get('status'));
    });
});
