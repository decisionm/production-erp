import { describe, expect, it } from 'vitest';
import {
    AGENT_STALE_AFTER_MS,
    agentLiveness,
    agentLivenessLabel,
    canRequestSyncNow,
    SYNC_NOW_CONFIRM,
    syncNowMessage,
} from './syncNow';
import type { User } from '@/features/auth/types';
import type { SyncNowResult } from './types';

/**
 * "Sync Now" and the agent-liveness light (DEC-20260825-002), pinned:
 *
 *  - who the page draws the button for — the same two-part test the server
 *    enforces, and never a substitute for it;
 *  - the four liveness states kept apart, "never" and "unavailable"
 *    especially, since one is a measurement and the other is its absence;
 *  - the copy after a press never claims anything reached Tally, and says
 *    out loud when the factory PC is not there to take it.
 */

const user = (permissions: string[]): User => ({
    id: 1,
    name: 'A person',
    email: 'a@example.test',
    is_active: true,
    permissions,
});

const result = (over: Partial<SyncNowResult> = {}): SyncNowResult => ({
    outcome: 'released',
    requested_at: '2026-08-25T10:00:00+00:00',
    released: 1,
    released_entry_ids: [7],
    already_queued: 0,
    with_agent: 0,
    queued_total: 1,
    agent: { last_checked_at: '2026-08-25T09:59:30+00:00' },
    ...over,
});

const NOW = Date.parse('2026-08-25T10:00:00Z');

describe('canRequestSyncNow', () => {
    it('lets an Owner/Accounts login who runs the queue press it', () => {
        expect(canRequestSyncNow(user(['tally-sync.manage', 'finance.manage']))).toBe(true);
        // FC-06's pair is view OR manage — the view half is still
        // Owner/Accounts standing, exactly as it is for purchase rates.
        expect(canRequestSyncNow(user(['tally-sync.manage', 'finance.view']))).toBe(true);
    });

    it('refuses everyone else', () => {
        // Whoever runs the sync queue day to day but is not Owner/Accounts
        // keeps Resync, Dismiss and per-voucher Release — this one press is
        // the owner's or Accounts'.
        expect(canRequestSyncNow(user(['tally-sync.manage']))).toBe(false);
        // Owner/Accounts standing without the queue's own manage permission
        // is refused by the module gate on the server; the page agrees.
        expect(canRequestSyncNow(user(['finance.manage']))).toBe(false);
        expect(canRequestSyncNow(user(['tally-sync.view', 'finance.manage']))).toBe(false);
        expect(canRequestSyncNow(user(['production.manage']))).toBe(false);
        expect(canRequestSyncNow(user([]))).toBe(false);
    });

    it('refuses a login that is not there, or carries no permissions at all', () => {
        expect(canRequestSyncNow(null)).toBe(false);
        expect(canRequestSyncNow({ id: 1, name: 'x', email: 'x@y.z', is_active: true })).toBe(false);
    });
});

describe('SYNC_NOW_CONFIRM', () => {
    it('says what the press does, and what it does not read', () => {
        expect(SYNC_NOW_CONFIRM.body).toContain('next check');
        expect(SYNC_NOW_CONFIRM.body).toContain('does not read anything from Tally');
    });

    it('warns that a running shift is sent as it stands', () => {
        // The consequence nobody can guess and would otherwise find in
        // Tally: sending mid-shift closes the merge window, and later
        // approvals land in a follow-up voucher (DEC-20260807-011).
        expect(SYNC_NOW_CONFIRM.body).toContain('follow-up voucher');
    });
});

describe('agentLiveness', () => {
    it('is fresh inside the five-minute window and stale past it', () => {
        const at = (agoMs: number) => new Date(NOW - agoMs).toISOString();

        expect(agentLiveness(at(0), NOW).state).toBe('fresh');
        expect(agentLiveness(at(90_000), NOW).state).toBe('fresh');
        // Exactly on the boundary is still fresh — the light goes amber
        // only once the agent is demonstrably past it.
        expect(agentLiveness(at(AGENT_STALE_AFTER_MS), NOW).state).toBe('fresh');
        expect(agentLiveness(at(AGENT_STALE_AFTER_MS + 1000), NOW).state).toBe('stale');
        expect(agentLiveness(at(3 * 60 * 60 * 1000), NOW).state).toBe('stale');
    });

    it('keeps "never checked in" and "could not find out" apart', () => {
        // Two different claims. One says the agent has never authenticated;
        // the other says this page did not manage to read the summary and
        // knows nothing either way.
        expect(agentLiveness(null, NOW).state).toBe('never');
        expect(agentLiveness(undefined, NOW).state).toBe('never');
        expect(agentLiveness('2026-08-25T09:59:00Z', NOW, false).state).toBe('unavailable');
        expect(agentLiveness(null, NOW, false).state).toBe('unavailable');
        // An unparseable stamp is not a measurement either.
        expect(agentLiveness('not a time', NOW).state).toBe('unavailable');
    });

    it('reads a clock-skewed future check-in as fresh, never as a negative age', () => {
        const ahead = new Date(NOW + 120_000).toISOString();
        expect(agentLiveness(ahead, NOW)).toEqual({ state: 'fresh', label: 'just now' });
    });

    it('labels the age in the coarsest unit that is still true', () => {
        const at = (agoMs: number) => new Date(NOW - agoMs).toISOString();

        expect(agentLiveness(at(20_000), NOW).label).toBe('just now');
        expect(agentLiveness(at(4 * 60_000), NOW).label).toBe('4 min ago');
        expect(agentLiveness(at(90 * 60_000), NOW).label).toBe('1 hr ago');
        expect(agentLiveness(at(26 * 60 * 60_000), NOW).label).toBe('1 day ago');
        expect(agentLiveness(at(50 * 60 * 60_000), NOW).label).toBe('2 days ago');
    });

    it('has a header label for every state that never names a token', () => {
        const labels = [
            agentLivenessLabel(agentLiveness(new Date(NOW).toISOString(), NOW)),
            agentLivenessLabel(agentLiveness(new Date(NOW - 10 * 60_000).toISOString(), NOW)),
            agentLivenessLabel(agentLiveness(null, NOW)),
            agentLivenessLabel(agentLiveness(null, NOW, false)),
        ];

        expect(labels).toEqual([
            'Agent checked in just now',
            'Agent last checked in 10 min ago',
            'Agent has never checked in',
            'Agent status unknown',
        ]);
        for (const label of labels) {
            expect(label).not.toMatch(/tally-sync:|token/i);
        }
    });
});

describe('syncNowMessage', () => {
    const live = agentLiveness(new Date(NOW - 30_000).toISOString(), NOW);
    const quiet = agentLiveness(new Date(NOW - 60 * 60_000).toISOString(), NOW);
    const never = agentLiveness(null, NOW);
    const unknown = agentLiveness(null, NOW, false);

    it('never says the vouchers were sent, posted or synced', () => {
        // Nothing has reached Tally when the request returns: the agent
        // posts, and the Status column reports it.
        const messages = [
            syncNowMessage(result(), live),
            syncNowMessage(result({ outcome: 'already_queued', released: 0, already_queued: 2, queued_total: 2 }), live),
            syncNowMessage(result({ outcome: 'nothing_queued', released: 0, queued_total: 0 }), live),
            syncNowMessage(result(), never),
        ];

        for (const message of messages) {
            expect(message.text).not.toMatch(/\b(sent|posted|synced|done|complete)\b/i);
        }
    });

    it('reports a release as requested, with what is now waiting', () => {
        const message = syncNowMessage(result({ released: 2, already_queued: 1, with_agent: 1, queued_total: 4 }), live);

        expect(message.tone).toBe('success');
        expect(message.text).toContain('Requested');
        expect(message.text).toContain('2 vouchers released');
        expect(message.text).toContain('3 waiting to be collected');
        expect(message.text).toContain('1 already with the agent');
        expect(message.text).toContain('about 90 seconds');
    });

    it('says plainly when nothing was being held back', () => {
        const message = syncNowMessage(
            result({ outcome: 'already_queued', released: 0, released_entry_ids: [], already_queued: 3, queued_total: 3 }),
            live,
        );

        expect(message.text).toContain('Nothing was being held back');
        expect(message.text).toContain('3 waiting to be collected');
    });

    it('says the queue is empty rather than dressing it as a failure', () => {
        const message = syncNowMessage(
            result({ outcome: 'nothing_queued', released: 0, released_entry_ids: [], queued_total: 0 }),
            live,
        );

        expect(message.tone).toBe('info');
        expect(message.text).toBe('Nothing is queued — there are no vouchers waiting to go to Tally.');
    });

    it('warns, and says the request waits, when the agent is not there', () => {
        // The case that matters most: an accountant told "on its next
        // check" about a machine switched off after lunch would go looking
        // in Tally for a voucher that is not going to be there.
        const stale = syncNowMessage(result(), quiet);
        expect(stale.tone).toBe('warning');
        expect(stale.text).toContain('last checked in 1 hr ago');
        expect(stale.text).toContain('nothing will post until it checks in again');
        expect(stale.text).toContain('The request is saved and waits');

        const none = syncNowMessage(result(), never);
        expect(none.tone).toBe('warning');
        expect(none.text).toContain('No sync agent has ever checked in');
        expect(none.text).toContain('The request is saved and waits');
    });

    it('admits it does not know when the agent could not be read', () => {
        const message = syncNowMessage(result(), unknown);

        // Not a warning and not an all-clear: an unmeasured thing.
        expect(message.tone).toBe('info');
        expect(message.text).toContain('could not be read');
        expect(message.text).toContain('watch the Status column');
    });

    it('gets the singular right for one voucher', () => {
        expect(syncNowMessage(result({ released: 1, queued_total: 1 }), live).text).toContain('1 voucher released');
    });
});
