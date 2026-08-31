const test = require('node:test');
const assert = require('node:assert');
const { buildDeliveryNoteXml } = require('../dist/tally/voucherBuilders/deliveryNote.js');

const COMPANY = 'SWAASHPET POLYMERS PVT LTD';

/**
 * Executable contract for the Delivery Note builder.
 *
 * THIS IS THE ONE BUILDER WITH NO REAL EXPORT BEHIND IT. The factory's Tally
 * contains ZERO Delivery Note vouchers and none of its 177 real Sales vouchers
 * references one; the ERP posts them by owner decision (DEC-20260831-004), so
 * this is the INTRODUCTION of a practice. The structure is reasoned from the
 * Sales voucher's inventory block, and the first live post is the real check.
 *
 * These tests therefore pin what CAN be pinned without an export: that it is an
 * inventory-only voucher carrying no money, that it names the customer's TALLY
 * ledger rather than the ERP's label, that it never fabricates Tally's identity
 * fields, and that it refuses to build for the wrong company.
 */
function payload(overrides = {}) {
    return {
        voucher_type: 'Delivery Note',
        voucher_date: '2026-08-31',
        voucher_number: 'DN-3',
        party_ledger: 'Sangam Pharma Packers',
        party_gstin: '33ABVFS0946B1Z5',
        godown: COMPANY,
        narration: null,
        allowed_company: COMPANY,
        lines: [{ item: 'B.500 Ml Round Pet Bottle Amber - 36gms', quantity: '980.0000', uom: 'Nos' }],
        ...overrides,
    };
}

test('it is an INVENTORY voucher — no money anywhere in it', () => {
    const xml = buildDeliveryNoteXml(payload(), COMPANY);

    // The bill is the Sales voucher's job. A Delivery Note that carried a rate
    // or an amount would be a second, disagreeing statement of the sale.
    for (const money of ['<RATE>', '<AMOUNT>', 'LEDGERENTRIES', 'ACCOUNTINGALLOCATIONS']) {
        assert.ok(!xml.includes(money), `a Delivery Note must carry no ${money}`);
    }
    assert.match(xml, /<ISINVOICE>No<\/ISINVOICE>/);
});

test('stock goes OUT, with its godown and its unit', () => {
    const xml = buildDeliveryNoteXml(payload(), COMPANY);

    assert.match(xml, /<VOUCHERTYPENAME>Delivery Note<\/VOUCHERTYPENAME>/);
    assert.match(xml, /<ISDEEMEDPOSITIVE>No<\/ISDEEMEDPOSITIVE>/);
    assert.match(xml, /<ACTUALQTY>980\.0000 Nos\.<\/ACTUALQTY>/);
    assert.match(xml, new RegExp(`<GODOWNNAME>${COMPANY}</GODOWNNAME>`));
});

test('a line with no unit still builds, carrying the bare quantity', () => {
    const xml = buildDeliveryNoteXml(payload({ lines: [{ item: 'X', quantity: '5.0000', uom: null }] }), COMPANY);

    assert.match(xml, /<ACTUALQTY>5\.0000<\/ACTUALQTY>/);
});

test('the party is the customer\'s TALLY ledger, in every place the voucher names them', () => {
    const xml = buildDeliveryNoteXml(payload(), COMPANY);

    for (const tag of ['PARTYLEDGERNAME', 'PARTYNAME', 'BASICBUYERNAME']) {
        assert.match(xml, new RegExp(`<${tag}>Sangam Pharma Packers</${tag}>`));
    }
    assert.match(xml, /<PARTYGSTIN>33ABVFS0946B1Z5<\/PARTYGSTIN>/);
});

test('an unregistered party omits PARTYGSTIN rather than emitting a blank one', () => {
    const xml = buildDeliveryNoteXml(payload({ party_gstin: null }), COMPANY);

    assert.ok(!xml.includes('<PARTYGSTIN>'));
});

test("Tally's own identity and the portal's e-invoice fields are never fabricated", () => {
    const xml = buildDeliveryNoteXml(payload(), COMPANY);

    for (const forbidden of ['REMOTEID', 'VCHKEY', '<GUID>', 'ALTERID', 'MASTERID', '<IRN>']) {
        assert.ok(!xml.includes(forbidden), `${forbidden} is Tally's or the IRP's, never ours`);
    }
    assert.match(xml, /ACTION="Create"/);
});

test('a payload with no allowed_company refuses to build', () => {
    const legacy = payload();
    delete legacy.allowed_company;
    assert.throws(() => buildDeliveryNoteXml(legacy, COMPANY), /no allowed_company/);
});

test('a mismatched allowed_company refuses — the export itself came from a "... Testing" company', () => {
    assert.throws(
        () => buildDeliveryNoteXml(payload({ allowed_company: `${COMPANY} Testing` }), COMPANY),
        /does not match/,
    );
});
