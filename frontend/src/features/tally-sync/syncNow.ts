import type { User } from '@/features/auth/types';
import { hasManageAccess, hasModuleAccess } from '@/features/auth/permissions';
import type { SyncNowResult } from './types';

/**
 * The Tally Sync page's "Sync Now" and the agent-liveness line beside
 * Refresh — everything about them that is a function of its arguments, so
 * it can be tested without a DOM (syncNow.test.ts).
 *
 * Nothing here calls the API. The one network call this feature adds is
 * requestTallySyncNow() in api.ts, and nothing on the page's Refresh path
 * touches it (api.test.ts pins that).
 */

// ---- who may press it -------------------------------------------------------

/**
 * Whether THIS login may press Sync Now — DEC-20260825-002, "Only an
 * Owner/Accounts permission may press it".
 *
 * The same two-part test the server enforces (SyncNowAuthority +
 * the route group's module gate): the queue's own manage permission, AND
 * FC-06's Owner/Accounts pair, which in this codebase is finance.view /
 * finance.manage — the permission purchase rates and supplier identity are
 * already gated on.
 *
 * A COURTESY, NEVER THE GATE. This only decides whether the button is
 * drawn. The server 403s the request regardless of what the page shows, so
 * a stale page, a hand-built request or another API client is refused by
 * the backend and not by this function.
 */
export function canRequestSyncNow(user: User | null): boolean {
    return hasManageAccess(user, 'tally-sync') && hasModuleAccess(user, 'finance');
}

/**
 * The confirmation — required because pressing this is an outward business
 * action: it can put vouchers into the accountant's live books minutes later.
 *
 * The body carries the one consequence a person genuinely cannot guess and
 * would otherwise discover in Tally. A shift voucher is held so the whole
 * shift's approvals can merge into ONE voucher (DEC-20260807-011); sending
 * it mid-shift closes that merge window, and every approval after it lands
 * in a -2 / -3 follow-up voucher.
 */
export const SYNC_NOW_CONFIRM = {
    title: 'Release queued vouchers?',
    // Two facts, no paragraph. The first is the one this dialog used to
    // leave to inference: the press is QUEUE-WIDE. The server frees every
    // voucher the shift gate is holding (TallySyncService::requestSyncNow),
    // which is not the same set as the rows on screen — someone who has
    // filtered to one day, or to "held only", is still releasing the rest.
    body: 'Releases every held voucher in the queue, not only the rows your filters show. '
        + 'A running shift is released as it stands; later approvals create a follow-up voucher.',
    ok: 'Release now',
    cancel: 'Not yet',
} as const;

/**
 * The per-voucher form of the same press (DEC-20260807-011's accountant
 * override), behind the same kind of pause.
 *
 * "Release now" sits in the row's action strip and in the drawer, one
 * click from View and from Resync, and it used to fire on that click: a
 * mis-tap put a running shift's voucher on the next poll with nothing to
 * stop it and no way back. Naming the voucher is the whole point of the
 * dialog — it is what tells someone they grabbed the wrong row.
 *
 * The consequence is the queue-wide one, minus the queue: this shift's
 * voucher goes as it stands, and approvals after it land in a follow-up.
 */
export const RELEASE_ONE_CONFIRM = {
    title: (voucher: string) => `Release ${voucher} now?`,
    body: "This shift's voucher goes on the agent's next check; later approvals create a follow-up voucher.",
    ok: 'Release now',
    cancel: 'Not yet',
} as const;

// ---- what to say afterwards -------------------------------------------------

export interface SyncNowMessage {
    tone: 'success' | 'info' | 'warning';
    text: string;
}

/**
 * The honest sentence for what the server just answered.
 *
 * IT NEVER SAYS "DONE", "SENT" OR "SYNCED". Nothing has reached Tally when
 * this returns: the request frees vouchers for the agent's NEXT poll, and
 * only the agent can post them. The queue's own Status column and "Last
 * activity" are what report a collection and an acceptance, because those
 * are the only two places that read what actually happened.
 *
 * When the agent has not checked in recently the copy says so rather than
 * implying a post is imminent — an accountant who reads "on its next check"
 * about a machine that has been off since lunch has been misled, and would
 * go looking in Tally for a voucher that is not there.
 */
export function syncNowMessage(result: SyncNowResult, liveness: AgentLiveness): SyncNowMessage {
    const waiting = agentIsWaiting(liveness.state);
    // Neither a warning nor an all-clear when the agent could not be read:
    // a green tick over an unmeasured thing is the same lie as a red one.
    const tone: SyncNowMessage['tone'] = waiting ? 'warning' : liveness.state === 'unavailable' ? 'info' : 'success';

    if (result.outcome === 'nothing_queued') {
        return { tone: 'info', text: 'No vouchers queued.' };
    }

    if (result.outcome === 'already_queued') {
        return {
            tone: waiting ? 'warning' : 'info',
            text: sentence(['Nothing held back.', describeQueued(result), collectionClause(liveness)]),
        };
    }

    return {
        tone,
        text: sentence([`Requested — ${result.released} released.`, describeQueued(result), collectionClause(liveness)]),
    };
}

/** The non-empty fragments, one space apart. */
function sentence(parts: string[]): string {
    return parts.filter((part) => part !== '').join(' ');
}

/** What is now waiting, and what is already in the agent's hands. */
function describeQueued(result: SyncNowResult): string {
    const parts: string[] = [];

    const waiting = result.released + result.already_queued;
    if (waiting > 0) parts.push(`${waiting} waiting`);
    if (result.with_agent > 0) parts.push(`${result.with_agent} with agent`);

    return parts.length > 0 ? `${parts.join(', ')}.` : '';
}

/** Whether the factory PC is there to take them — and if not, that it waits. */
function collectionClause(liveness: AgentLiveness): string {
    switch (liveness.state) {
        case 'fresh':
            return 'Agent live.';
        case 'stale':
            return `Agent offline (last checked in ${liveness.label}) — request waits.`;
        case 'never':
            return 'Agent has never run — request waits.';
        case 'unavailable':
            return 'Agent status unknown.';
    }
}

/** Neither fresh nor unknown-good: the request will sit until the agent returns. */
function agentIsWaiting(state: AgentLivenessState): boolean {
    return state === 'stale' || state === 'never';
}

// ---- how alive the agent is -------------------------------------------------

export type AgentLivenessState = 'fresh' | 'stale' | 'never' | 'unavailable';

export interface AgentLiveness {
    state: AgentLivenessState;
    /** A short relative phrase ("4 min ago") — empty for never/unavailable. */
    label: string;
}

/**
 * How long the agent may be silent before the light goes amber.
 *
 * The agent polls every ~90 seconds, so five minutes is roughly three
 * missed polls — long enough that a slow network or one restarted service
 * does not cry wolf, short enough that a factory PC switched off after
 * lunch is noticed the same afternoon.
 */
export const AGENT_STALE_AFTER_MS = 5 * 60 * 1000;

/**
 * The agent's freshness from its last CHECK-IN (summary.agent.last_checked_at
 * — the newest last_used_at across tokens PROVISIONED as the agent;
 * deliberately stricter than "may poll", so a wildcard client token cannot
 * light this green while the factory PC is off).
 *
 * FOUR STATES, KEPT APART, because they are four different facts and one of
 * them used to be reported as another:
 *
 *   fresh        checked in within the last five minutes
 *   stale        checked in, but not lately — the machine may be off
 *   never        no agent has EVER checked in (a fresh install, a revoked
 *                token) — not the same claim as "it went quiet"
 *   unavailable  this page could not read the summary at all; it does not
 *                know, and must not render a green light or a red one over
 *                a measurement it never made
 *
 * `now` is an argument so the boundary is testable rather than a function
 * of when the test happens to run. A future timestamp (clock skew between
 * the factory PC and the server) reads as fresh, not as a negative age.
 *
 * Says nothing about WHICH token or what it may do — a timestamp is the
 * whole of what reaches this page.
 */
export function agentLiveness(
    lastCheckedAt: string | null | undefined,
    now: number,
    available = true,
): AgentLiveness {
    if (!available) return { state: 'unavailable', label: '' };
    if (!lastCheckedAt) return { state: 'never', label: '' };

    const checked = new Date(lastCheckedAt).getTime();
    if (Number.isNaN(checked)) return { state: 'unavailable', label: '' };

    const age = now - checked;

    return {
        state: age > AGENT_STALE_AFTER_MS ? 'stale' : 'fresh',
        label: relativeAge(age),
    };
}

/** The one-line label beside Refresh — compact on purpose, it sits in a header. */
export function agentLivenessLabel(liveness: AgentLiveness): string {
    switch (liveness.state) {
        case 'fresh':
            return `Agent checked in ${liveness.label}`;
        case 'stale':
            return `Agent last checked in ${liveness.label}`;
        case 'never':
            return 'Agent has never run';
        case 'unavailable':
            return 'Agent status unknown';
    }
}

/** How long ago, in the coarsest unit that is still true. Never negative. */
function relativeAge(ms: number): string {
    const seconds = Math.max(0, Math.round(ms / 1000));
    if (seconds < 60) return 'just now';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hr ago`;

    const days = Math.floor(hours / 24);

    return `${days} day${days === 1 ? '' : 's'} ago`;
}
