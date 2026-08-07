/**
 * The one gate every outbound Tally request passes through.
 *
 * Tally's XML gateway (port 9000) has no queueing discipline and no auth; under
 * concurrent collection exports it degrades and can crash outright. Field fact,
 * 07 Aug 2026: a SINGLE full-catalogue stock-summary export crashed the live
 * Tally on its own — concurrency on top of heavy reads only makes that worse.
 *
 * Invariant: never more than ONE outbound request to Tally in flight from this
 * process, ever. Everything that talks to the Tally port wraps itself in
 * withTallyGate() — voucher posts, masters exports, the company list, the ping,
 * and every stock-summary chunk. Requests queue here, in the agent, where
 * waiting is free — never inside Tally, where it isn't.
 *
 * Deliberately dependency-free (no electron, no config, no logger), like
 * masters.ts and for the same reason: the tally/ layer stays runnable and
 * testable standalone.
 */

let tail: Promise<unknown> = Promise.resolve();

export function withTallyGate<T>(fn: () => Promise<T>): Promise<T> {
    // Chain onto whatever is currently in flight, whether it succeeds or fails
    // — a failed request must never wedge the gate shut.
    const run = tail.then(fn, fn);
    tail = run.catch(() => undefined);
    return run;
}
