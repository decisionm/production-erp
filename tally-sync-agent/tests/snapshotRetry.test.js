/**
 * Executable contract for the SNAPSHOT JOURNAL — what happens to a post-Tally
 * snapshot when the cloud will not take it (Phase 7 hardening; agent 0.3.9).
 *
 * WHY THIS FILE EXISTS. Until this change a snapshot whose upload failed was
 * warned about and dropped: the agent posted to Tally, Tally answered, and if
 * the cloud happened to be down at that moment (a deploy's maintenance window
 * answers 503 on every route) the record of WHAT was sent and WHAT Tally said
 * was lost for good — the drawer would show nothing for a post that did
 * happen. The post itself was already journalled (post-journal.json) so the
 * ack/fail could be said again later; the snapshot was not. Now it is: a
 * failed upload is written to a second journal and re-sent on a later cycle,
 * once the cloud is answering again, with a bounded number of attempts and a
 * logged give-up. Nothing here changes what reaches Tally or what the cloud is
 * told about the entry — a snapshot is a RECORD, before and after.
 *
 * WHY IT REQUIRES dist/*.js WITH NO node_modules. Same reasoning as
 * snapshot.test.js: sync.ts pulls in axios and electron-store, so the queue
 * is a pure module with an INJECTED store ({load, save}) and an injected
 * upload; the electron-store persistence (snapshotJournal.ts) is a thin
 * adapter that is not required here. Every runtime import in
 * snapshotQueue.ts and snapshot.ts is a node built-in.
 *
 * Nothing here contacts Tally or the cloud. No network. Plain objects.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');

const { buildSnapshotBody, sendSnapshot } = require('../dist/snapshot.js');
const {
    enqueueSnapshot,
    flushSnapshotQueue,
    SNAPSHOT_RETRY_MAX_ATTEMPTS,
    SNAPSHOT_QUEUE_CAP,
} = require('../dist/snapshotQueue.js');

const sha256 = (text) => crypto.createHash('sha256').update(text, 'utf8').digest('hex');

const XML = '<ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER></ENVELOPE>';

const entry = (overrides = {}) => ({
    id: 42,
    tally_voucher_type: 'Stock Journal',
    attempts: 0,
    delivered_at: null,
    payload: { voucher_number: 'SJ-20260816-S1' },
    payload_hash: 'a'.repeat(64),
    ...overrides,
});

const tallyOk = (overrides = {}) => ({
    success: true,
    created: 1,
    errors: 0,
    message: 'Imported successfully',
    rawResponse: '<RESPONSE><CREATED>1</CREATED><ERRORS>0</ERRORS></RESPONSE>',
    ...overrides,
});

const input = (overrides = {}) => ({
    entry: entry(),
    xml: XML,
    tally: tallyOk(),
    agentVersion: '0.3.9',
    ...overrides,
});

/** An in-memory stand-in for the electron-store journal: the same {load, save} the app injects. */
function memoryStore(initial = []) {
    let items = initial.map((item) => JSON.parse(JSON.stringify(item)));
    const saves = [];
    return {
        store: {
            load: () => JSON.parse(JSON.stringify(items)),
            save: (next) => {
                items = JSON.parse(JSON.stringify(next));
                saves.push(items);
            },
        },
        items: () => items,
        saves,
    };
}

/** Collects what a caller's logger would have been told. */
function fakeLog() {
    const warnings = [];
    const infos = [];
    const errors = [];
    return {
        log: {
            warn: (message, meta) => warnings.push({ message, meta }),
            info: (message, meta) => infos.push({ message, meta }),
            error: (message, meta) => errors.push({ message, meta }),
        },
        warnings,
        infos,
        errors,
    };
}

const cloudDown = async () => {
    throw new Error('Request failed with status code 503');
};

/** A queued record as the journal would hold it after one failed upload. */
function queued(overrides = {}) {
    return {
        entryId: 42,
        body: buildSnapshotBody(input()),
        attempts: 1,
        queuedAt: '2026-08-16T10:00:00.000Z',
        lastError: 'Request failed with status code 503',
        ...overrides,
    };
}

/* ── Zero-dependency guarantee ────────────────────────────────────────── */

test('the queue module is requirable with no runtime dependency', () => {
    const heavy = Object.keys(require.cache).filter((k) => k.includes('node_modules'));
    assert.deepEqual(heavy, [], 'snapshotQueue must not drag in electron or axios');
});

/* ── The behaviour this change exists for ─────────────────────────────── */

test('RED-BEFORE: a snapshot whose upload fails while the cloud is down is JOURNALLED, not lost', async () => {
    const { store, items } = memoryStore();
    const { log, warnings } = fakeLog();

    const ok = await sendSnapshot(input(), { upload: cloudDown, warn: log.warn, info: log.info, queue: store });

    // The outcome contract is unchanged: false, warned, never thrown…
    assert.equal(ok, false);
    assert.equal(warnings.length, 1);
    assert.match(warnings[0].message, /#42/);
    // …but the record now survives the outage.
    assert.equal(items().length, 1, 'the failed snapshot must be written to the journal');
    assert.equal(items()[0].entryId, 42);
    assert.deepEqual(items()[0].body, buildSnapshotBody(input()), 'the queued body is the exact body that failed to upload');
    assert.equal(items()[0].attempts, 1, 'the failed upload itself is attempt 1');
    assert.equal(items()[0].lastError, 'Request failed with status code 503');
    assert.match(items()[0].queuedAt, /^\d{4}-\d{2}-\d{2}T/);
    assert.match(warnings[0].message, /queued|re-sent|retry/i, 'the warn line says the record is kept, not gone');
});

test('the queued snapshot is RE-SENT verbatim on a later flush and then forgotten', async () => {
    const { store, items } = memoryStore();
    const { log } = fakeLog();

    await sendSnapshot(input(), { upload: cloudDown, warn: log.warn, queue: store });
    assert.equal(items().length, 1);

    // The cloud is back. Nothing about the record may have changed in
    // transit: same entry, same sha256, same attempt ordinal, same tally block.
    const received = [];
    const result = await flushSnapshotQueue(store, {
        upload: async (entryId, body) => {
            received.push({ entryId, body });
        },
        ...log,
    });

    assert.deepEqual(result.sent, [42]);
    assert.deepEqual(result.kept, []);
    assert.deepEqual(result.dropped, []);
    assert.equal(received.length, 1);
    assert.equal(received[0].entryId, 42);
    assert.deepEqual(received[0].body, buildSnapshotBody(input()));
    assert.equal(received[0].body.xml_sha256, sha256(XML));
    assert.equal(received[0].body.attempt, 1);
    assert.equal(received[0].body.tally.success, true);
    assert.equal(items().length, 0, 'a delivered snapshot leaves the journal');
});

test('the scenario itself: Tally answered during a maintenance window — the answer still reaches the cloud', async () => {
    // Tally REJECTED the voucher (a real answer worth keeping) while every
    // cloud route answered 503. The reject/report path is journalled by
    // postJournal already; this proves the snapshot rides the same outage.
    const rejected = input({
        tally: tallyOk({
            success: false,
            created: 0,
            errors: 1,
            message: 'Stock Item does not exist',
            rawResponse: '<RESPONSE><LINEERROR>Stock Item does not exist</LINEERROR></RESPONSE>',
        }),
    });
    const { store } = memoryStore();
    const { log } = fakeLog();

    let cloudUp = false;
    const uploaded = [];
    const upload = async (entryId, body) => {
        if (!cloudUp) throw new Error('Request failed with status code 503');
        uploaded.push({ entryId, body });
    };

    assert.equal(await sendSnapshot(rejected, { upload, warn: log.warn, queue: store }), false);
    // Still down on the next cycle: kept, not dropped, not duplicated.
    let r = await flushSnapshotQueue(store, { upload, ...log });
    assert.deepEqual(r.kept, [42]);
    assert.equal(uploaded.length, 0);

    cloudUp = true;
    r = await flushSnapshotQueue(store, { upload, ...log });
    assert.deepEqual(r.sent, [42]);
    assert.equal(uploaded.length, 1);
    assert.equal(uploaded[0].body.tally.success, false);
    assert.equal(uploaded[0].body.tally.message, 'Stock Item does not exist');
    assert.equal(uploaded[0].body.xml, XML);
    // And a further flush has nothing to say.
    r = await flushSnapshotQueue(store, { upload, ...log });
    assert.deepEqual(r, { sent: [], kept: [], dropped: [] });
    assert.equal(uploaded.length, 1, 'a delivered snapshot is never sent twice by this agent');
});

test('without a queue in deps (an older caller) sendSnapshot behaves exactly as before', async () => {
    const { log, warnings } = fakeLog();

    const ok = await sendSnapshot(input(), { upload: cloudDown, warn: log.warn });

    assert.equal(ok, false);
    assert.equal(warnings.length, 1);
    assert.equal(warnings[0].meta.message, 'Request failed with status code 503');
});

test('a successful upload queues nothing', async () => {
    const { store, items } = memoryStore();
    const { log } = fakeLog();

    assert.equal(await sendSnapshot(input(), { upload: async () => {}, warn: log.warn, queue: store }), true);
    assert.equal(items().length, 0);
});

test('a body that could not be built is not queued (there is nothing honest to re-send)', async () => {
    const { store, items } = memoryStore();
    const { log } = fakeLog();

    assert.equal(await sendSnapshot(input({ xml: 12345 }), { upload: cloudDown, warn: log.warn, queue: store }), false);
    assert.equal(items().length, 0);
});

/* ── Bounded ──────────────────────────────────────────────────────────── */

test('attempts are bounded: a snapshot the cloud keeps refusing is given up with an error line', async () => {
    assert.ok(Number.isInteger(SNAPSHOT_RETRY_MAX_ATTEMPTS) && SNAPSHOT_RETRY_MAX_ATTEMPTS >= 2);

    const { store, items } = memoryStore([queued({ attempts: SNAPSHOT_RETRY_MAX_ATTEMPTS - 1 })]);
    const { log, errors, warnings } = fakeLog();

    const result = await flushSnapshotQueue(store, { upload: cloudDown, ...log });

    assert.deepEqual(result.dropped, [42]);
    assert.deepEqual(result.kept, []);
    assert.equal(items().length, 0, 'a given-up snapshot leaves the journal');
    assert.equal(errors.length, 1, 'the give-up is logged at error level, once');
    assert.match(errors[0].message, /giving up|gave up|given up/i);
    assert.match(errors[0].message, /#42/);
    assert.equal(errors[0].meta.attempts, SNAPSHOT_RETRY_MAX_ATTEMPTS);
    assert.equal(warnings.length, 0, 'not ALSO warned as "kept"');
});

test('below the bound a failed retry increments attempts, records the error and keeps the record', async () => {
    const { store, items } = memoryStore([queued({ attempts: 1, lastError: 'first' })]);
    const { log, warnings, errors } = fakeLog();

    const result = await flushSnapshotQueue(store, {
        upload: async () => {
            throw new Error('ECONNREFUSED');
        },
        ...log,
    });

    assert.deepEqual(result.kept, [42]);
    assert.equal(items().length, 1);
    assert.equal(items()[0].attempts, 2);
    assert.equal(items()[0].lastError, 'ECONNREFUSED');
    assert.deepEqual(items()[0].body, queued().body, 'the body is never rewritten by a retry');
    assert.equal(warnings.length, 1);
    assert.match(warnings[0].message, /#42/);
    assert.match(warnings[0].message, new RegExp(`2/${SNAPSHOT_RETRY_MAX_ATTEMPTS}`));
    assert.equal(errors.length, 0);
});

test('a flush stops at the first failure — the rest wait for the next poll rather than each eating a timeout', async () => {
    const { store, items } = memoryStore([
        queued({ entryId: 1, body: buildSnapshotBody(input({ entry: entry({ id: 1 }) })) }),
        queued({ entryId: 2, body: buildSnapshotBody(input({ entry: entry({ id: 2 }) })) }),
        queued({ entryId: 3, body: buildSnapshotBody(input({ entry: entry({ id: 3 }) })) }),
    ]);
    const { log } = fakeLog();

    const attempted = [];
    const result = await flushSnapshotQueue(store, {
        upload: async (entryId) => {
            attempted.push(entryId);
            if (entryId === 2) throw new Error('down again');
        },
        ...log,
    });

    assert.deepEqual(attempted, [1, 2], 'entry 3 must not be attempted after 2 failed');
    assert.deepEqual(result.sent, [1]);
    assert.deepEqual(result.kept, [2, 3]);
    assert.deepEqual(items().map((i) => i.entryId), [2, 3], 'FIFO order is preserved');
    assert.equal(items()[0].attempts, 2);
    assert.equal(items()[1].attempts, 1, 'an unattempted record does not lose an attempt');
});

test('the journal is capped: over the cap the OLDEST record is dropped with an error line', () => {
    assert.ok(Number.isInteger(SNAPSHOT_QUEUE_CAP) && SNAPSHOT_QUEUE_CAP >= 2);

    const seed = [];
    for (let i = 1; i <= SNAPSHOT_QUEUE_CAP; i += 1) {
        seed.push(queued({ entryId: i, body: buildSnapshotBody(input({ entry: entry({ id: i }) })) }));
    }
    const { store, items } = memoryStore(seed);
    const { log, errors } = fakeLog();

    const newest = buildSnapshotBody(input({ entry: entry({ id: 999 }) }));
    assert.equal(enqueueSnapshot(store, 999, newest, 'down', log), true);

    assert.equal(items().length, SNAPSHOT_QUEUE_CAP);
    assert.equal(items()[0].entryId, 2, 'entry 1 (the oldest) was dropped');
    assert.equal(items()[items().length - 1].entryId, 999);
    assert.equal(errors.length, 1);
    assert.match(errors[0].message, /#1\b/);
    assert.match(errors[0].message, /full|cap/i);
});

test('re-queueing the SAME post (entry + sha256 + attempt) replaces the record instead of doubling it', () => {
    const { store, items } = memoryStore();
    const { log } = fakeLog();
    const body = buildSnapshotBody(input());

    enqueueSnapshot(store, 42, body, 'first', log);
    enqueueSnapshot(store, 42, body, 'second', log);
    assert.equal(items().length, 1);
    assert.equal(items()[0].lastError, 'second');

    // A DIFFERENT post of the same entry (Retry → attempt 2) is its own record.
    const later = buildSnapshotBody(input({ entry: entry({ attempts: 1 }) }));
    enqueueSnapshot(store, 42, later, 'third', log);
    assert.equal(items().length, 2);
    assert.deepEqual(items().map((i) => i.body.attempt), [1, 2]);
});

/* ── Never escapes ────────────────────────────────────────────────────── */

test('a journal that cannot be written cannot make sendSnapshot throw — it still returns false and warns', async () => {
    const broken = {
        load: () => [],
        save: () => {
            throw new Error('disk full');
        },
    };
    const { log, warnings } = fakeLog();

    let ok;
    await assert.doesNotReject(async () => {
        ok = await sendSnapshot(input(), { upload: cloudDown, warn: log.warn, queue: broken });
    });
    assert.equal(ok, false);
    assert.ok(warnings.length >= 1);
});

test('a journal that cannot be read cannot make the flush throw', async () => {
    const broken = {
        load: () => {
            throw new Error('corrupt json');
        },
        save: () => {},
    };
    const { log, warnings } = fakeLog();

    let result;
    await assert.doesNotReject(async () => {
        result = await flushSnapshotQueue(broken, { upload: async () => {}, ...log });
    });
    assert.deepEqual(result, { sent: [], kept: [], dropped: [] });
    assert.ok(warnings.length >= 1, 'the unreadable journal is warned about, not hidden');
});

test('an empty journal costs no upload and no log line', async () => {
    const { store } = memoryStore();
    const { log, warnings, infos, errors } = fakeLog();

    const result = await flushSnapshotQueue(store, {
        upload: async () => {
            assert.fail('nothing to upload');
        },
        ...log,
    });

    assert.deepEqual(result, { sent: [], kept: [], dropped: [] });
    assert.equal(warnings.length + infos.length + errors.length, 0);
});

test('the log lines never carry the XML body or the raw Tally text — ids, counts and the hash prefix only', async () => {
    const { store } = memoryStore();
    const { log, warnings, infos, errors } = fakeLog();

    await sendSnapshot(input(), { upload: cloudDown, warn: log.warn, queue: store });
    await flushSnapshotQueue(store, { upload: cloudDown, ...log });
    // Drive it to the give-up too.
    const { store: nearlyDone } = memoryStore([queued({ attempts: SNAPSHOT_RETRY_MAX_ATTEMPTS - 1 })]);
    await flushSnapshotQueue(nearlyDone, { upload: cloudDown, ...log });

    const dumped = JSON.stringify({ warnings, infos, errors });
    assert.ok(!dumped.includes(XML), 'the XML must not be logged');
    assert.ok(!dumped.includes('<RESPONSE>'), 'raw Tally text must not be logged');
    assert.ok(dumped.includes(sha256(XML).slice(0, 12)), 'the hash prefix identifies the snapshot');
});
