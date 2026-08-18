/**
 * Executable contract for the consolidated shift Stock Journal (v0.3.5).
 *
 * WHY THIS FILE IS PLAIN JAVASCRIPT AGAINST dist/. tsconfig.json pins
 * rootDir to src and includes only src/**, so a .ts test outside src cannot
 * compile without moving rootDir — which would change the dist layout that
 * package.json's `main: dist/main.js` and electron-builder's `files` both
 * depend on. Testing the COMPILED output instead needs no tsconfig change, no
 * second config, and no new dependency: `npm test` builds, then runs this
 * with node:test. It also tests the artifact that actually ships.
 *
 * The whole voucherBuilders tree is runtime-dependency-free — every import is
 * relative, and the dispatcher's TallySyncEntry is a type-only import erased
 * at compile time — so requiring dist here pulls in no electron and no axios.
 *
 * WHAT THIS GUARDS. The 07-Aug-2026 outage was entries #33/#34 failing with
 * "No XML builder" because the server labelled a voucher 'Stock Journal'
 * while the fleet's agent had no such case. The dispatch test below is that
 * regression. The shape tests pin FC-04 and the ISDEEMEDPOSITIVE rule the
 * builder's own docblock calls load-bearing: the tag name does not decide the
 * column, ISDEEMEDPOSITIVE does.
 *
 * Nothing here contacts Tally. These are pure string builders.
 */

const test = require('node:test');
const assert = require('node:assert/strict');

const { buildStockJournalXml } = require('../dist/tally/voucherBuilders/stockJournal.js');
const { buildVoucherXml } = require('../dist/tally/voucherBuilders/index.js');

const COMPANY = 'SWAASHPET POLYMERS PVT LTD';

/** A payload shaped like TallySyncService's consolidated shift voucher. */
function payload(overrides = {}) {
    return {
        voucher_type: 'Stock Journal',
        voucher_date: '2026-08-07',
        voucher_number: 'SJ-20260807-S3',
        narration: 'Shift C consolidated production',
        godown: 'Factory Day Bin',
        consumed: [{ item: 'Relpet G5801M', quantity: '307.3400' }],
        produced: [{ item: 'L. 500 ml Kidney RIB clear Pet', quantity: '13363' }],
        ...overrides,
    };
}

/** Every <INVENTORYENTRIESIN.LIST> / ...OUT.LIST block, whole. */
function entries(xml, side) {
    const re = new RegExp(
        `<INVENTORYENTRIES${side}\\.LIST>[\\s\\S]*?</INVENTORYENTRIES${side}\\.LIST>`,
        'g',
    );
    return xml.match(re) ?? [];
}

test('the dispatcher accepts the voucher type the server actually labels', () => {
    const xml = buildVoucherXml(
        { id: 33, tally_voucher_type: 'Stock Journal', payload: payload() },
        COMPANY,
    );

    assert.match(xml, /<VOUCHERTYPENAME>Stock Journal<\/VOUCHERTYPENAME>/);
    // The exact failure of entries #33/#34, now impossible to reintroduce silently.
    assert.doesNotMatch(xml, /No XML builder/);
});

test('an unknown voucher type still fails loudly, naming the type and the entry', () => {
    // 'Payment' is a voucher type the ERP never builds (it lives in Tally
    // only — TallyTransactionCategory::Payment). This case used to name
    // 'Purchase Order' as the canonical unknown; since 0.3.9 that type HAS a
    // builder (purchaseOrder.ts, Phase 6), so the unknown moved here.
    assert.throws(
        () => buildVoucherXml({ id: 41, tally_voucher_type: 'Payment', payload: {} }, COMPANY),
        /No XML builder for voucher type "Payment" \(entry #41\)/,
    );
});

test('voucher type, date, number and narration are encoded', () => {
    const xml = buildStockJournalXml(payload(), COMPANY);

    assert.match(xml, /<VOUCHER VCHTYPE="Stock Journal" ACTION="Create">/);
    assert.match(xml, /<VOUCHERTYPENAME>Stock Journal<\/VOUCHERTYPENAME>/);
    // Tally wants YYYYMMDD with no separators.
    assert.match(xml, /<DATE>20260807<\/DATE>/);
    assert.match(xml, /<VOUCHERNUMBER>SJ-20260807-S3<\/VOUCHERNUMBER>/);
    assert.match(xml, /<NARRATION>Shift C consolidated production<\/NARRATION>/);
    assert.match(xml, /<SVCURRENTCOMPANY>SWAASHPET POLYMERS PVT LTD<\/SVCURRENTCOMPANY>/);
});

test('a null narration encodes as empty rather than the string "null"', () => {
    const xml = buildStockJournalXml(payload({ narration: null }), COMPANY);

    assert.match(xml, /<NARRATION><\/NARRATION>/);
    assert.doesNotMatch(xml, /<NARRATION>null<\/NARRATION>/);
});

test('consumed lines go OUT with ISDEEMEDPOSITIVE No — stock decreases', () => {
    const xml = buildStockJournalXml(payload(), COMPANY);
    const out = entries(xml, 'OUT');

    assert.equal(out.length, 1);
    assert.match(out[0], /<STOCKITEMNAME>Relpet G5801M<\/STOCKITEMNAME>/);
    assert.match(out[0], /<ISDEEMEDPOSITIVE>No<\/ISDEEMEDPOSITIVE>/);
    assert.match(out[0], /<ACTUALQTY>307\.3400<\/ACTUALQTY>/);
    assert.match(out[0], /<BILLEDQTY>307\.3400<\/BILLEDQTY>/);
});

test('produced lines go IN with ISDEEMEDPOSITIVE Yes — stock increases', () => {
    const xml = buildStockJournalXml(payload(), COMPANY);
    const inn = entries(xml, 'IN');

    assert.equal(inn.length, 1);
    assert.match(inn[0], /<STOCKITEMNAME>L\. 500 ml Kidney RIB clear Pet<\/STOCKITEMNAME>/);
    assert.match(inn[0], /<ISDEEMEDPOSITIVE>Yes<\/ISDEEMEDPOSITIVE>/);
    assert.match(inn[0], /<ACTUALQTY>13363<\/ACTUALQTY>/);
});

test('the two sides never share a direction — ISDEEMEDPOSITIVE decides the column, not the tag', () => {
    const xml = buildStockJournalXml(payload(), COMPANY);

    // Whatever the tag names are, no OUT block may claim Yes and no IN block No.
    for (const block of entries(xml, 'OUT')) {
        assert.doesNotMatch(block, /<ISDEEMEDPOSITIVE>Yes<\/ISDEEMEDPOSITIVE>/);
    }
    for (const block of entries(xml, 'IN')) {
        assert.doesNotMatch(block, /<ISDEEMEDPOSITIVE>No<\/ISDEEMEDPOSITIVE>/);
    }
});

test('a per-line godown wins over the voucher-level one, on BOTH sides', () => {
    const xml = buildStockJournalXml(
        payload({
            godown: 'Factory Day Bin',
            consumed: [{ item: 'Relpet G5801M', quantity: '10', godown: 'Packing Material Store' }],
            produced: [{ item: 'Bottle', quantity: '20', godown: 'Finished Goods' }],
        }),
        COMPANY,
    );

    assert.match(entries(xml, 'OUT')[0], /<GODOWNNAME>Packing Material Store<\/GODOWNNAME>/);
    assert.match(entries(xml, 'IN')[0], /<GODOWNNAME>Finished Goods<\/GODOWNNAME>/);
    // The voucher-level fallback must not leak onto a line that named its own.
    assert.doesNotMatch(xml, /<GODOWNNAME>Factory Day Bin<\/GODOWNNAME>/);
});

test('the voucher-level godown is the fallback when a line names none', () => {
    const xml = buildStockJournalXml(
        payload({
            godown: 'Factory Day Bin',
            consumed: [{ item: 'Relpet G5801M', quantity: '10' }],
            produced: [{ item: 'Bottle', quantity: '20', godown: 'Finished Goods' }],
        }),
        COMPANY,
    );

    assert.match(entries(xml, 'OUT')[0], /<GODOWNNAME>Factory Day Bin<\/GODOWNNAME>/);
    assert.match(entries(xml, 'IN')[0], /<GODOWNNAME>Finished Goods<\/GODOWNNAME>/);
});

test('with no godown anywhere, the line carries no batch allocation at all', () => {
    const xml = buildStockJournalXml(
        payload({
            godown: null,
            consumed: [{ item: 'Relpet G5801M', quantity: '10' }],
            produced: [{ item: 'Bottle', quantity: '20' }],
        }),
        COMPANY,
    );

    // A guessed godown would be a wrong voucher; the builder omits the block.
    assert.doesNotMatch(xml, /BATCHALLOCATIONS\.LIST/);
    assert.doesNotMatch(xml, /GODOWNNAME/);
});

test('several lines a side each get their own entry', () => {
    const xml = buildStockJournalXml(
        payload({
            consumed: [
                { item: 'Relpet G5801M', quantity: '300' },
                { item: 'Master Batch Amber', quantity: '4.275' },
            ],
            produced: [
                { item: 'Bottle', quantity: '13000' },
                { item: 'PET Scrap - Amber', quantity: '12.5' },
            ],
        }),
        COMPANY,
    );

    assert.equal(entries(xml, 'OUT').length, 2);
    // FC-02: scrap is produced stock, booked inward beside the good bottles.
    assert.equal(entries(xml, 'IN').length, 2);
    assert.match(entries(xml, 'IN')[1], /<STOCKITEMNAME>PET Scrap - Amber<\/STOCKITEMNAME>/);
});

test('XML-significant characters in item names and narration are escaped', () => {
    const xml = buildStockJournalXml(
        payload({
            narration: 'Shift C <handover> & "sign-off"',
            consumed: [{ item: 'Resin & Co <grade>', quantity: '1', godown: "Bay 'A'" }],
            produced: [{ item: '500ML "KIDNEY"', quantity: '2' }],
        }),
        COMPANY,
    );

    assert.match(xml, /<NARRATION>Shift C &lt;handover&gt; &amp; &quot;sign-off&quot;<\/NARRATION>/);
    assert.match(xml, /<STOCKITEMNAME>Resin &amp; Co &lt;grade&gt;<\/STOCKITEMNAME>/);
    assert.match(xml, /<STOCKITEMNAME>500ML &quot;KIDNEY&quot;<\/STOCKITEMNAME>/);
    assert.match(xml, /<GODOWNNAME>Bay &apos;A&apos;<\/GODOWNNAME>/);

    // No raw delimiter may survive inside a text node, or Tally sees malformed
    // XML. Strip the legitimate entities first, then nothing significant may
    // remain — including a bare "&" that never opened an entity.
    const textNodes = (xml.match(/>[^<>]+</g) ?? []).map((n) => n.slice(1, -1));
    for (const node of textNodes) {
        const withoutEntities = node.replace(/&(?:amp|lt|gt|quot|apos|#\d+);/g, '');
        assert.doesNotMatch(withoutEntities, /[&<>"']/, `unescaped character in: ${node}`);
    }
});

test('non-ASCII is emitted as numeric references, not raw UTF-8', () => {
    // Verified against the client's TallyPrime (SPE-3): raw UTF-8 renders as
    // mojibake because Tally does not treat the body as UTF-8.
    const xml = buildStockJournalXml(
        payload({ narration: 'Shift C — night', consumed: [], produced: [] }),
        COMPANY,
    );

    assert.match(xml, /<NARRATION>Shift C &#8212; night<\/NARRATION>/);
    assert.doesNotMatch(xml, /—/);
});
