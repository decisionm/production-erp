/**
 * Executable contract for the Day Book purchase-rate reader (Phase 6.5).
 *
 * Plain JavaScript against dist/ for the reason the other agent tests give:
 * tsconfig pins rootDir to src, so testing the COMPILED output needs no second
 * config and tests the artifact that ships. The parser is
 * runtime-dependency-free apart from fast-xml-parser — requiring dist here
 * pulls in no electron.
 *
 * EVERY VALUE BELOW IS SYNTHETIC (FC-06). "SYNTHETIC SUPPLIES", "ITEM_A", rate
 * 674.000, a made-up GSTIN and ledger names, dates in 2026. No real rate,
 * vendor, GSTIN, Tally item name or voucher number appears here. The STRUCTURE
 * — the tag tree, the `674.000/Kgs.` rate spelling, the RATEDETAILS duty
 * heads, the ALLINVENTORYENTRIES nesting — is what was MEASURED on the
 * factory's own exports (107 Purchase Order vouchers 12-Aug, 17 Purchase
 * vouchers 24-Aug, read locally and never committed — Q38). The values are
 * not.
 *
 * WHAT THIS GUARDS, in one line each:
 *   · the rate keeps its BASIS, because a bare number on the wrong unit
 *     silently restates the price of a real order (Q40);
 *   · a cancelled, deleted or optional voucher never supplies a rate (Q39
 *     names voucher 72 of the 92 as the cancelled one);
 *   · GST stays per voucher line and never becomes a per-item rate (Q39);
 *   · nothing here posts to Tally — it is an export reader and only that.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    parseDayBook,
    parseRate,
    parseQuantity,
    parseTallyDate,
    linesOfVoucher,
} = require('../dist/tally/purchaseRates');

/** A synthetic Day Book export, in the shape a real one arrives in. */
function dayBook(vouchers) {
    return (
        '<ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><IMPORTDATA>' +
        '<REQUESTDESC><REPORTNAME>Day Book</REPORTNAME></REQUESTDESC><REQUESTDATA>' +
        vouchers.map((v) => `<TALLYMESSAGE>${v}</TALLYMESSAGE>`).join('') +
        '</REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>'
    );
}

function inventoryEntry({ item = 'ITEM_A', rate = '674.000/Kgs.', qty = ' 48.000 Kgs.', amount = '-32352.000', hsn = '39076190', ledger = 'Interstate Purchase Taxable', gst = true } = {}) {
    return (
        '<ALLINVENTORYENTRIES.LIST>' +
        `<STOCKITEMNAME>${item}</STOCKITEMNAME>` +
        `<GSTHSNNAME>${hsn}</GSTHSNNAME>` +
        (rate === null ? '' : `<RATE>${rate}</RATE>`) +
        `<AMOUNT>${amount}</AMOUNT>` +
        `<ACTUALQTY>${qty}</ACTUALQTY>` +
        `<BILLEDQTY>${qty}</BILLEDQTY>` +
        `<ACCOUNTINGALLOCATIONS.LIST><LEDGERNAME>${ledger}</LEDGERNAME><AMOUNT>${amount}</AMOUNT></ACCOUNTINGALLOCATIONS.LIST>` +
        (gst
            ? '<RATEDETAILS.LIST><GSTRATEDUTYHEAD>CGST</GSTRATEDUTYHEAD><GSTRATE> 9</GSTRATE></RATEDETAILS.LIST>' +
              '<RATEDETAILS.LIST><GSTRATEDUTYHEAD>SGST/UTGST</GSTRATEDUTYHEAD><GSTRATE> 9</GSTRATE></RATEDETAILS.LIST>' +
              '<RATEDETAILS.LIST><GSTRATEDUTYHEAD>IGST</GSTRATEDUTYHEAD><GSTRATE> 18</GSTRATE></RATEDETAILS.LIST>' +
              '<RATEDETAILS.LIST><GSTRATEDUTYHEAD>Cess</GSTRATEDUTYHEAD><GSTRATEVALUATIONTYPE>&#4; Not Applicable</GSTRATEVALUATIONTYPE></RATEDETAILS.LIST>'
            : '') +
        '</ALLINVENTORYENTRIES.LIST>'
    );
}

function voucher({ type = 'Purchase Order', guid = 'guid-0001', date = '20260701', number = '77', reference = 'REF-77', party = 'SYNTHETIC SUPPLIES', gstin = '33AAAAA0000A1ZA', flags = {}, entries = [inventoryEntry()] } = {}) {
    const flag = (name) => `<${name}>${flags[name] ?? 'No'}</${name}>`;

    return (
        `<VOUCHER VCHTYPE="${type}" ACTION="Create">` +
        `<GUID>${guid}</GUID><DATE>${date}</DATE>` +
        `<VOUCHERTYPENAME>${type}</VOUCHERTYPENAME>` +
        `<VOUCHERNUMBER>${number}</VOUCHERNUMBER><REFERENCE>${reference}</REFERENCE>` +
        `<PARTYLEDGERNAME>${party}</PARTYLEDGERNAME><PARTYNAME>${party}</PARTYNAME>` +
        `<PARTYGSTIN>${gstin}</PARTYGSTIN>` +
        flag('ISCANCELLED') + flag('ISDELETED') + flag('ISOPTIONAL') +
        entries.join('') +
        '</VOUCHER>'
    );
}

// ── The rate keeps its basis ─────────────────────────────────────────────

test('a rate is split into its value and the unit it is quoted per', () => {
    assert.deepEqual(parseRate('674.000/Kgs.'), { value: 674, unit: 'Kgs.' });
    assert.deepEqual(parseRate('57.890/Nos.'), { value: 57.89, unit: 'Nos.' });
});

test('a rate with no unit yields a null unit rather than an assumed one', () => {
    // The cloud refuses to prefill from this. "No basis recorded" must stay
    // distinguishable from "the same basis".
    assert.deepEqual(parseRate('674.000'), { value: 674, unit: null });
});

test('an unreadable rate is null, never zero — zero would read as free', () => {
    assert.deepEqual(parseRate(''), { value: null, unit: null });
    assert.deepEqual(parseRate('n/a'), { value: null, unit: null });
});

test('a quantity is split from its unit, leading space and all', () => {
    assert.deepEqual(parseQuantity(' 48.000 Kgs.'), { value: 48, unit: 'Kgs.' });
    assert.deepEqual(parseQuantity(' 12,000.000 Kgs.'), { value: 12000, unit: 'Kgs.' });
});

test("Tally's yyyymmdd becomes an ISO date, and anything else is null", () => {
    assert.equal(parseTallyDate('20260701'), '2026-07-01');
    assert.equal(parseTallyDate('1-Jul-26'), null);
    assert.equal(parseTallyDate(''), null);
});

// ── What is read ─────────────────────────────────────────────────────────

test('a purchase order voucher yields a quotable line with its rate, unit, party and reference', () => {
    const [line] = parseDayBook(dayBook([voucher()]));

    assert.equal(line.voucher_type, 'purchase_order');
    assert.equal(line.voucher_guid, 'guid-0001');
    assert.equal(line.voucher_date, '2026-07-01');
    assert.equal(line.voucher_number, '77');
    assert.equal(line.voucher_reference, 'REF-77');
    assert.equal(line.party_ledger_name, 'SYNTHETIC SUPPLIES');
    assert.equal(line.party_gstin, '33AAAAA0000A1ZA');
    assert.equal(line.stock_item_name, 'ITEM_A');
    assert.equal(line.rate_value, 674);
    assert.equal(line.rate_unit, 'Kgs.');
    assert.equal(line.quantity, 48);
    assert.equal(line.quantity_unit, 'Kgs.');
    assert.equal(line.hsn_code, '39076190');
    assert.equal(line.purchase_ledger_name, 'Interstate Purchase Taxable');
});

test('a purchase invoice is read as its own kind, not folded in with orders', () => {
    const lines = parseDayBook(dayBook([
        voucher({ type: 'Purchase Order', guid: 'g-po' }),
        voucher({ type: 'Purchase', guid: 'g-pi' }),
    ]));

    assert.deepEqual(lines.map((l) => l.voucher_type), ['purchase_order', 'purchase_invoice']);
});

test('the GST of the line is carried per duty head, and Cess with no rate stays null', () => {
    const [line] = parseDayBook(dayBook([voucher()]));

    assert.equal(line.cgst_rate, 9);
    assert.equal(line.sgst_rate, 9);
    assert.equal(line.igst_rate, 18);
    // Q39: the ERP must NOT hold one GST rate per item. Nothing here is a
    // per-item tax master; this is what THAT voucher carried on THAT date.
    assert.equal(line.cess_rate, null);
});

test("the line's amount is its magnitude, not Tally's double-entry sign", () => {
    const [line] = parseDayBook(dayBook([voucher()]));

    assert.equal(line.amount, 32352);
});

// ── What is dropped, and why ─────────────────────────────────────────────

for (const flag of ['ISCANCELLED', 'ISDELETED', 'ISOPTIONAL']) {
    test(`a voucher Tally marks ${flag} supplies no rate at all`, () => {
        const lines = parseDayBook(dayBook([voucher({ flags: { [flag]: 'Yes' } })]));

        assert.deepEqual(lines, []);
    });
}

test('a voucher of any other type is ignored — the Day Book carries everything', () => {
    const lines = parseDayBook(dayBook([
        voucher({ type: 'Sales', guid: 'g-sales' }),
        voucher({ type: 'Receipt', guid: 'g-receipt' }),
        voucher({ type: 'Stock Journal', guid: 'g-journal' }),
        voucher({ type: 'Purchase', guid: 'g-keep' }),
    ]));

    assert.deepEqual(lines.map((l) => l.voucher_guid), ['g-keep']);
});

test('a "Purchase Return" is NOT read as a purchase', () => {
    // Matched on the whole type name, never a substring — a return quoted as
    // a purchase would be a negative rate presented as evidence.
    assert.deepEqual(parseDayBook(dayBook([voucher({ type: 'Purchase Return' })])), []);
});

test('an inventory line with no rate is dropped, and does not renumber the others', () => {
    const lines = parseDayBook(dayBook([voucher({
        entries: [
            inventoryEntry({ item: 'ITEM_A' }),
            inventoryEntry({ item: 'ITEM_NO_RATE', rate: null }),
            inventoryEntry({ item: 'ITEM_C' }),
        ],
    })]));

    assert.deepEqual(lines.map((l) => l.stock_item_name), ['ITEM_A', 'ITEM_C']);
    // The index is the line's POSITION in the voucher and is half the cloud's
    // identity for the row. Renumbering around the gap would make ITEM_C
    // change identity the day someone fills that rate in.
    assert.deepEqual(lines.map((l) => l.line_index), [0, 2]);
});

test('a voucher with no party is dropped rather than attributed to nobody', () => {
    assert.deepEqual(parseDayBook(dayBook([voucher({ party: '' })])), []);
});

test('a voucher with an unreadable date is dropped rather than dated today', () => {
    assert.deepEqual(parseDayBook(dayBook([voucher({ date: '1-Jul-26' })])), []);
});

test('an empty or voucher-less export reads as no lines, not as an error', () => {
    assert.deepEqual(parseDayBook(dayBook([])), []);
    assert.deepEqual(parseDayBook('<ENVELOPE></ENVELOPE>'), []);
});

test('a single voucher node (not an array) is handled — Tally omits the array for one', () => {
    // fast-xml-parser hands back an object rather than a list when there is
    // exactly one. Every real one-voucher day would break a naive .map().
    assert.equal(parseDayBook(dayBook([voucher()])).length, 1);
});

test('linesOfVoucher is pure — it contacts nothing and posts nothing', () => {
    // The whole module is an export READER. There is no post path in it at
    // all, and this pins that the parse works on a plain object with no I/O.
    const parsed = require('fast-xml-parser');
    const p = new parsed.XMLParser({ ignoreAttributes: false, parseTagValue: false, trimValues: true });
    const node = p.parse(voucher()).VOUCHER;

    assert.equal(linesOfVoucher(node).length, 1);
});
