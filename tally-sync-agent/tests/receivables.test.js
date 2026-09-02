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
    describeDocument,
} = require('../dist/tally/receivables.js');

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
