/**
 * THE PRODUCTION STOCK JOURNAL, VALIDATED AGAINST A REAL TALLY EXPORT.
 *
 * stockJournal.test.js pins what the builder emits. This file pins that what
 * it emits is the shape the factory's own Tally actually writes — the half no
 * self-consistent test can reach, and the half FC-04 is about.
 *
 * THE EVIDENCE. tests/fixtures/tally-stock-journal-real.xml is voucher #104
 * (13-Jul-2026) lifted verbatim from the factory's Stock Journal export
 * (~/Downloads/test_stock_journal_entry.xml, UTF-16, 34 vouchers, read
 * 01-Sep-2026), with three classes of value neutralised and NOTHING else
 * touched:
 *
 *   RATE / AMOUNT   zeroed. AGENTS.md: purchase rates never reach
 *                   documentation, and FC-06 makes them Owner/Accounts only.
 *                   The export books Relpet at the real per-kg rate; that
 *                   figure does not belong in git.
 *   GUID / VCHKEY / the live company file's own identity.
 *   REMOTEID
 *
 * Everything the assertions below read — element names, nesting, the
 * direction convention, item names, quantities, godown — is the factory's
 * unmodified data. The source export itself is deliberately NOT committed: it
 * is manifest entry `tally-testing-xml-2026-08-26`, status `external`, and
 * permission to commit it is PENDING Q13. An open question is not a fact.
 *
 * WHY A WHOLE VOUCHER AND NOT A TRIMMED ONE. The boilerplate is the point. A
 * hand-cut fixture would encode this agent's belief about which elements
 * matter, which is the belief under test.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { buildStockJournalXml } = require('../dist/tally/voucherBuilders/stockJournal.js');

const COMPANY = 'SWAASHPET POLYMERS PVT LTD';
const REAL = fs.readFileSync(
    path.join(__dirname, 'fixtures', 'tally-stock-journal-real.xml'),
    'utf8',
);

/** Every <INVENTORYENTRIESIN.LIST> / ...OUT.LIST block that names an item. */
function entries(xml, side) {
    const re = new RegExp(
        `<INVENTORYENTRIES${side}\\.LIST>[\\s\\S]*?</INVENTORYENTRIES${side}\\.LIST>`,
        'g',
    );
    return (xml.match(re) ?? []).filter((block) => block.includes('<STOCKITEMNAME>'));
}

const itemOf = (block) => block.match(/<STOCKITEMNAME>([\s\S]*?)<\/STOCKITEMNAME>/)[1].trim();
const deemedOf = (block) => block.match(/<ISDEEMEDPOSITIVE>(.*?)<\/ISDEEMEDPOSITIVE>/)?.[1];

// ---------------------------------------------------------------------------
// What the factory's Tally actually writes.
// ---------------------------------------------------------------------------

test('the real voucher is a Stock Journal in the consumption view', () => {
    assert.match(REAL, /<VOUCHER [^>]*VCHTYPE="Stock Journal"/);
    assert.match(REAL, /<VOUCHERTYPENAME>Stock Journal<\/VOUCHERTYPENAME>/);
    // YYYYMMDD, no separators — what toTallyDate() must produce.
    assert.match(REAL, /<DATE>20260713<\/DATE>/);
});

test('the real voucher carries inventory on INVENTORYENTRIESIN/OUT, never ALLINVENTORYENTRIES', () => {
    // A Sales or Purchase voucher uses ALLINVENTORYENTRIES.LIST. A Stock
    // Journal does not, and a builder that emitted one would produce a
    // voucher Tally accepts into the wrong view.
    assert.ok(entries(REAL, 'IN').length > 0);
    assert.ok(entries(REAL, 'OUT').length > 0);
    assert.doesNotMatch(REAL, /<ALLINVENTORYENTRIES\.LIST>/);
});

test('ISDEEMEDPOSITIVE and the tag agree on every real line — Yes on IN, No on OUT', () => {
    // The load-bearing rule in stockJournal.ts's docblock, checked against the
    // factory's own data rather than against our restatement of it.
    for (const block of entries(REAL, 'IN')) {
        assert.equal(deemedOf(block), 'Yes', `IN line ${itemOf(block)}`);
    }
    for (const block of entries(REAL, 'OUT')) {
        assert.equal(deemedOf(block), 'No', `OUT line ${itemOf(block)}`);
    }
});

test('FC-04: produced goods go IN; resin, masterbatch and packing go OUT', () => {
    const inNames = entries(REAL, 'IN').map(itemOf);
    const outNames = entries(REAL, 'OUT').map(itemOf);

    // Bottles are produced.
    assert.ok(inNames.some((n) => /Pet Bottle/i.test(n)), inNames.join(' | '));

    // DEC-20260805-002: Relpet is the real purchased resin. DEC-20260806-004:
    // the amber masterbatch is 'Master Batch Amber'. Both are consumed.
    assert.ok(outNames.includes('Relpet'), outNames.join(' | '));
    assert.ok(outNames.includes('Master Batch Amber'), outNames.join(' | '));

    // Packing: master boxes and trays issue on the OUT side.
    assert.ok(outNames.some((n) => /Master Box/i.test(n)), outNames.join(' | '));
    assert.ok(outNames.some((n) => /Tray/i.test(n)), outNames.join(' | '));

    // No produced bottle may appear on the consumption side, and no resin on
    // the production side — the inversion FC-04 exists to prevent.
    assert.ok(!outNames.some((n) => /Pet Bottle/i.test(n)));
    assert.ok(!inNames.includes('Relpet'));
});

test('FC-02: Pet Scrap is booked INWARD beside the good bottles, not discarded', () => {
    const inNames = entries(REAL, 'IN').map(itemOf);
    const outNames = entries(REAL, 'OUT').map(itemOf);

    assert.ok(inNames.includes('Pet Scrap'), inNames.join(' | '));
    assert.ok(!outNames.includes('Pet Scrap'));
});

test('FC-03: the real voucher books packing tape in Nos, never in metres', () => {
    const tape = entries(REAL, 'OUT').find((block) => /Packing Tape/i.test(itemOf(block)));
    assert.ok(tape, 'the fixture must carry a tape line');

    // 'Nos.' is the unit FC-03 protects. A metre figure filed as Nos is a
    // different number about a different thing — it reached live once.
    assert.match(tape, /<ACTUALQTY>[^<]*Nos\.[^<]*<\/ACTUALQTY>/);
    assert.doesNotMatch(tape, /<ACTUALQTY>[^<]*(?:Mtr|Metre|Meter)/i);
});

test('DEC-20260830-002: every line books to the one operational godown', () => {
    const godowns = new Set(
        [...REAL.matchAll(/<GODOWNNAME>([\s\S]*?)<\/GODOWNNAME>/g)].map((m) => m[1].trim()),
    );
    assert.deepEqual([...godowns], ['SWAASHPET POLYMERS PVT LTD']);
});

test('the committed fixture carries no purchase rate — FC-06, and it must stay that way', () => {
    // Guards the fixture itself: a future refresh from the live export must
    // neutralise rates again. Every RATE/AMOUNT is zeroed.
    for (const m of REAL.matchAll(/<RATE>([^<]*)<\/RATE>/g)) {
        assert.match(m[1], /^0\.000(\/.*)?$/, `RATE leaked: ${m[1]}`);
    }
    for (const m of REAL.matchAll(/<AMOUNT>([^<]*)<\/AMOUNT>/g)) {
        assert.equal(m[1].trim(), '0.000', `AMOUNT leaked: ${m[1]}`);
    }
});

test('no element other than a quantity or a name carries a figure at all', () => {
    // The class, not two names. Tally scatters money across BASICRATE*,
    // CLASSRATE, DISCOUNT and the additional-cost lists, and a refresh from a
    // richer voucher could reintroduce a rate under an element this file has
    // never seen. So the rule is stated positively: only quantities and names
    // may carry a non-zero number.
    const allowed = /QTY$|^STOCKITEMNAME$|^BATCHNAME$|^GODOWNNAME$|^VOUCHERNUMBER$|^NARRATION$/;

    const leaked = [];
    for (const m of REAL.matchAll(/<([A-Z][A-Z0-9.]*)>([^<]*[1-9][0-9]*\.[0-9]+[^<]*)<\/\1>/g)) {
        if (!allowed.test(m[1])) {
            leaked.push(`${m[1]} = ${m[2].trim()}`);
        }
    }

    assert.deepEqual(leaked, [], `money may have leaked into the fixture:\n${leaked.join('\n')}`);
});

test('the fixture names no supplier and no ledger — the other half of FC-06', () => {
    // FC-06 protects supplier identity as well as rates. A Stock Journal
    // should carry neither; assert it rather than assume it.
    assert.doesNotMatch(REAL, /<LEDGERNAME>/);
    assert.doesNotMatch(REAL, /<PARTYNAME>/);
    assert.doesNotMatch(REAL, /<PARTYLEDGERNAME>/);
});

test('the live company file is not identifiable from the fixture', () => {
    // GUID / VCHKEY / REMOTEID were neutralised to all-zero forms.
    for (const m of REAL.matchAll(/<GUID>([^<]*)<\/GUID>/g)) {
        assert.match(m[1], /^0{8}-0{4}-0{4}-0{4}-0{12}-0{8}$/, `GUID leaked: ${m[1]}`);
    }
    assert.doesNotMatch(REAL, /REMOTEID="(?!0{8}-)/);
});

// ---------------------------------------------------------------------------
// What we emit, held against it.
// ---------------------------------------------------------------------------

/**
 * The builder fed the real voucher's own lines. If our output disagrees with
 * the export on element names, nesting or direction, this is where it shows.
 */
function rebuiltFromReal() {
    return buildStockJournalXml(
        {
            voucher_type: 'Stock Journal',
            voucher_date: '2026-07-13',
            voucher_number: '104',
            narration: null,
            godown: 'SWAASHPET POLYMERS PVT LTD',
            produced: entries(REAL, 'IN').map((b) => ({ item: itemOf(b), quantity: '1' })),
            consumed: entries(REAL, 'OUT').map((b) => ({ item: itemOf(b), quantity: '1' })),
        },
        COMPANY,
    );
}

test('our voucher uses the same elements the real one does', () => {
    const ours = rebuiltFromReal();

    for (const element of [
        'VOUCHERTYPENAME',
        'VOUCHERNUMBER',
        'DATE',
        'STOCKITEMNAME',
        'ISDEEMEDPOSITIVE',
        'ACTUALQTY',
        'BILLEDQTY',
        'BATCHALLOCATIONS.LIST',
        'GODOWNNAME',
        'BATCHNAME',
    ]) {
        assert.ok(REAL.includes(`<${element}>`), `real export lacks ${element}`);
        assert.ok(ours.includes(`<${element}>`), `our builder omits ${element}`);
    }
});

// ---------------------------------------------------------------------------
// PENDING Q90 — the three vouchers that are NOT shift production.
// ---------------------------------------------------------------------------

/**
 * Three of the export's 34 are not shift production, and what they ARE is an
 * open owner question (Q90). Until it is answered the case is held FAIL-CLOSED:
 * nothing may classify them as production, and anything counting production
 * vouchers must exclude them.
 *
 * These tests pin the shapes so the exclusion is executable rather than
 * remembered. The fixture is voucher 104 — a real production journal — so what
 * is asserted is the POSITIVE shape a production voucher has, which is exactly
 * the predicate a future inbound reader must require before treating any
 * voucher as production.
 */
test('a production journal has BOTH sides — the predicate Q90 holds the line on', () => {
    // Voucher 115 has an IN side and no OUT side at all. A reader that assumed
    // both sides are populated would book an inward quantity against no
    // consumption and call it a shift's output.
    assert.ok(entries(REAL, 'IN').length > 0, 'production journal must produce something');
    assert.ok(entries(REAL, 'OUT').length > 0, 'production journal must consume something');
});

test('a production journal is not an equal-quantity reclassification', () => {
    // Vouchers 114 and 116 are chips OUT -> scrap IN at identical quantities,
    // one line each side, nothing produced. Treating those as production would
    // report 6.5 tonnes of scrap as a shift's output.
    const inLines = entries(REAL, 'IN');
    const outLines = entries(REAL, 'OUT');

    const isReclassification =
        inLines.length === 1 &&
        outLines.length === 1 &&
        itemOf(inLines[0]) === 'Pet Scrap' &&
        /Chips|Polyster/i.test(itemOf(outLines[0]));

    assert.equal(isReclassification, false);
    // A real production journal produces bottles alongside its scrap.
    assert.ok(inLines.map(itemOf).some((n) => /Pet Bottle/i.test(n)));
});

test('our voucher puts every item on the same side the real one put it', () => {
    const ours = rebuiltFromReal();

    assert.deepEqual(entries(ours, 'IN').map(itemOf), entries(REAL, 'IN').map(itemOf));
    assert.deepEqual(entries(ours, 'OUT').map(itemOf), entries(REAL, 'OUT').map(itemOf));
});

test('our voucher uses the same direction convention, line for line', () => {
    const ours = rebuiltFromReal();

    for (const block of entries(ours, 'IN')) {
        assert.equal(deemedOf(block), 'Yes');
    }
    for (const block of entries(ours, 'OUT')) {
        assert.equal(deemedOf(block), 'No');
    }
});

test("our batch allocation names the same batch Tally's own does", () => {
    const ours = rebuiltFromReal();

    assert.match(REAL, /<BATCHNAME>Primary Batch<\/BATCHNAME>/);
    assert.match(ours, /<BATCHNAME>Primary Batch<\/BATCHNAME>/);
});

test('we emit no RATE and no AMOUNT — Tally derives value from item costs', () => {
    const ours = rebuiltFromReal();

    // Deliberate: the ERP never asserts a purchase rate into a voucher
    // (FC-06), and Tally costs the line itself. The real export HAS these
    // elements; that we omit them is a choice, pinned here so it stays one.
    assert.doesNotMatch(ours, /<RATE>/);
    assert.doesNotMatch(ours, /<AMOUNT>/);
});
