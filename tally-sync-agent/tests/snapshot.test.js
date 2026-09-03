/**
 * Executable contract for the post-Tally SNAPSHOT the agent uploads to the
 * cloud (Phase 4 — agent 0.3.8): {xml, sha256, what Tally answered}.
 *
 * WHY THIS FILE EXISTS. Until now the cloud had no record of WHAT XML the
 * agent sent to Tally nor WHAT Tally answered beyond a one-line message. The
 * snapshot closes that gap — but it must do so WITHOUT ever changing the
 * post/ack/fail outcome. Two things are pinned here:
 *
 *   - The body is honest: the sha256 is of the exact bytes posted, the raw
 *     Tally response is capped so the cloud can store it, and an XML too big
 *     to send is omitted while its hash still goes up.
 *   - The upload can NEVER escape: a rejecting or throwing client is warned
 *     about and swallowed, so a snapshot failure cannot turn a synced voucher
 *     into a "failed" one, and cannot stop the loop.
 *
 * WHY IT REQUIRES dist/snapshot.js WITH NO node_modules. Same reasoning as
 * postDecision.test.js: sync.ts pulls in axios and electron-store, so the
 * upload is a pure builder + an injected `upload` function. Every runtime
 * import in snapshot.ts is a node built-in; every other import is type-only.
 *
 * Nothing here contacts Tally or the cloud. No network. Plain objects.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const path = require('node:path');

const {
    buildSnapshotBody,
    sendSnapshot,
    SNAPSHOT_RAW_CAP_BYTES,
    SNAPSHOT_XML_CAP_BYTES,
} = require('../dist/snapshot.js');
const { agentVersion } = require('../dist/version.js');
const { buildVoucherXml } = require('../dist/tally/voucherBuilders/index.js');

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
    agentVersion: '0.3.8',
    ...overrides,
});

/** Collects what a caller's logger would have been told. */
function fakeDeps(upload) {
    const warnings = [];
    const infos = [];
    return {
        deps: {
            upload,
            warn: (message, meta) => warnings.push({ message, meta }),
            info: (message, meta) => infos.push({ message, meta }),
        },
        warnings,
        infos,
    };
}

/* ── Zero-dependency guarantee ────────────────────────────────────────── */

test('the module is requirable with no runtime dependency', () => {
    const heavy = Object.keys(require.cache).filter((k) => k.includes('node_modules'));
    assert.deepEqual(heavy, [], 'snapshot must not drag in electron or axios');
});

/* ── The body ─────────────────────────────────────────────────────────── */

test('the body carries the sha256 of the exact XML bytes and the XML itself', () => {
    const body = buildSnapshotBody(input());

    assert.equal(body.xml, XML);
    assert.equal(body.xml_sha256, sha256(XML));
    assert.match(body.xml_sha256, /^[0-9a-f]{64}$/);
});

test('the sha256 is over UTF-8 bytes — a non-ASCII item name hashes as Tally received it', () => {
    const xml = '<STOCKITEMNAME>Relpet – प्लास्टिक ₹</STOCKITEMNAME>';
    const body = buildSnapshotBody(input({ xml }));

    assert.equal(body.xml_sha256, sha256(xml));
    assert.equal(body.xml_sha256, crypto.createHash('sha256').update(Buffer.from(xml, 'utf8')).digest('hex'));
});

test('the sha256 of a real Stock Journal from the dispatcher matches the XML the builder produced', () => {
    // The XML builders are NOT touched by this phase — this only proves the
    // snapshot hashes exactly what buildVoucherXml handed to the poster.
    const xml = buildVoucherXml(
        {
            id: 7,
            tally_voucher_type: 'Stock Journal',
            payload: {
                voucher_type: 'Stock Journal',
                voucher_date: '2026-08-07',
                voucher_number: 'SJ-20260807-S3',
                narration: 'Shift C consolidated production',
                godown: 'Factory Day Bin',
                consumed: [{ item: 'Relpet G5801M', quantity: '307.3400' }],
                produced: [{ item: 'L. 500 ml Kidney RIB clear Pet', quantity: '13363' }],
            },
        },
        'SWAASHPET POLYMERS PVT LTD',
    );
    const body = buildSnapshotBody(input({ xml }));

    assert.equal(body.xml, xml);
    assert.equal(body.xml_sha256, sha256(xml));
});

test('the tally block is the import result summary, with raw as Tally sent it', () => {
    const body = buildSnapshotBody(input());

    assert.deepEqual(body.tally, {
        success: true,
        created: 1,
        errors: 0,
        message: 'Imported successfully',
        raw: '<RESPONSE><CREATED>1</CREATED><ERRORS>0</ERRORS></RESPONSE>',
    });
});

test('a Tally rejection is carried as success:false with its LINEERROR message', () => {
    const body = buildSnapshotBody(
        input({
            tally: tallyOk({
                success: false,
                created: 0,
                errors: 1,
                message: 'Stock Item does not exist',
                rawResponse: '<RESPONSE><LINEERROR>Stock Item does not exist</LINEERROR></RESPONSE>',
            }),
        }),
    );

    assert.equal(body.tally.success, false);
    assert.equal(body.tally.created, 0);
    assert.equal(body.tally.errors, 1);
    assert.equal(body.tally.message, 'Stock Item does not exist');
});

test('the inconclusive-timeout path sends tally: null — we sent XML, Tally never answered', () => {
    const body = buildSnapshotBody(input({ tally: null }));

    assert.equal(body.tally, null);
    assert.equal(body.xml, XML);
    assert.equal(body.xml_sha256, sha256(XML));
});

test('attempt is the 1-based ordinal of THIS post (attempts so far + 1); agent_version and payload_hash are echoed', () => {
    const body = buildSnapshotBody(input({ entry: entry({ attempts: 3, payload_hash: 'b'.repeat(64) }) }));

    // Tally refused it three times; this is the fourth post — the same number
    // the cloud's voucher.failed event will carry if it fails again.
    assert.equal(body.attempt, 4);
    assert.equal(buildSnapshotBody(input({ entry: entry({ attempts: 0 }) })).attempt, 1);
    assert.equal(body.agent_version, '0.3.8');
    assert.equal(body.payload_hash, 'b'.repeat(64));
});

test('an older cloud that sends no payload_hash yields payload_hash: null, not undefined', () => {
    const e = entry();
    delete e.payload_hash;
    const body = buildSnapshotBody(input({ entry: e, agentVersion: null }));

    assert.equal(body.payload_hash, null);
    assert.equal(body.agent_version, null);
    // JSON transport must carry the keys explicitly (nullable, not absent).
    const wire = JSON.parse(JSON.stringify(body));
    assert.ok('payload_hash' in wire);
    assert.ok('agent_version' in wire);
    assert.equal(wire.payload_hash, null);
});

/* ── Caps ─────────────────────────────────────────────────────────────── */

test('the raw Tally response is capped at 64 KB by slicing; the summary is untouched', () => {
    assert.equal(SNAPSHOT_RAW_CAP_BYTES, 65535);

    const raw = '<RESPONSE>' + 'x'.repeat(SNAPSHOT_RAW_CAP_BYTES * 2) + '</RESPONSE>';
    const body = buildSnapshotBody(input({ tally: tallyOk({ rawResponse: raw }) }));

    assert.equal(Buffer.byteLength(body.tally.raw, 'utf8'), SNAPSHOT_RAW_CAP_BYTES);
    assert.ok(body.tally.raw.startsWith('<RESPONSE>'));
    assert.equal(body.tally.raw, raw.slice(0, SNAPSHOT_RAW_CAP_BYTES));
    assert.equal(body.tally.success, true);
    assert.equal(body.tally.message, 'Imported successfully');
});

test('the raw cap counts BYTES and never splits a multi-byte character', () => {
    // 3 bytes each; 65535 is divisible by 3, so a 2-byte offset forces a split.
    const raw = 'ab' + '₹'.repeat(SNAPSHOT_RAW_CAP_BYTES);
    const body = buildSnapshotBody(input({ tally: tallyOk({ rawResponse: raw }) }));

    const bytes = Buffer.byteLength(body.tally.raw, 'utf8');
    assert.ok(bytes <= SNAPSHOT_RAW_CAP_BYTES, `raw is ${bytes} bytes, over the cap`);
    assert.ok(!body.tally.raw.includes('�'), 'a split character would decode as U+FFFD');
    assert.ok(body.tally.raw.length <= SNAPSHOT_RAW_CAP_BYTES, 'character count must satisfy the server max too');
});

test('a raw response under the cap is sent whole', () => {
    const raw = '<RESPONSE><CREATED>1</CREATED></RESPONSE>';
    const body = buildSnapshotBody(input({ tally: tallyOk({ rawResponse: raw }) }));

    assert.equal(body.tally.raw, raw);
});

test('an empty raw response is carried as null', () => {
    const body = buildSnapshotBody(input({ tally: tallyOk({ rawResponse: '' }) }));

    assert.equal(body.tally.raw, null);
});

test('an XML over 2 MB is OMITTED from the body while its sha256 is still sent', () => {
    assert.equal(SNAPSHOT_XML_CAP_BYTES, 2 * 1024 * 1024);

    const xml = '<ENVELOPE>' + 'y'.repeat(SNAPSHOT_XML_CAP_BYTES) + '</ENVELOPE>';
    const body = buildSnapshotBody(input({ xml }));

    assert.ok(!('xml' in body), 'xml key must be absent, not null, when omitted');
    assert.equal(body.xml_bytes, Buffer.byteLength(xml, 'utf8'), 'the size still goes up with the hash');
    assert.equal(body.xml_sha256, sha256(xml));
    assert.equal(body.tally.success, true);
    // And it stays absent on the wire.
    assert.ok(!('xml' in JSON.parse(JSON.stringify(body))));
});

test('an XML exactly at 2 MB is still sent whole', () => {
    const xml = 'z'.repeat(SNAPSHOT_XML_CAP_BYTES);
    const body = buildSnapshotBody(input({ xml }));

    assert.equal(body.xml, xml);
});

test('the XML cap counts UTF-8 bytes, not characters', () => {
    // 700 000 three-byte characters = 2 100 000 bytes > 2 MiB, though only 700 000 chars.
    const xml = '₹'.repeat(700_000);
    assert.ok(xml.length < SNAPSHOT_XML_CAP_BYTES);
    assert.ok(Buffer.byteLength(xml, 'utf8') > SNAPSHOT_XML_CAP_BYTES);

    const body = buildSnapshotBody(input({ xml }));

    assert.ok(!('xml' in body));
    assert.equal(body.xml_sha256, sha256(xml));
});

/* ── The upload never escapes ─────────────────────────────────────────── */

test('sendSnapshot uploads the built body to the entry and reports true', async () => {
    const calls = [];
    const { deps, warnings, infos } = fakeDeps(async (entryId, body) => {
        calls.push({ entryId, body });
    });

    const ok = await sendSnapshot(input(), deps);

    assert.equal(ok, true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].entryId, 42);
    assert.deepEqual(calls[0].body, buildSnapshotBody(input()));
    assert.equal(warnings.length, 0);
    assert.equal(infos.length, 1);
    assert.match(infos[0].message, /#42/);
});

test('a client that REJECTS is warned about and swallowed — never thrown', async () => {
    const { deps, warnings } = fakeDeps(async () => {
        throw new Error('Request failed with status code 503');
    });

    let ok;
    await assert.doesNotReject(async () => {
        ok = await sendSnapshot(input(), deps);
    });

    assert.equal(ok, false);
    assert.equal(warnings.length, 1);
    assert.match(warnings[0].message, /snapshot/i);
    assert.match(warnings[0].message, /#42/);
    assert.equal(warnings[0].meta.message, 'Request failed with status code 503');
});

test('a client that throws SYNCHRONOUSLY is swallowed too', async () => {
    const { deps, warnings } = fakeDeps(() => {
        throw new Error('boom');
    });

    const ok = await sendSnapshot(input(), deps);

    assert.equal(ok, false);
    assert.equal(warnings.length, 1);
    assert.equal(warnings[0].meta.message, 'boom');
});

test('a client that rejects with a non-Error is still swallowed and described', async () => {
    const { deps, warnings } = fakeDeps(() => Promise.reject('plain string rejection'));

    const ok = await sendSnapshot(input(), deps);

    assert.equal(ok, false);
    assert.equal(warnings[0].meta.message, 'plain string rejection');
});

test('a logger that itself throws cannot make sendSnapshot throw', async () => {
    const deps = {
        upload: async () => {
            throw new Error('down');
        },
        warn: () => {
            throw new Error('logger exploded');
        },
    };

    await assert.doesNotReject(async () => {
        assert.equal(await sendSnapshot(input(), deps), false);
    });
});

test('the warning never carries the XML body or the raw Tally text — counts only', async () => {
    const { deps, warnings } = fakeDeps(async () => {
        throw new Error('nope');
    });

    await sendSnapshot(input(), deps);

    const dumped = JSON.stringify(warnings);
    assert.ok(!dumped.includes(XML), 'the XML must not be logged on failure');
    assert.ok(!dumped.includes('<RESPONSE>'), 'raw Tally text must not be logged on failure');
    assert.ok(dumped.includes(sha256(XML).slice(0, 12)), 'the hash prefix identifies the snapshot');
});

test('a body-builder failure is swallowed as well — a bad input never reaches the loop', async () => {
    const { deps, warnings } = fakeDeps(async () => {
        assert.fail('upload must not be attempted when the body could not be built');
    });

    // xml is not a string: crypto refuses it. sendSnapshot must not.
    const ok = await sendSnapshot(input({ xml: 12345 }), deps);

    assert.equal(ok, false);
    assert.equal(warnings.length, 1);
});

/* ── The version the snapshot is stamped with ─────────────────────────── */

test('agentVersion() reads package.json and is the strict semver the release gate wants', () => {
    const pkg = require(path.join(__dirname, '..', 'package.json'));

    assert.equal(agentVersion(), pkg.version);
    assert.match(agentVersion(), /^\d+\.\d+\.\d+$/);
});

test('this candidate is 0.4.7 — snapshots since 0.3.8, the Purchase Order builder since 0.3.9, the purchase-rate read since 0.4.0, measured receivables summary support since 0.4.6, the bill-wise Collection read since 0.4.7', () => {
    // The drawer's "agent ≥ 0.3.8" line depends on 0.3.8 being the snapshot
    // FLOOR, which does not move; the candidate itself advances. 0.3.9 added
    // purchaseOrder.ts (Phase 6, staged, flag off); 0.4.0 adds the Day Book
    // purchase-rate READ — tally/purchaseRates.ts and the tray item that runs
    // it, feeding Procurement's vendor/item rate lookup. It posts nothing to
    // Tally and runs on no timer.
    //
    // Built and tested, NOT published. Publishing writes the auto-update feed
    // and the factory agent self-updates from it within hours, so it is the
    // owner's dispatch — see DEPLOY.md's release ritual.
    // 0.4.1 decodes Tally's numeric character references before cleaning a
    // value — the agent half of the 31-Aug-2026 masters-pull outage. The cloud
    // half shipped separately and does not need this build to be correct.
    // 0.4.2 stops the Day Book parser following a hardcoded envelope path —
    // the reason the first live purchase-rate pull reported zero lines against
    // a company with hundreds of purchase orders. THIS build is required for
    // the rate lookup to carry anything; unlike 0.4.1, the cloud cannot
    // compensate for it, because the parsing happens here.
    // 0.4.3 reads purchase vouchers through a TDL COLLECTION — the shape every
    // read that works against this factory's Tally uses — falling back to the
    // Day Book report and REPORTING WHICH ONE ANSWERED. The reporting is the
    // point: three pulls on 31-Aug returned zero and the cloud could not tell
    // "bought nothing" from "request refused" from "answer not understood".
    // 0.4.4 stops an ATTRIBUTED element being stringified into the literal
    // "[object Object]". 0.4.3's Collection read worked and imported 458 rows
    // whose supplier name was exactly that — a total success that could answer
    // nothing.
    // 0.4.5 adds the RECEIVABLES READ — tally/receivables.ts and the tray item
    // that runs it: Bills Receivable and Sales Order Outstanding, feeding the
    // Finance client-outstanding page. Read-only, on no timer, and it posts
    // nothing to Tally, like every other pull here. Its parsers walk the
    // document for their nodes rather than following a path — 0.4.2's lesson,
    // applied before it could be paid for twice.
    //
    // 0.4.6 replaces the unmeasured bill-row assumption with the factory's
    // observed DSPACCNAME/DSPACCINFO party-summary response and corrects
    // Tally's debit sign at the integration boundary. The cloud/UI explicitly
    // marks these as balance-only rows, never as invented bill detail.
    //
    // 0.4.7 CLOSES THAT FOLLOW-UP. The bills are asked for as a TDL COLLECTION
    // first — the shape 0.4.3 measured this Tally answers — with the report
    // request kept as the fallback, and the pull reports WHICH SHAPE ANSWERED
    // and HOW MANY ROWS CARRIED A DUE DATE. It also stops asking with
    // SVFROMDATE = SVTODATE = the as-at day: that is a one-day window, and an
    // outstanding position is an as-at reading over the whole book.
    //
    // WHY IT HAD TO CHANGE: the party-summary answer 0.4.6 accepted cannot be
    // aged. With no bill date and no due date, every rupee lands in the page's
    // "no due date" bucket and the ageing columns stay empty for good.
    //
    // THE COLLECTION SHAPE IS NOT YET MEASURED. Nobody has run it against the
    // factory's Tally, and an unrecognised NATIVEMETHOD returns an empty
    // collection rather than an error — #64's exact failure mode. That is why
    // it is the first of two shapes and not the only one, and why every
    // attempt is still described by node name and count.
    assert.equal(agentVersion(), '0.4.7');
});
