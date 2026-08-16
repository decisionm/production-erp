/**
 * Executable contract for decideAction — the agent's double-post guard.
 *
 * WHY THIS FILE EXISTS. This is the most safety-critical decision the agent
 * makes (post, ack, report, or refuse) and until now it had no test at all:
 * sync.ts pulls in axios and electron-store, so requiring it from a test
 * downloads an Electron binary. decideAction is a pure read of two plain
 * objects, so it now lives in its own module whose only imports are types —
 * dist/postDecision.js requires with zero node_modules, asserted below.
 *
 * WHAT THIS GUARDS. Two live incidents, one per direction:
 *
 *   - Posting a voucher twice into the factory's real books. The `posted`
 *     record must beat everything, including a cleared stamp.
 *   - Issue #168: a Tally REJECTION during a deploy's maintenance window.
 *     Every cloud route answers 503, the failure report is swallowed, and the
 *     entry sits pending with a delivered_at stamp the agent cannot explain.
 *     Before the fix it was refused on every later cycle, forever, silently —
 *     never reaching the dashboard's failed list, so nobody knew to retry it.
 *
 * Nothing here contacts Tally or the cloud. Pure function, plain objects.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { decideAction } = require('../dist/postDecision.js');

const entry = (overrides = {}) => ({
    id: 42,
    tally_voucher_type: 'Stock Journal',
    delivered_at: '2026-08-16T10:00:00.000Z',
    payload: { voucher_number: 'SJ-20260816-S1' },
    ...overrides,
});

const rec = (outcome, extra = {}) => ({
    entryId: 42,
    outcome,
    voucherNumber: 'SJ-20260816-S1',
    at: '2026-08-16T10:00:00.000Z',
    ...extra,
});

test('the module is requirable with no runtime dependency', () => {
    const heavy = Object.keys(require.cache).filter((k) => k.includes('node_modules'));
    assert.deepEqual(heavy, [], 'decideAction must not drag in electron or axios');
});

test('Tally confirmed the import — ack, never rebuild the voucher', () => {
    assert.equal(decideAction(entry(), rec('posted')).kind, 'ack-only');
});

test('a posted record beats a cleared stamp — Tally is a harder fact than the dashboard', () => {
    assert.equal(decideAction(entry({ delivered_at: null }), rec('posted')).kind, 'ack-only');
});

test('a human hit Retry — post it', () => {
    assert.equal(decideAction(entry({ delivered_at: null }), undefined).kind, 'post');
});

test('fresh entry, first delivery — post it', () => {
    assert.equal(decideAction(entry({ delivered_at: null }), undefined).kind, 'post');
});

test('sent and never answered — refuse both ways, a human must look in Tally', () => {
    const action = decideAction(entry(), rec('unverified'));
    assert.equal(action.kind, 'refuse');
    assert.match(action.reason, /never got an answer/);
});

test('delivered before, no local memory — refuse rather than guess', () => {
    const action = decideAction(entry(), undefined);
    assert.equal(action.kind, 'refuse');
    assert.match(action.reason, /no record of what happened/);
});

// ---- issue #168 ----

test('#168: a remembered rejection is REPORTED again, not refused forever', () => {
    const action = decideAction(entry(), rec('rejected', { message: 'Ledger not found' }));
    assert.equal(action.kind, 'report-only', 'must not be refused — nothing was created in Tally');
    assert.equal(action.record.message, 'Ledger not found', 'reports the reason Tally actually gave');
});

test('#168: the rejection path never posts — Tally already said no', () => {
    const action = decideAction(entry(), rec('rejected', { message: 'x' }));
    assert.notEqual(action.kind, 'post');
});

test('#168: a human Retry still outranks a remembered rejection', () => {
    const action = decideAction(entry({ delivered_at: null }), rec('rejected', { message: 'x' }));
    assert.equal(action.kind, 'post', 'a cleared stamp is a person re-authorising it');
});

test('#168: a rejection with no stored message still reports rather than refusing', () => {
    assert.equal(decideAction(entry(), rec('rejected')).kind, 'report-only');
});
