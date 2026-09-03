/**
 * Executable contract for the receivables readers — what clients owe, and what
 * is still to ship them.
 *
 * Plain JavaScript against dist/ for the reason the other agent tests give:
 * tsconfig pins rootDir to src, so testing the COMPILED output needs no second
 * config and tests the artifact that ships. The parsers are pure functions over
 * a string; nothing here contacts Tally.
 *
 * EVERY VALUE BELOW IS SYNTHETIC (FC-06): invented parties, invented bill
 * references, round numbers, dates in 2026. What a named client owes the
 * factory is Owner/Accounts data and does not belong in a repository.
 *
 * WHAT THIS GUARDS, above all, is the #66 lesson: the readers must find their
 * nodes ANYWHERE in the document, because a saved Tally export is IMPORTDATA
 * and a live gateway export is EXPORTDATA, and a parser that matched the saved
 * shape read every evidence file perfectly and returned ZERO against the real
 * Tally. Both wrappers are asserted below, on the same payload.
 */

const test = require('node:test');
const assert = require('node:assert');

const {
    parseBillsReceivable,
    parsePendingSalesOrders,
    parseAmount,
    parseTallyDate,
    parseCreditPeriodDays,
    dueDateOf,
    buildBillsCollectionXml,
    buildReportXml,
    describeDocument,
} = require('../dist/tally/receivables.js');

/**
 * A TDL Collection over Bills, as Tally answers one: the party is the bill's
 * PARENT, and the due date is not stated — it is BillDate + BillCreditPeriod,
 * which is what Tally's own "Due on" column shows.
 */
function billsCollectionDoc() {
    return `<ENVELOPE><BODY><EXPORTDATA><REQUESTDATA><COLLECTION>
        <BILLS NAME="INV-101">
            <NAME>INV-101</NAME>
            <PARENT>Northwind Traders</PARENT>
            <BILLDATE>20260801</BILLDATE>
            <BILLCREDITPERIOD>30 Days</BILLCREDITPERIOD>
            <CLOSINGBALANCE>-10000.00</CLOSINGBALANCE>
        </BILLS>
        <BILLS NAME="INV-102">
            <NAME>INV-102</NAME>
            <PARENT>Southgate Polymers</PARENT>
            <BILLDATE>20260810</BILLDATE>
            <CLOSINGBALANCE>-2500.00</CLOSINGBALANCE>
        </BILLS>
    </COLLECTION></REQUESTDATA></EXPORTDATA></BODY></ENVELOPE>`;
}

/** The same two bills, wrapped the way a SAVED export and a LIVE export differ. */
function billsDoc(wrapper) {
    return `<ENVELOPE><BODY><${wrapper}><REQUESTDATA>
        <BILLFIXED>
            <LEDGERNAME>Northwind Traders</LEDGERNAME>
            <BILLREF>INV-001</BILLREF>
            <BILLDATE>20260801</BILLDATE>
            <BILLDUEDATE>20260901</BILLDUEDATE>
            <BILLCL>-10000.00</BILLCL>
            <BILLOP>-10000.00</BILLOP>
        </BILLFIXED>
        <BILLFIXED>
            <LEDGERNAME>Southgate Polymers</LEDGERNAME>
            <BILLREF>INV-002</BILLREF>
            <BILLDATE>20260810</BILLDATE>
            <BILLCL>2500.00</BILLCL>
        </BILLFIXED>
    </REQUESTDATA></${wrapper}></BODY></ENVELOPE>`;
}

test('bills are found in a SAVED export and a LIVE export alike', () => {
    // THE #66 REGRESSION. A reader that followed a path would pass one of
    // these and silently return nothing for the other.
    for (const wrapper of ['IMPORTDATA', 'EXPORTDATA']) {
        const bills = parseBillsReceivable(billsDoc(wrapper));

        assert.strictEqual(bills.length, 2, `wrapper ${wrapper} should yield both bills`);
        assert.strictEqual(bills[0].party_ledger_name, 'Northwind Traders');
        assert.strictEqual(bills[0].bill_reference, 'INV-001');
        assert.strictEqual(bills[0].bill_date, '2026-08-01');
        assert.strictEqual(bills[0].due_date, '2026-09-01');
        assert.strictEqual(bills[0].closing_amount, 10000);
    }
});

test('Tally debit and credit signs are normalised to the page contract', () => {
    const bills = parseBillsReceivable(billsDoc('EXPORTDATA'));

    // Tally states receivable debit balances as negative. The page contract is
    // the opposite: positive means the client owes us; negative is a credit.
    assert.strictEqual(bills[0].closing_amount, 10000);
    assert.strictEqual(bills[1].closing_amount, -2500);
});

test('the factory party-summary shape becomes honest balance-only rows', () => {
    const xml = `<ENVELOPE>
        <DSPACCNAME><DSPDISPNAME>Northwind Traders</DSPDISPNAME></DSPACCNAME>
        <DSPACCINFO>
            <DSPCLDRAMT><DSPCLDRAMTA>-10000.000</DSPCLDRAMTA></DSPCLDRAMT>
            <DSPCLCRAMT><DSPCLCRAMTA>1500.000</DSPCLCRAMTA></DSPCLCRAMT>
        </DSPACCINFO>
        <DSPACCNAME><DSPDISPNAME>Southgate Polymers</DSPDISPNAME></DSPACCNAME>
        <DSPACCINFO>
            <DSPCLDRAMT><DSPCLDRAMTA></DSPCLDRAMTA></DSPCLDRAMT>
            <DSPCLCRAMT><DSPCLCRAMTA>2500.000</DSPCLCRAMTA></DSPCLCRAMT>
        </DSPACCINFO>
    </ENVELOPE>`;

    const bills = parseBillsReceivable(xml);

    assert.strictEqual(bills.length, 2);
    assert.strictEqual(bills[0].party_ledger_name, 'Northwind Traders');
    assert.strictEqual(bills[0].closing_amount, 8500);
    assert.strictEqual(bills[0].bill_reference, null);
    assert.strictEqual(bills[0].due_date, null);
    assert.strictEqual(bills[1].closing_amount, -2500);
});

test('an unpaired party-summary response is refused rather than misjoined', () => {
    const xml = `<ENVELOPE>
        <DSPACCNAME><DSPDISPNAME>Northwind Traders</DSPDISPNAME></DSPACCNAME>
        <DSPACCNAME><DSPDISPNAME>Southgate Polymers</DSPDISPNAME></DSPACCNAME>
        <DSPACCINFO><DSPCLDRAMT><DSPCLDRAMTA>-1000</DSPCLDRAMTA></DSPCLDRAMT></DSPACCINFO>
    </ENVELOPE>`;

    assert.deepStrictEqual(parseBillsReceivable(xml), []);
});

test('a bill with no due date is read, with a null due date', () => {
    const bills = parseBillsReceivable(billsDoc('EXPORTDATA'));

    // Tally permits a party with no credit terms. The bill is kept; the due
    // date is absent rather than assumed to be the bill date.
    assert.strictEqual(bills[1].due_date, null);
    assert.strictEqual(bills[1].opening_amount, null);
});

test('a bill with no party or no closing amount is dropped, not stored half-formed', () => {
    const xml = `<ENVELOPE><BODY><EXPORTDATA>
        <BILLFIXED><BILLREF>NO-PARTY</BILLREF><BILLCL>500</BILLCL></BILLFIXED>
        <BILLFIXED><LEDGERNAME>No Amount Ltd</LEDGERNAME><BILLREF>X</BILLREF></BILLFIXED>
    </EXPORTDATA></BODY></ENVELOPE>`;

    assert.deepStrictEqual(parseBillsReceivable(xml), []);
});

test('an absent amount is null, never zero', () => {
    // A 0 outstanding is a SETTLED bill. Reporting one the factory never
    // stated would take a real debt off somebody's collection list.
    assert.strictEqual(parseAmount(''), null);
    assert.strictEqual(parseAmount(undefined), null);
    assert.strictEqual(parseAmount('not a number'), null);
    assert.strictEqual(parseAmount('1,25,000.50'), 125000.5);
    assert.strictEqual(parseAmount('-2500'), -2500);
});

test('a date is read only when it really is one', () => {
    assert.strictEqual(parseTallyDate('20260901'), '2026-09-01');
    assert.strictEqual(parseTallyDate('2026-09-01'), '2026-09-01');
    assert.strictEqual(parseTallyDate('1-Sep-2026'), null);
    assert.strictEqual(parseTallyDate(''), null);
});

const ordersXml = `<ENVELOPE><BODY><EXPORTDATA><REQUESTDATA>
    <VOUCHER VCHTYPE="Sales Order">
        <PARTYLEDGERNAME>Northwind Traders</PARTYLEDGERNAME>
        <BASICPURCHASEORDERNO>PO-4471</BASICPURCHASEORDERNO>
        <DATE>20260901</DATE>
        <ALLINVENTORYENTRIES.LIST>
            <STOCKITEMNAME>ITEM_A</STOCKITEMNAME>
            <BALANCEQTY> 40.000 Kgs.</BALANCEQTY>
            <AMOUNT>26960.00</AMOUNT>
        </ALLINVENTORYENTRIES.LIST>
        <ALLINVENTORYENTRIES.LIST>
            <STOCKITEMNAME>ITEM_SHIPPED</STOCKITEMNAME>
            <BALANCEQTY> 0.000 Kgs.</BALANCEQTY>
            <AMOUNT>0.00</AMOUNT>
        </ALLINVENTORYENTRIES.LIST>
    </VOUCHER>
    <VOUCHER VCHTYPE="Sales Order">
        <PARTYLEDGERNAME>Cancelled Ltd</PARTYLEDGERNAME>
        <ISCANCELLED>Yes</ISCANCELLED>
        <ALLINVENTORYENTRIES.LIST><STOCKITEMNAME>ITEM_B</STOCKITEMNAME><BALANCEQTY> 5.000 Kgs.</BALANCEQTY></ALLINVENTORYENTRIES.LIST>
    </VOUCHER>
</REQUESTDATA></EXPORTDATA></BODY></ENVELOPE>`;

test('a fully-shipped order line is not pending', () => {
    const orders = parsePendingSalesOrders(ordersXml);

    // Reading ACTUALQTY instead of BALANCEQTY would report a shipped line as
    // entirely outstanding.
    assert.strictEqual(orders.length, 1);
    assert.strictEqual(orders[0].stock_item_name, 'ITEM_A');
    assert.strictEqual(orders[0].pending_quantity, 40);
    assert.strictEqual(orders[0].quantity_unit, 'Kgs.');
    assert.strictEqual(orders[0].pending_amount, 26960);
});

test("the client's own PO number is what identifies a pending order", () => {
    const orders = parsePendingSalesOrders(ordersXml);

    assert.strictEqual(orders[0].order_reference, 'PO-4471');
    assert.strictEqual(orders[0].order_date, '2026-09-01');
});

test('a cancelled order is not something the factory still owes anybody', () => {
    const orders = parsePendingSalesOrders(ordersXml);

    assert.ok(orders.every((o) => o.party_ledger_name !== 'Cancelled Ltd'));
});

test('an order with no inventory line is still reported, with null quantities', () => {
    const xml = `<ENVELOPE><BODY><EXPORTDATA>
        <VOUCHER VCHTYPE="Sales Order">
            <PARTYLEDGERNAME>Headers Only Ltd</PARTYLEDGERNAME>
            <BASICPURCHASEORDERNO>PO-9</BASICPURCHASEORDERNO>
        </VOUCHER>
    </EXPORTDATA></BODY></ENVELOPE>`;

    const orders = parsePendingSalesOrders(xml);

    // A real pending order. Dropping it would understate what is owed to
    // ship; inventing a quantity would be worse.
    assert.strictEqual(orders.length, 1);
    assert.strictEqual(orders[0].pending_quantity, null);
    assert.strictEqual(orders[0].pending_amount, null);
});

test('a zero read can be described by node name, without leaking a value', () => {
    // This is what separates "the factory is owed nothing" from "this parser
    // did not understand the answer" (#64) on the first real pull.
    const described = describeDocument(billsDoc('EXPORTDATA'));

    assert.strictEqual(described.nodes.BILLFIXED, 2);
    assert.ok(described.bytes > 0);
    // Names and counts only — no amount, no party.
    assert.ok(!JSON.stringify(described).includes('Northwind'));
    assert.ok(!JSON.stringify(described).includes('10000'));
});

// ---------------------------------------------------------------------------
// 0.4.7 — bill-wise, with the dates. The party-summary answer 0.4.6 accepted
// cannot be aged: no bill date and no due date means every rupee lands in the
// page's "no due date" bucket and the ageing columns are empty for good.
// ---------------------------------------------------------------------------

test('a Bills COLLECTION is read bill-wise — the party is the bill PARENT', () => {
    const bills = parseBillsReceivable(billsCollectionDoc());

    assert.strictEqual(bills.length, 2);
    assert.strictEqual(bills[0].party_ledger_name, 'Northwind Traders');
    assert.strictEqual(bills[0].bill_reference, 'INV-101');
    assert.strictEqual(bills[0].bill_date, '2026-08-01');
    // Tally states a receivable debit as negative; the page contract is
    // positive-means-owed. The sign crosses once, at this boundary.
    assert.strictEqual(bills[0].closing_amount, 10000);
});

test('the due date is BillDate + BillCreditPeriod — what Tally\'s own "Due on" shows', () => {
    const bills = parseBillsReceivable(billsCollectionDoc());

    // 01-Aug + 30 days. Without this the ageing spine has nothing to age by.
    assert.strictEqual(bills[0].due_date, '2026-08-31');
});

test('a bill whose credit period Tally did not state has NO due date', () => {
    const bills = parseBillsReceivable(billsCollectionDoc());

    // Tally permits a party with no credit terms. Defaulting to the bill date
    // would report it overdue the day it was raised; defaulting to a house
    // term would assert a term the factory never set. Both are inventions.
    assert.strictEqual(bills[1].due_date, null);
});

test('a stated due date beats a credit period', () => {
    const xml = `<ENVELOPE><BODY><EXPORTDATA><BILLS>
        <PARENT>Northwind Traders</PARENT>
        <BILLDATE>20260801</BILLDATE>
        <BILLDUEDATE>20260915</BILLDUEDATE>
        <BILLCREDITPERIOD>30 Days</BILLCREDITPERIOD>
        <CLOSINGBALANCE>-1000.00</CLOSINGBALANCE>
    </BILLS></EXPORTDATA></BODY></ENVELOPE>`;

    // Tally's own answer outranks arithmetic on Tally's inputs.
    assert.strictEqual(parseBillsReceivable(xml)[0].due_date, '2026-09-15');
});

test('a credit period Tally wrote as a DATE is the due date itself', () => {
    assert.strictEqual(dueDateOf({ BILLCREDITPERIOD: '20261001' }, '2026-08-01'), '2026-10-01');
});

test('a credit period in a shape nobody has measured yields no due date', () => {
    // Not "0 days", not the bill date — null. An unrecognised shape is the
    // parser saying it did not understand, and the page has a bucket for that.
    assert.strictEqual(parseCreditPeriodDays('Net 30'), null);
    assert.strictEqual(parseCreditPeriodDays(''), null);
    assert.strictEqual(dueDateOf({ BILLCREDITPERIOD: 'Net 30' }, '2026-08-01'), null);

    // And a period with no bill date to add it to cannot become a date.
    assert.strictEqual(dueDateOf({ BILLCREDITPERIOD: '30 Days' }, null), null);
});

test('"30 Days", "45 day" and "60 DAYS" are all read as days', () => {
    assert.strictEqual(parseCreditPeriodDays('30 Days'), 30);
    assert.strictEqual(parseCreditPeriodDays('45 day'), 45);
    assert.strictEqual(parseCreditPeriodDays('60 DAYS'), 60);
    assert.strictEqual(parseCreditPeriodDays('15'), null);
});

test('the bills are asked for over the whole book, not a single day', () => {
    // THE WINDOW BUG. Both reads asked with SVFROMDATE = SVTODATE = the as-at
    // day, which can only ever describe bills raised that morning — while
    // every bill still open today was raised before today.
    for (const xml of [
        buildBillsCollectionXml('SWAASHPET POLYMERS PVT LTD Testing', '2026-09-03'),
        buildReportXml('SWAASHPET POLYMERS PVT LTD Testing', 'Bills Receivable', '2026-09-03'),
    ]) {
        assert.ok(xml.includes('<SVTODATE>20260903</SVTODATE>'), 'the as-at date is the TO date');
        assert.ok(!xml.includes('<SVFROMDATE>20260903</SVFROMDATE>'), 'a one-day window reports almost nothing');
    }
});

test('the bill-wise request asks for a Bills collection carrying the dates', () => {
    const xml = buildBillsCollectionXml('SWAASHPET POLYMERS PVT LTD Testing', '2026-09-03');

    // The shape #67 measured this Tally answers, not a report request.
    assert.ok(xml.includes('<TYPE>Collection</TYPE>'));
    assert.ok(xml.includes('<TYPE>Bills</TYPE>'));

    // Without Parent there is no client to attach the debt to; without
    // BillDate and BillCreditPeriod there is nothing to age it by.
    for (const method of ['Name', 'Parent', 'BillDate', 'BillCreditPeriod', 'ClosingBalance']) {
        assert.ok(xml.includes(method), `the collection must fetch ${method}`);
    }

    // The company is escaped into the request, not concatenated raw.
    assert.ok(xml.includes('<SVCURRENTCOMPANY>SWAASHPET POLYMERS PVT LTD Testing</SVCURRENTCOMPANY>'));
});

test('the party-summary fallback still answers, and still refuses to misjoin', () => {
    // 0.4.6's measured shape is NOT dropped: a Tally that answers the
    // collection with nothing must still yield the balance-only position.
    const summary = `<ENVELOPE><BODY><EXPORTDATA>
        <DSPACCNAME><DSPDISPNAME>Northwind Traders</DSPDISPNAME></DSPACCNAME>
        <DSPACCINFO><DSPCLDRAMT><DSPCLDRAMTA>-10000.00</DSPCLDRAMTA></DSPCLDRAMT></DSPACCINFO>
    </EXPORTDATA></BODY></ENVELOPE>`;

    const bills = parseBillsReceivable(summary);

    assert.strictEqual(bills.length, 1);
    assert.strictEqual(bills[0].closing_amount, 10000);
    // Balance-only: it says so by carrying no bill detail, rather than
    // inventing a reference or a date.
    assert.strictEqual(bills[0].bill_reference, null);
    assert.strictEqual(bills[0].due_date, null);
});
