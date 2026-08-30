/**
 * Executable contract for the Receipt Note voucher builder's company gate.
 *
 * Plain JavaScript against dist/ for the reasons stockJournal.test.js gives:
 * tsconfig pins rootDir to src, so testing the COMPILED output needs no
 * second config and tests the artifact that ships.
 *
 * WHAT THIS GUARDS — the agent half of the 28-Aug rehearsal fix: a Receipt
 * Note voucher reached an OBSOLETE Tally company because nothing checked the
 * destination. The cloud now stamps `allowed_company`
 * (tally-sync.receipt_notes_allowed_company) on every staged Receipt Note,
 * and this builder refuses to build at all unless it equals, byte-for-byte,
 * the company this agent is configured for — the same lock the Purchase
 * Order builder carries (one shared implementation, xmlHelpers'
 * requireAllowedCompanyFor). A payload staged BEFORE the field existed has
 * no allowed_company key, and is refused the same way: an unknown
 * destination is refused, not assumed.
 *
 * Every value below is SYNTHETIC on purpose (FC-06): no real rate, vendor,
 * GSTIN, Tally item name or company name appears here.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { buildReceiptNoteXml } = require('../dist/tally/voucherBuilders/receiptNote.js');

const COMPANY = 'Company Alpha';

/** A payload shaped like TallySyncService::enqueueGoodsReceiptNote()'s. */
function payload(overrides = {}) {
    return {
        voucher_type: 'Receipt Note',
        voucher_date: '2026-08-20',
        voucher_number: 'GRN-7',
        party_ledger: 'Vendor Alpha',
        party_gstin: '00AAAAA0000A0Z0',
        allowed_company: COMPANY,
        godown: 'Godown Alpha',
        narration: null,
        lines: [{ item: 'ITEM_A', quantity: '100.0000', rate: '1.0000', amount: '100.0000' }],
        total_amount: '100.0000',
        ...overrides,
    };
}

test('a missing or blank allowed_company throws — this agent never guesses which Tally company a Receipt Note belongs in', () => {
    for (const allowed_company of [null, undefined, '', '   ']) {
        assert.throws(
            () => buildReceiptNoteXml(payload({ allowed_company }), COMPANY),
            /no allowed_company/,
        );
    }
});

test('a payload staged before the field existed (no key at all) is refused the same way', () => {
    const legacy = payload();
    delete legacy.allowed_company;
    assert.throws(() => buildReceiptNoteXml(legacy, COMPANY), /no allowed_company/);
});

test('an allowed_company that does not match this agent\'s configured Tally company throws', () => {
    assert.throws(
        () => buildReceiptNoteXml(payload({ allowed_company: 'Some Other Company' }), COMPANY),
        /does not match/,
    );
});

// The comparison is byte-for-byte: the cloud's one trim already ran, so any
// surviving difference — case, surrounding whitespace — is the wrong name.
test('the comparison is verbatim — no trim, no case-fold on this side', () => {
    assert.throws(() => buildReceiptNoteXml(payload({ allowed_company: `${COMPANY} ` }), COMPANY), /does not match/);
    assert.throws(() => buildReceiptNoteXml(payload({ allowed_company: COMPANY.toUpperCase() }), COMPANY), /does not match/);
});

test('a matching allowed_company builds normally, addressed to that company', () => {
    const xml = buildReceiptNoteXml(payload(), COMPANY);
    assert.match(xml, /VCHTYPE="Receipt Note"/);
    assert.match(xml, new RegExp(`<SVCURRENTCOMPANY>${COMPANY}</SVCURRENTCOMPANY>`));
    assert.match(xml, /<STOCKITEMNAME>ITEM_A<\/STOCKITEMNAME>/);
    assert.match(xml, /<PARTYLEDGERNAME>Vendor Alpha<\/PARTYLEDGERNAME>/);
});
