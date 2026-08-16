/**
 * Offline contract for the pre-publish version-advance gate.
 *
 * Every case below uses LOCAL FIXTURE OBJECTS. Nothing here reads the network,
 * contacts the live ERP, or touches the published feed — the validator is a
 * pure function precisely so this suite can prove the dangerous cases (equal
 * version, downgrade, mismatched commit) without ever being near a publish.
 *
 * No version number in this file is asserted as durable truth about what is
 * published; they are fixtures chosen to exercise ordering.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    validateVersionAdvance,
    compareVersions,
    parseVersion,
    expectedFilename,
} = require('../scripts/assert-version-advance.js');

const COMMIT = 'abc1234';

/** A well-formed candidate at the given version, built from COMMIT. */
function candidate(version, overrides = {}) {
    return {
        version,
        commit: COMMIT,
        filename: expectedFilename(version),
        sha256: 'f'.repeat(64),
        built_at: '2026-08-14T00:00:00.000Z',
        ...overrides,
    };
}

function published(version) {
    return { version, commit: '0000000', filename: expectedFilename(version) };
}

/* ── The happy path ───────────────────────────────────────────────────── */

test('a genuine advance passes', () => {
    const r = validateVersionAdvance({
        candidate: candidate('0.3.6'),
        published: published('0.3.5'),
        commit: COMMIT,
    });
    assert.equal(r.from, '0.3.5');
    assert.equal(r.to, '0.3.6');
});

test('advances across minor and major boundaries pass', () => {
    for (const [from, to] of [
        ['0.3.9', '0.4.0'],
        ['0.9.9', '1.0.0'],
        ['1.2.3', '10.0.0'],
    ]) {
        assert.doesNotThrow(() =>
            validateVersionAdvance({
                candidate: candidate(to),
                published: published(from),
                commit: COMMIT,
            }),
        );
    }
});

/* ── The cases this gate exists for ───────────────────────────────────── */

test('an EQUAL version is refused', () => {
    assert.throws(
        () =>
            validateVersionAdvance({
                candidate: candidate('0.3.6'),
                published: published('0.3.6'),
                commit: COMMIT,
            }),
        /EQUALS the published version/,
    );
});

test('a DOWNGRADE is refused', () => {
    assert.throws(
        () =>
            validateVersionAdvance({
                candidate: candidate('0.3.4'),
                published: published('0.3.6'),
                commit: COMMIT,
            }),
        /LOWER than the published/,
    );
});

test('a downgrade that only looks higher lexically is still refused', () => {
    // '0.3.9' > '0.3.10' as strings; numerically it is a rollback.
    assert.throws(
        () =>
            validateVersionAdvance({
                candidate: candidate('0.3.9'),
                published: published('0.3.10'),
                commit: COMMIT,
            }),
        /LOWER than the published/,
    );
});

/* ── Shape of the metadata ────────────────────────────────────────────── */

test('non-strict semver is refused on either side', () => {
    for (const bad of ['0.3', 'v0.3.6', '0.3.6-rc.1', '0.3.6+build', '1.2.3.4', 'latest', '']) {
        assert.throws(
            () =>
                validateVersionAdvance({
                    candidate: candidate(bad),
                    published: published('0.3.5'),
                    commit: COMMIT,
                }),
            /strict numeric x\.y\.z semver/,
            `candidate "${bad}" should have been refused`,
        );
    }
    assert.throws(
        () =>
            validateVersionAdvance({
                candidate: candidate('0.3.6'),
                published: published('0.3'),
                commit: COMMIT,
            }),
        /strict numeric x\.y\.z semver/,
    );
});

test('a filename disagreeing with its own version is refused', () => {
    assert.throws(
        () =>
            validateVersionAdvance({
                candidate: candidate('0.3.6', {
                    filename: 'tally-sync-agent-setup-0.3.5.exe',
                }),
                published: published('0.3.5'),
                commit: COMMIT,
            }),
        /does not match its own version/,
    );
});

test('an artifact built from a different commit than this run is refused', () => {
    assert.throws(
        () =>
            validateVersionAdvance({
                candidate: candidate('0.3.6', { commit: 'deadbee' }),
                published: published('0.3.5'),
                commit: COMMIT,
            }),
        /built from commit "deadbee" but this publish run is at "abc1234"/,
    );
});

/* ── Missing inputs BLOCK; they never pass by default ─────────────────── */

test('missing published metadata blocks the publish rather than assuming a first release', () => {
    for (const missing of [null, undefined, 'not an object']) {
        assert.throws(
            () =>
                validateVersionAdvance({
                    candidate: candidate('0.3.6'),
                    published: missing,
                    commit: COMMIT,
                }),
            /Publishing is blocked/,
        );
    }
});

test('missing candidate metadata or commit is refused', () => {
    assert.throws(
        () => validateVersionAdvance({ candidate: null, published: published('0.3.5'), commit: COMMIT }),
        /candidate metadata is missing/,
    );
    assert.throws(
        () => validateVersionAdvance({ candidate: candidate('0.3.6'), published: published('0.3.5'), commit: '' }),
        /commit SHA was not supplied/,
    );
});

/* ── The primitives ───────────────────────────────────────────────────── */

test('compareVersions orders numerically, not lexically', () => {
    assert.equal(compareVersions('0.3.10', '0.3.9'), 1);
    assert.equal(compareVersions('0.3.9', '0.3.10'), -1);
    assert.equal(compareVersions('1.0.0', '1.0.0'), 0);
});

test('parseVersion returns numeric components', () => {
    assert.deepEqual(parseVersion('10.20.30', 'test'), [10, 20, 30]);
});

test('expectedFilename derives the stable installer name', () => {
    assert.equal(expectedFilename('0.3.6'), 'tally-sync-agent-setup-0.3.6.exe');
});
