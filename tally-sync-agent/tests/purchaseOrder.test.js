/**
 * Executable contract for the Purchase Order voucher builder (0.3.9, Phase 6).
 *
 * Plain JavaScript against dist/ for the reasons stockJournal.test.js gives:
 * tsconfig pins rootDir to src, so testing the COMPILED output needs no
 * second config and tests the artifact that ships. The builder tree is
 * runtime-dependency-free — requiring dist here pulls in no electron, no axios.
 *
 * WHAT THIS GUARDS — the agent half of the DEC-20260812-002 proof: "the PO is
 * an ORDER voucher; it must not touch accounts or stock in Tally; stated in
 * the code and proved by a test". Tally treats it as an order BECAUSE OF THE
 * VOUCHER TYPE, so the type is what is pinned: VCHTYPE="Purchase Order",
 * VOUCHERTYPENAME Purchase Order, ISINVOICE No, PERSISTEDVIEW / OBJVIEW
 * "Invoice Voucher View" — while the ledger blocks (party, purchase ledger)
 * are PRESENT, exactly as every real export carries them. The reasoning is
 * asserted to be in the builder's own docblock, not only here.
 *
 * Every value below is SYNTHETIC on purpose (FC-06): "Vendor Alpha",
 * "ITEM_A", rate 1.0000, a made-up ledger and godown, dates in 2026. No
 * real rate, vendor, GSTIN, Tally item name or voucher number appears here
 * or in tests/fixtures/purchase-order.golden.xml. The STRUCTURE (tag tree,
 * order, signs, cardinalities) is what was measured on the factory's real
 * exports (Q38 — read locally, never committed); the values are not.
 *
 * Nothing here contacts Tally. These are pure string builders — and the
 * cloud stages no Purchase Order at all while tally-sync.purchase_orders_enabled
 * is off (owner gate Q35), so no entry of this type reaches an agent today.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const {
    buildPurchaseOrderXml,
    orderDueJd,
    orderDueP,
    negate,
    sumDecimals,
    quantity,
} = require('../dist/tally/voucherBuilders/purchaseOrder.js');
const { buildVoucherXml } = require('../dist/tally/voucherBuilders/index.js');

const COMPANY = 'Company Alpha';

/** A payload shaped like TallySyncService::enqueuePurchaseOrder()'s. */
function payload(overrides = {}) {
    return {
        voucher_type: 'Purchase Order',
        voucher_date: '2026-08-20',
        voucher_number: 'PO-7',
        voucher_number_source: 'erp',
        party_ledger: 'Vendor Alpha',
        party_gstin: '00AAAAA0000A0Z0',
        purchase_ledger: 'Purchase Ledger Alpha',
        godown: 'Godown Alpha',
        reference: 'PO-7',
        narration: 'Synthetic golden — Phase 6',
        lines: [
            {
                item: 'ITEM_A',
                quantity: '100.0000',
                rate: '1.0000',
                amount: '100.0000',
                schedules: [
                    { due_date: '2026-09-01', quantity: '60.0000', amount: '60.0000' },
                    { due_date: '2026-09-15', quantity: '40.0000', amount: '40.0000' },
                ],
            },
            { item: 'ITEM_B', quantity: '25.0000', rate: '2.0000', amount: '50.0000', schedules: [] },
        ],
        total_amount: '150.0000',
        ...overrides,
    };
}

/** A one-line, one-schedule order. */
function oneLine(overrides = {}) {
    return payload({
        lines: [
            {
                item: 'ITEM_A',
                quantity: '10.0000',
                rate: '1.0000',
                amount: '10.0000',
                schedules: [{ due_date: '2026-09-01', quantity: '10.0000', amount: '10.0000' }],
            },
        ],
        total_amount: '10.0000',
        ...overrides,
    });
}

/** Every <TAG.LIST> … </TAG.LIST> block of one kind, whole, in document order. */
function blocks(xml, tag) {
    const re = new RegExp(`<${tag.replace('.', '\\.')}>[\\s\\S]*?</${tag.replace('.', '\\.')}>`, 'g');
    return xml.match(re) ?? [];
}

/** The tag names between the VOUCHER open tag and its close, in order, repeats collapsed. */
function topLevelTags(xml) {
    const inner = xml.slice(xml.indexOf('<VOUCHER '), xml.indexOf('</VOUCHER>'));
    const names = [];
    let depth = 0;
    for (const m of inner.matchAll(/<(\/?)([A-Z.]+)[^>]*?(\/?)>/g)) {
        const [, closing, name, selfClosing] = m;
        if (name === 'VOUCHER') continue;
        if (closing) {
            depth -= 1;
            continue;
        }
        if (depth === 0 && (names.length === 0 || names[names.length - 1] !== name)) names.push(name);
        if (!selfClosing) depth += 1;
    }
    return names;
}

/** The direct child tag names of one block, in order, repeats collapsed. */
function childTags(block) {
    const inner = block.slice(block.indexOf('>') + 1, block.lastIndexOf('</'));
    const names = [];
    let depth = 0;
    for (const m of inner.matchAll(/<(\/?)([A-Z.]+)[^>]*?>/g)) {
        const [, closing, name] = m;
        if (closing) {
            depth -= 1;
            continue;
        }
        if (depth === 0 && (names.length === 0 || names[names.length - 1] !== name)) names.push(name);
        depth += 1;
    }
    return names;
}

/* ── The DEC-20260812-002 proof, agent half ─────────────────────────────── */

test('it is an ORDER voucher by TYPE: VCHTYPE / VOUCHERTYPENAME Purchase Order, ISINVOICE No, Invoice Voucher View', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);

    assert.match(xml, /<VOUCHER VCHTYPE="Purchase Order" ACTION="Create" OBJVIEW="Invoice Voucher View">/);
    assert.match(xml, /<VOUCHERTYPENAME>Purchase Order<\/VOUCHERTYPENAME>/);
    assert.match(xml, /<ISINVOICE>No<\/ISINVOICE>/);
    assert.match(xml, /<PERSISTEDVIEW>Invoice Voucher View<\/PERSISTEDVIEW>/);
    assert.match(xml, /<ISCANCELLED>No<\/ISCANCELLED>/);
    assert.match(xml, /<USETRACKINGNUMBER>No<\/USETRACKINGNUMBER>/);
    // Order-ness is the type — none of these appear on any real order, and
    // none may appear here: they would claim a different voucher.
    assert.doesNotMatch(xml, /<ISORDER>|<ORDERTYPE>|<DESTINATIONGODOWNNAME>/);
    // ... and it is never any of the vouchers that DO move stock or books.
    assert.doesNotMatch(xml, /VCHTYPE="(Purchase|Receipt Note|Stock Journal|Journal)"/);
});

test('the ledger blocks are PRESENT, as on every real export — the non-effect is the type, not an omission', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);

    // The vendor at voucher level ...
    const ledgers = blocks(xml, 'LEDGERENTRIES.LIST');
    assert.equal(ledgers.length, 1, 'exactly one voucher-level ledger line: the party (no tax, no rounding — Q35(e))');
    assert.match(ledgers[0], /<LEDGERNAME>Vendor Alpha<\/LEDGERNAME>/);
    assert.match(ledgers[0], /<ISPARTYLEDGER>Yes<\/ISPARTYLEDGER>/);
    // ... and the purchase ledger on EVERY line.
    const allocations = blocks(xml, 'ACCOUNTINGALLOCATIONS.LIST');
    assert.equal(allocations.length, 2);
    for (const allocation of allocations) {
        assert.match(allocation, /<LEDGERNAME>Purchase Ledger Alpha<\/LEDGERNAME>/);
    }
});

test('the builder states the type-not-tags reasoning, the staged status and the owner gate in its own docblock', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', 'src', 'tally', 'voucherBuilders', 'purchaseOrder.ts'), 'utf8');

    // The line the cloud's EntryPresenter quotes verbatim (PURCHASE_ORDER_STAGED_LINE).
    assert.match(
        source,
        /DERIVED FROM THE STRUCTURE OF 107 REAL PURCHASE ORDER EXPORTS — NOT YET POSTED TO A REAL TALLY \(flag off; owner gate Q35\)/,
    );
    assert.match(source, /DEC-20260812-002/);
    assert.match(source, /BECAUSE OF THE VOUCHER TYPE/);
    assert.match(source, /not\s+\*?\s*because any ledger block is left out/);
    assert.match(source, /Q38/, 'the raw exports are named as evidence outside the repo');
    assert.match(source, /Q40/, 'the unit-suffix omission cites its owner question');
    assert.match(source, /Q35\(c\)/, 'the voucher-number convention cites its owner question');
    assert.match(source, /Q35\(e\)/, 'the missing tax line cites its owner question');
});

/* ── Dispatch ────────────────────────────────────────────────────────────── */

test('the dispatcher routes tally_voucher_type "Purchase Order" to this builder', () => {
    const xml = buildVoucherXml({ id: 41, tally_voucher_type: 'Purchase Order', payload: payload() }, COMPANY);

    assert.match(xml, /<VOUCHERTYPENAME>Purchase Order<\/VOUCHERTYPENAME>/);
    assert.doesNotMatch(xml, /No XML builder/);
});

/* ── Shape: tag order, measured on the exports ───────────────────────────── */

test('top-level tags come in the exports\' own order, inventory before the party ledger', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);

    assert.deepEqual(topLevelTags(xml), [
        'DATE',
        'PARTYGSTIN',
        'VOUCHERTYPENAME',
        'PARTYNAME',
        'PARTYLEDGERNAME',
        'VOUCHERNUMBER',
        'REFERENCE',
        'BASICBASEPARTYNAME',
        'NARRATION',
        'PERSISTEDVIEW',
        'EFFECTIVEDATE',
        'ISCANCELLED',
        'USETRACKINGNUMBER',
        'ISINVOICE',
        'ALLINVENTORYENTRIES.LIST',
        'LEDGERENTRIES.LIST',
    ]);
});

test('an inventory line carries item, sign, rate, amount, quantities, then its allocations, then the purchase ledger', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);
    const [lineA] = blocks(xml, 'ALLINVENTORYENTRIES.LIST');

    assert.deepEqual(childTags(lineA), [
        'STOCKITEMNAME',
        'ISDEEMEDPOSITIVE',
        'RATE',
        'AMOUNT',
        'ACTUALQTY',
        'BILLEDQTY',
        'BATCHALLOCATIONS.LIST',
        'ACCOUNTINGALLOCATIONS.LIST',
    ]);
    const [allocation] = blocks(lineA, 'BATCHALLOCATIONS.LIST');
    assert.deepEqual(childTags(allocation), ['GODOWNNAME', 'BATCHNAME', 'ORDERNO', 'AMOUNT', 'ACTUALQTY', 'BILLEDQTY', 'ORDERDUEDATE']);
    const [accounting] = blocks(lineA, 'ACCOUNTINGALLOCATIONS.LIST');
    assert.deepEqual(childTags(accounting), ['LEDGERNAME', 'ISDEEMEDPOSITIVE', 'AMOUNT']);
    const [party] = blocks(xml, 'LEDGERENTRIES.LIST');
    assert.deepEqual(childTags(party), ['LEDGERNAME', 'ISDEEMEDPOSITIVE', 'ISPARTYLEDGER', 'AMOUNT']);
});

test('the party is named three ways from the ONE ledger name, and the dates are yyyymmdd', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);

    assert.match(xml, /<PARTYNAME>Vendor Alpha<\/PARTYNAME>/);
    assert.match(xml, /<PARTYLEDGERNAME>Vendor Alpha<\/PARTYLEDGERNAME>/);
    assert.match(xml, /<BASICBASEPARTYNAME>Vendor Alpha<\/BASICBASEPARTYNAME>/);
    assert.match(xml, /<DATE>20260820<\/DATE>/);
    assert.match(xml, /<EFFECTIVEDATE>20260820<\/EFFECTIVEDATE>/);
    assert.match(xml, /<VOUCHERNUMBER>PO-7<\/VOUCHERNUMBER>/);
    assert.match(xml, /<REFERENCE>PO-7<\/REFERENCE>/);
    assert.match(xml, /<PARTYGSTIN>00AAAAA0000A0Z0<\/PARTYGSTIN>/);
    assert.match(xml, /<SVCURRENTCOMPANY>Company Alpha<\/SVCURRENTCOMPANY>/);
});

test('a missing GSTIN, reference or narration omits the tag / encodes empty — never the string "null"', () => {
    const xml = buildPurchaseOrderXml(payload({ party_gstin: null, reference: null, narration: null }), COMPANY);

    assert.doesNotMatch(xml, /<PARTYGSTIN>/);
    assert.doesNotMatch(xml, /<REFERENCE>/);
    assert.match(xml, /<NARRATION><\/NARRATION>/);
    assert.doesNotMatch(xml, /null/);
});

/* ── Signs: measured on the exports, emitted verbatim ────────────────────── */

test('signs: inventory Yes/negative, purchase allocation Yes/negative, party No/positive — and the voucher balances', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);

    for (const line of blocks(xml, 'ALLINVENTORYENTRIES.LIST')) {
        assert.match(line, /<ISDEEMEDPOSITIVE>Yes<\/ISDEEMEDPOSITIVE>/);
        // The line's own AMOUNT (the first one in the block) is negative.
        assert.match(line, /<AMOUNT>-\d/);
    }
    const [lineA, lineB] = blocks(xml, 'ALLINVENTORYENTRIES.LIST');
    assert.match(lineA, /<RATE>1\.0000<\/RATE>\s*<AMOUNT>-100\.0000<\/AMOUNT>/);
    assert.match(lineB, /<RATE>2\.0000<\/RATE>\s*<AMOUNT>-50\.0000<\/AMOUNT>/);

    for (const allocation of blocks(xml, 'BATCHALLOCATIONS.LIST')) {
        assert.match(allocation, /<AMOUNT>-\d/);
    }
    for (const accounting of blocks(xml, 'ACCOUNTINGALLOCATIONS.LIST')) {
        assert.match(accounting, /<ISDEEMEDPOSITIVE>Yes<\/ISDEEMEDPOSITIVE>/);
        assert.match(accounting, /<AMOUNT>-\d/);
    }
    assert.match(blocks(xml, 'ACCOUNTINGALLOCATIONS.LIST')[0], /<AMOUNT>-100\.0000<\/AMOUNT>/);
    assert.match(blocks(xml, 'ACCOUNTINGALLOCATIONS.LIST')[1], /<AMOUNT>-50\.0000<\/AMOUNT>/);

    const [party] = blocks(xml, 'LEDGERENTRIES.LIST');
    assert.match(party, /<ISDEEMEDPOSITIVE>No<\/ISDEEMEDPOSITIVE>/);
    assert.match(party, /<AMOUNT>150\.0000<\/AMOUNT>/, 'the party balances the goods exactly: +(100 + 50)');
});

test('the party amount is the exact decimal sum of the line amounts, never a float', () => {
    const xml = buildPurchaseOrderXml(
        payload({
            lines: [
                { item: 'ITEM_A', quantity: '1.0000', rate: '0.1000', amount: '0.1000', schedules: [] },
                { item: 'ITEM_B', quantity: '1.0000', rate: '0.2000', amount: '0.2000', schedules: [] },
            ],
            total_amount: '0.3000',
        }),
        COMPANY,
    );

    assert.match(blocks(xml, 'LEDGERENTRIES.LIST')[0], /<AMOUNT>0\.3000<\/AMOUNT>/);
    assert.equal(sumDecimals(['0.1000', '0.2000']), '0.3000');
    assert.equal(sumDecimals(['33.3333', '33.3333', '33.3334']), '100.0000');
    assert.equal(sumDecimals(['1', '2.5']), '3.5');
    assert.equal(sumDecimals(['-1.00', '0.25']), '-0.75');
    assert.equal(negate('100.0000'), '-100.0000');
    assert.equal(negate('0.0000'), '0.0000', 'a zero stays unsigned');
    assert.equal(negate('-5.00'), '-5.00', 'an already-negative figure is not double-negated');
});

test('a total_amount that disagrees with the lines is refused — an unbalanced voucher is never built', () => {
    assert.throws(
        () => buildPurchaseOrderXml(payload({ total_amount: '999.0000' }), COMPANY),
        /total_amount \(999\.0000\) does not equal the sum of its line amounts \(150\.0000\)/,
    );
    // Without a total the lines alone decide.
    assert.doesNotThrow(() => buildPurchaseOrderXml(payload({ total_amount: undefined }), COMPANY));
});

/* ── Cardinalities: lines and schedules ──────────────────────────────────── */

test('one line, one schedule → one inventory entry with one allocation carrying ORDERDUEDATE', () => {
    const xml = buildPurchaseOrderXml(oneLine(), COMPANY);

    assert.equal(blocks(xml, 'ALLINVENTORYENTRIES.LIST').length, 1);
    assert.equal(blocks(xml, 'BATCHALLOCATIONS.LIST').length, 1);
    assert.equal(blocks(xml, 'ACCOUNTINGALLOCATIONS.LIST').length, 1);
    assert.equal(blocks(xml, 'LEDGERENTRIES.LIST').length, 1);
    assert.match(xml, /<ORDERDUEDATE JD="46265" P="1-Sep-26">1-Sep-26<\/ORDERDUEDATE>/);
    assert.match(xml, /<ORDERNO>PO-7<\/ORDERNO>/, 'ORDERNO repeats the voucher number (221/232 real allocations)');
});

test('two lines → two inventory entries, each with its own purchase-ledger allocation, one party line', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);
    const lines = blocks(xml, 'ALLINVENTORYENTRIES.LIST');

    assert.equal(lines.length, 2);
    assert.match(lines[0], /<STOCKITEMNAME>ITEM_A<\/STOCKITEMNAME>/);
    assert.match(lines[1], /<STOCKITEMNAME>ITEM_B<\/STOCKITEMNAME>/);
    assert.equal(blocks(xml, 'ACCOUNTINGALLOCATIONS.LIST').length, 2);
    assert.equal(blocks(xml, 'LEDGERENTRIES.LIST').length, 1);
});

test('a line with two schedules → two BATCHALLOCATIONS with distinct ORDERDUEDATEs whose amounts sum to the line', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);
    const [lineA] = blocks(xml, 'ALLINVENTORYENTRIES.LIST');
    const allocations = blocks(lineA, 'BATCHALLOCATIONS.LIST');

    assert.equal(allocations.length, 2);
    assert.match(allocations[0], /<ACTUALQTY>60\.0000<\/ACTUALQTY>/);
    assert.match(allocations[0], /<AMOUNT>-60\.0000<\/AMOUNT>/);
    assert.match(allocations[0], /<ORDERDUEDATE JD="46265" P="1-Sep-26">1-Sep-26<\/ORDERDUEDATE>/);
    assert.match(allocations[1], /<ACTUALQTY>40\.0000<\/ACTUALQTY>/);
    assert.match(allocations[1], /<AMOUNT>-40\.0000<\/AMOUNT>/);
    assert.match(allocations[1], /<ORDERDUEDATE JD="46279" P="15-Sep-26">15-Sep-26<\/ORDERDUEDATE>/);
    // Every allocation names the godown and the order.
    for (const allocation of allocations) {
        assert.match(allocation, /<GODOWNNAME>Godown Alpha<\/GODOWNNAME>/);
        assert.match(allocation, /<BATCHNAME>Primary Batch<\/BATCHNAME>/);
        assert.match(allocation, /<ORDERNO>PO-7<\/ORDERNO>/);
    }
});

test('a line with NO schedule gets ONE allocation for the whole line and NO ORDERDUEDATE — a due date is never invented', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);
    const [, lineB] = blocks(xml, 'ALLINVENTORYENTRIES.LIST');
    const allocations = blocks(lineB, 'BATCHALLOCATIONS.LIST');

    assert.equal(allocations.length, 1);
    assert.match(allocations[0], /<AMOUNT>-50\.0000<\/AMOUNT>/);
    assert.match(allocations[0], /<ACTUALQTY>25\.0000<\/ACTUALQTY>/);
    assert.doesNotMatch(allocations[0], /ORDERDUEDATE/);
    assert.deepEqual(childTags(allocations[0]), ['GODOWNNAME', 'BATCHNAME', 'ORDERNO', 'AMOUNT', 'ACTUALQTY', 'BILLEDQTY']);
});

test('an UNDER-SCHEDULED line as the cloud stages it (schedules + an undated remainder) → N+1 allocations whose quantities and amounts sum to the line, the remainder without ORDERDUEDATE', () => {
    // TallySyncService::enqueuePurchaseOrder appends the remainder allocation
    // itself (due_date = the order's expected_date, or null): 100 @ 2 with
    // schedules [30, 50] → [30/60, 50/100, 20/40 undated].
    const xml = buildPurchaseOrderXml(payload({
        lines: [{
            item: 'ITEM_A',
            quantity: '100.0000',
            rate: '2.0000',
            amount: '200.0000',
            schedules: [
                { due_date: '2026-09-01', quantity: '30.0000', amount: '60.0000' },
                { due_date: '2026-09-15', quantity: '50.0000', amount: '100.0000' },
                { due_date: null, quantity: '20.0000', amount: '40.0000' },
            ],
        }],
        total_amount: '200.0000',
    }), COMPANY);
    const [line] = blocks(xml, 'ALLINVENTORYENTRIES.LIST');
    const allocations = blocks(line, 'BATCHALLOCATIONS.LIST');

    assert.equal(allocations.length, 3, 'one per schedule plus the remainder');
    assert.match(allocations[0], /<ORDERDUEDATE JD="46265" P="1-Sep-26">1-Sep-26<\/ORDERDUEDATE>/);
    assert.match(allocations[1], /<ORDERDUEDATE JD="46279" P="15-Sep-26">15-Sep-26<\/ORDERDUEDATE>/);
    assert.match(allocations[2], /<ACTUALQTY>20\.0000<\/ACTUALQTY>/);
    assert.match(allocations[2], /<AMOUNT>-40\.0000<\/AMOUNT>/);
    assert.doesNotMatch(allocations[2], /ORDERDUEDATE/, 'a null due date emits no ORDERDUEDATE — never a made-up date');
    assert.deepEqual(childTags(allocations[2]), ['GODOWNNAME', 'BATCHNAME', 'ORDERNO', 'AMOUNT', 'ACTUALQTY', 'BILLEDQTY']);

    const quantities = allocations.map((a) => a.match(/<ACTUALQTY>([^<]+)<\/ACTUALQTY>/)[1]);
    const amounts = allocations.map((a) => a.match(/<AMOUNT>([^<]+)<\/AMOUNT>/)[1]);
    assert.equal(sumDecimals(quantities), '100.0000', 'allocation quantities sum to the line');
    assert.equal(sumDecimals(amounts), '-200.0000', 'allocation amounts sum to the line');
});

test('a partial-schedule payload WITHOUT the remainder row (schedules promising less than the line) still emits N+1 allocations that sum to the line — the second lock on the same rule', () => {
    // A cloud that predates the remainder rule sent the schedules alone; the
    // builder tops the line up with an undated remainder (quantity = line − Σ,
    // amount = line amount − Σ) rather than posting allocations that do not
    // add up to the line: 100 @ 1 with [60] → [60/60, 40/40 undated].
    const xml = buildPurchaseOrderXml(payload({
        lines: [{
            item: 'ITEM_A',
            quantity: '100.0000',
            rate: '1.0000',
            amount: '100.0000',
            schedules: [{ due_date: '2026-09-01', quantity: '60.0000', amount: '60.0000' }],
        }],
        total_amount: '100.0000',
    }), COMPANY);
    const [line] = blocks(xml, 'ALLINVENTORYENTRIES.LIST');
    const allocations = blocks(line, 'BATCHALLOCATIONS.LIST');

    assert.equal(allocations.length, 2);
    assert.match(allocations[0], /<ACTUALQTY>60\.0000<\/ACTUALQTY>/);
    assert.match(allocations[0], /<AMOUNT>-60\.0000<\/AMOUNT>/);
    assert.match(allocations[0], /<ORDERDUEDATE JD="46265" P="1-Sep-26">1-Sep-26<\/ORDERDUEDATE>/);
    assert.match(allocations[1], /<ACTUALQTY>40\.0000<\/ACTUALQTY>/);
    assert.match(allocations[1], /<AMOUNT>-40\.0000<\/AMOUNT>/);
    assert.doesNotMatch(allocations[1], /ORDERDUEDATE/);
    // Nothing invented: the remainder names the same godown and order.
    assert.match(allocations[1], /<GODOWNNAME>Godown Alpha<\/GODOWNNAME>/);
    assert.match(allocations[1], /<ORDERNO>PO-7<\/ORDERNO>/);

    // Rounding lands on the remainder: 33.3333 @ 1.5 → 49.9999 on the
    // schedule; the remainder takes 150.0000 − 49.9999 = 100.0001.
    const rounded = buildPurchaseOrderXml(payload({
        lines: [{
            item: 'ITEM_A',
            quantity: '100.0000',
            rate: '1.5000',
            amount: '150.0000',
            schedules: [{ due_date: '2026-09-01', quantity: '33.3333', amount: '49.9999' }],
        }],
        total_amount: '150.0000',
    }), COMPANY);
    const [roundedLine] = blocks(rounded, 'ALLINVENTORYENTRIES.LIST');
    const roundedAllocations = blocks(roundedLine, 'BATCHALLOCATIONS.LIST');
    assert.equal(roundedAllocations.length, 2);
    assert.match(roundedAllocations[1], /<ACTUALQTY>66\.6667<\/ACTUALQTY>/);
    assert.match(roundedAllocations[1], /<AMOUNT>-100\.0001<\/AMOUNT>/);
});

test('an exactly covered line and an unscheduled line get no remainder allocation', () => {
    const xml = buildPurchaseOrderXml(payload(), COMPANY);
    const [lineA, lineB] = blocks(xml, 'ALLINVENTORYENTRIES.LIST');

    assert.equal(blocks(lineA, 'BATCHALLOCATIONS.LIST').length, 2, '60 + 40 covers 100 exactly — no third row');
    assert.equal(blocks(lineB, 'BATCHALLOCATIONS.LIST').length, 1, 'no schedule → the one whole-line allocation, unchanged');
});

/* ── ORDERDUEDATE: JD = excelSerial − 1, P = d-Mmm-yy ────────────────────── */

test('ORDERDUEDATE JD is the Excel serial minus one (days since 1899-12-31); P is d-Mmm-yy without padding', () => {
    // The epoch: JD counts days since 1899-12-31 (Excel serial − 1 for
    // every modern date; Excel's own 1900 leap-year quirk is not modelled
    // and cannot matter for an order due in this century).
    assert.equal(orderDueJd('1899-12-31'), 0);
    assert.equal(orderDueJd('1900-01-01'), 1);
    // A known anchor: 2026-01-01 is Excel serial 46023.
    assert.equal(orderDueJd('2026-01-01'), 46022);
    assert.equal(orderDueJd('2026-09-01'), 46265);
    assert.equal(orderDueJd('2026-09-15'), 46279);
    // Across the leap day.
    assert.equal(orderDueJd('2028-03-01') - orderDueJd('2028-02-28'), 2);

    assert.equal(orderDueP('2026-09-01'), '1-Sep-26');
    assert.equal(orderDueP('2026-09-15'), '15-Sep-26');
    assert.equal(orderDueP('2026-01-05'), '5-Jan-26');
    assert.equal(orderDueP('2030-12-25'), '25-Dec-30');
    assert.equal(orderDueP('2101-03-09'), '9-Mar-01', 'two-digit year, zero-padded');

    assert.throws(() => orderDueJd('next week'), /needs an ISO date/);
    assert.throws(() => orderDueP('2026-13-01'), /needs an ISO date/);
});

/* ── Unit suffix (Q40) ───────────────────────────────────────────────────── */

test('with no unit in the payload the quantities are bare decimals and RATE has no "/unit" (Q40)', () => {
    const xml = buildPurchaseOrderXml(oneLine(), COMPANY);

    assert.match(xml, /<RATE>1\.0000<\/RATE>/);
    assert.match(xml, /<ACTUALQTY>10\.0000<\/ACTUALQTY>/);
    assert.match(xml, /<BILLEDQTY>10\.0000<\/BILLEDQTY>/);
    assert.doesNotMatch(xml, /<ACTUALQTY> /, 'no leading-space form without a unit');
    assert.doesNotMatch(xml, /<RATE>[^<]*\//);
    assert.equal(quantity('10.0000', null), '10.0000');
});

test('when the payload names a unit the exports\' forms are used: " qty unit" and "rate/unit"', () => {
    const line = { ...oneLine().lines[0], unit: 'UNIT_A' };
    const xml = buildPurchaseOrderXml(oneLine({ lines: [line] }), COMPANY);

    assert.match(xml, /<RATE>1\.0000\/UNIT_A<\/RATE>/);
    assert.match(xml, /<ACTUALQTY> 10\.0000 UNIT_A<\/ACTUALQTY>/);
    assert.match(xml, /<BILLEDQTY> 10\.0000 UNIT_A<\/BILLEDQTY>/);
    // The allocation's quantities take the same form.
    assert.match(blocks(xml, 'BATCHALLOCATIONS.LIST')[0], /<ACTUALQTY> 10\.0000 UNIT_A<\/ACTUALQTY>/);
    assert.equal(quantity('10.0000', 'UNIT_A'), ' 10.0000 UNIT_A');
});

/* ── Refusals: names are never invented ──────────────────────────────────── */

test('a missing purchase ledger throws — there is no default "Purchase" ledger', () => {
    for (const purchase_ledger of [null, undefined, '', '   ']) {
        assert.throws(
            () => buildPurchaseOrderXml(payload({ purchase_ledger }), COMPANY),
            /no purchase_ledger .*Tally names are never invented/,
        );
    }
    // And the source carries no such default anywhere.
    const source = fs.readFileSync(path.join(__dirname, '..', 'src', 'tally', 'voucherBuilders', 'purchaseOrder.ts'), 'utf8');
    assert.doesNotMatch(source, /['"`]Purchase Accounts?['"`]|['"`]Purchase['"`]\s*[;,)]/);
});

test('a missing party ledger throws — the vendor is never guessed from anything else', () => {
    for (const party_ledger of [null, undefined, '', '  ']) {
        assert.throws(
            () => buildPurchaseOrderXml(payload({ party_ledger }), COMPANY),
            /no party_ledger .*Tally names are never invented/,
        );
    }
});

test('a missing godown throws — every real allocation sits under one, and none is defaulted', () => {
    assert.throws(() => buildPurchaseOrderXml(payload({ godown: null }), COMPANY), /no godown/);
});

test('an order with no lines throws', () => {
    assert.throws(() => buildPurchaseOrderXml(payload({ lines: [] }), COMPANY), /no lines/);
});

/* ── Escaping ────────────────────────────────────────────────────────────── */

test('& < > " in names are escaped, and non-ASCII becomes numeric character references', () => {
    const xml = buildPurchaseOrderXml(
        oneLine({
            party_ledger: 'Vendor "Alpha" & Sons <Pvt>',
            purchase_ledger: 'Purchase <Alpha> & Co',
            godown: 'Godown "A" & B',
            narration: 'quote " amp & lt < gt > — dash',
            lines: [{ ...oneLine().lines[0], item: 'ITEM <A> & "B"' }],
        }),
        COMPANY,
    );

    assert.match(xml, /<PARTYLEDGERNAME>Vendor &quot;Alpha&quot; &amp; Sons &lt;Pvt&gt;<\/PARTYLEDGERNAME>/);
    assert.match(xml, /<LEDGERNAME>Purchase &lt;Alpha&gt; &amp; Co<\/LEDGERNAME>/);
    assert.match(xml, /<GODOWNNAME>Godown &quot;A&quot; &amp; B<\/GODOWNNAME>/);
    assert.match(xml, /<STOCKITEMNAME>ITEM &lt;A&gt; &amp; &quot;B&quot;<\/STOCKITEMNAME>/);
    assert.match(xml, /<NARRATION>quote &quot; amp &amp; lt &lt; gt &gt; &#8212; dash<\/NARRATION>/);
    // Nothing raw survives: every ampersand starts an entity, no bare quote
    // in a text node.
    assert.doesNotMatch(xml, /&(?!(amp|lt|gt|quot|apos|#\d+);)/);
    assert.doesNotMatch(xml, />[^<]*"[^<]*<\//);
});

/* ── The synthetic golden ────────────────────────────────────────────────── */

test('the two-line, two-schedule order matches tests/fixtures/purchase-order.golden.xml byte for byte', () => {
    const golden = fs.readFileSync(path.join(__dirname, 'fixtures', 'purchase-order.golden.xml'), 'utf8');
    const xml = buildPurchaseOrderXml(payload(), COMPANY);

    assert.equal(`${xml}\n`, golden);
});

test('the golden carries only synthetic values (FC-06) — no real vendor, rate, GSTIN, item or voucher number', () => {
    const golden = fs.readFileSync(path.join(__dirname, 'fixtures', 'purchase-order.golden.xml'), 'utf8');

    // What IS there is the synthetic set, whole.
    for (const expected of ['Vendor Alpha', 'ITEM_A', 'ITEM_B', 'Purchase Ledger Alpha', 'Godown Alpha', 'Company Alpha', 'PO-7', '00AAAAA0000A0Z0']) {
        assert.ok(golden.includes(expected), `${expected} is in the golden`);
    }
    // Every figure is a fixture figure.
    const figures = [...golden.matchAll(/<(?:RATE|AMOUNT|ACTUALQTY|BILLEDQTY)>(-?[\d.]+)<\//g)].map((m) => m[1]);
    assert.ok(figures.length > 0);
    for (const figure of figures) {
        assert.ok(
            ['1.0000', '2.0000', '-100.0000', '-50.0000', '100.0000', '25.0000', '60.0000', '-60.0000', '40.0000', '-40.0000', '150.0000'].includes(figure),
            `${figure} is a synthetic fixture figure`,
        );
    }
});
