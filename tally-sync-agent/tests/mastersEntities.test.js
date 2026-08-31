/**
 * TALLY'S NUMERIC CHARACTER REFERENCES, and why they took the factory down.
 *
 * On 31-Aug-2026 the masters pull started returning HTTP 422 and syncing
 * nothing. Three of the factory's 1742 ledgers carry a perfectly good GSTIN
 * with `&#13;&#10;` on the end — and `fast-xml-parser` with
 * `parseTagValue: false` does not decode those, so what reached clean() was
 * the ten characters LITERALLY, every one of them printable. The char-code
 * strip could not see them, 25 characters went at a 15-character column, and
 * because validation is all-or-nothing the whole pull died.
 *
 * The trap was that the obvious reading — "clean() strips CR and LF, so this
 * cannot happen" — is wrong. These tests pin the parser's ACTUAL behaviour, so
 * the next person does not have to rediscover it.
 *
 * GSTINs and names below are synthetic (FC-06); the byte sequence is real.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { exportLedgers } = require('../dist/tally/masters');

/** Stub the one call exportLedgers makes, with a canned Tally response. */
function withTallyResponse(xml, run) {
    const axios = require('axios');
    const original = axios.post;
    axios.post = async () => ({ data: xml });
    return run().finally(() => {
        axios.post = original;
    });
}

function ledgerXml(inner) {
    return (
        '<ENVELOPE><BODY><DATA><COLLECTION>' +
        `<LEDGER NAME="Alpha"><GUID>led-1</GUID><PARENT>Sundry Creditors</PARENT>${inner}</LEDGER>` +
        '</COLLECTION></DATA></BODY></ENVELOPE>'
    );
}

const target = { host: '127.0.0.1', port: 9000, company: 'SYNTHETIC' };

test('a GSTIN carrying Tally\'s undecoded line break is recovered, not mangled', async () => {
    await withTallyResponse(
        ledgerXml('<PARTYGSTIN>33AAAAA0000A1ZA&#13;&#10;</PARTYGSTIN>'),
        async () => {
            const [ledger] = await exportLedgers(target);

            // The whole point: 15 characters, the real GSTIN, not 25.
            assert.equal(ledger.gstin, '33AAAAA0000A1ZA');
            assert.equal(ledger.gstin.length, 15);
        },
    );
});

test('Tally\'s &#4; reserved-word marker is stripped from an ordinary value', async () => {
    await withTallyResponse(
        ledgerXml('<PRIORSTATENAME>&#4; Puducherry</PRIORSTATENAME>'),
        async () => {
            const [ledger] = await exportLedgers(target);
            assert.equal(ledger.state_name, 'Puducherry');
        },
    );
});

test('a field holding two GSTINs is NOT sent, rather than sent and dropped', async () => {
    await withTallyResponse(
        ledgerXml('<PARTYGSTIN>33AAAAA0000A1ZA / 27BBBBB1111B1ZB</PARTYGSTIN>'),
        async () => {
            const [ledger] = await exportLedgers(target);

            // Absent, not null: absent means "leave the column alone", which
            // is the right instruction for a value we cannot vouch for.
            assert.equal('gstin' in ledger, false);
        },
    );
});

test('a clean GSTIN is still sent unchanged', async () => {
    await withTallyResponse(
        ledgerXml('<PARTYGSTIN>33AAAAA0000A1ZA</PARTYGSTIN>'),
        async () => {
            const [ledger] = await exportLedgers(target);
            assert.equal(ledger.gstin, '33AAAAA0000A1ZA');
        },
    );
});

test('a ledger with no party fields sends none of them', async () => {
    await withTallyResponse(ledgerXml(''), async () => {
        const [ledger] = await exportLedgers(target);

        assert.equal('gstin' in ledger, false);
        assert.equal('email' in ledger, false);
        assert.equal('phone' in ledger, false);
        assert.equal(ledger.name, 'Alpha');
    });
});
