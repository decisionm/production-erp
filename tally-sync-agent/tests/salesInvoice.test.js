const test = require('node:test');
const assert = require('node:assert');
const { buildSalesInvoiceXml } = require('../dist/tally/voucherBuilders/salesInvoice.js');

const COMPANY = 'SWAASHPET POLYMERS PVT LTD';

/**
 * Executable contract for the Sales voucher builder, written against the 55
 * REAL Sales vouchers the factory exported from its own Tally (read
 * 30-Aug-2026, ~/Downloads/sales_voucher.xml — 54 live plus one cancelled).
 *
 * WHAT THIS GUARDS. The previous builder declared itself unvalidated and was:
 * it emitted no CGST/SGST/IGST, no 'Rounding Off', no godown and no per-line
 * accounting allocation; it nested inventory inside a ledger entry; it used
 * ALLLEDGERENTRIES.LIST, which appears ZERO times in the real export; and it
 * debited the party the PRE-TAX total. Every test below is one of those defects
 * turned into a lock.
 *
 * THE CENTRAL ONE is `the voucher balances exactly` — the property all 54 live
 * real vouchers satisfy and the old builder broke by the whole tax.
 */

/** An interstate sale: 980 x 7.20 = 7056.00 + 18% IGST 1270.08 = 8326.08 -> 8326, rounding -0.08. */
function payload(overrides = {}) {
    return {
        voucher_type: 'Sales',
        voucher_date: '2026-08-01',
        voucher_number: 'INV-1',
        reference: 'P.O.NO:FRD/2627/POS/PMP/00002 Dt:23.07.2026',
        party_ledger: 'Sangam Pharma Packers',
        party_gstin: '33ABVFS0946B1Z5',
        party_amount: '8326',
        company_gstin: '34AAWCS7109K1ZQ',
        company_state: 'Puducherry',
        buyer_state: 'Tamil Nadu',
        place_of_supply: 'Tamil Nadu',
        supply_type: 'inter_state',
        taxable_value: '7056.0000',
        tax_ledgers: [{ ledger: 'IGST', amount: '1270.0800' }],
        round_off: { ledger: 'Rounding Off', amount: '-0.0800' },
        godown: COMPANY,
        narration: null,
        allowed_company: COMPANY,
        lines: [
            {
                item: 'B.500 Ml Round Pet Bottle Amber - 36gms',
                quantity: '980.0000',
                uom: 'Nos',
                rate: '7.2000',
                amount: '7056.0000',
                sales_ledger: 'Interstate Sales Taxable',
                godown: COMPANY,
            },
        ],
        ...overrides,
    };
}

function amountsOf(xml, blockTag) {
    const blocks = xml.match(new RegExp(`<${blockTag}>[\\s\\S]*?</${blockTag}>`, 'g')) || [];
    return blocks.map((b) => {
        const m = b.match(/<AMOUNT>(-?[\d.]+)<\/AMOUNT>/);
        return m ? parseFloat(m[1]) : 0;
    });
}

// ---- THE INVARIANT -------------------------------------------------------

test('the voucher balances exactly — sum(lines) + tax + rounding + party = 0', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    // Only the ACCOUNTINGALLOCATIONS copy of a line amount participates in the
    // balance; the ALLINVENTORYENTRIES and BATCHALLOCATIONS copies are the same
    // figure repeated, exactly as the real vouchers repeat it.
    const lineCredits = amountsOf(xml, 'ACCOUNTINGALLOCATIONS.LIST').reduce((a, b) => a + b, 0);
    const ledgers = amountsOf(xml, 'LEDGERENTRIES.LIST').reduce((a, b) => a + b, 0);

    assert.ok(
        Math.abs(lineCredits + ledgers) < 0.005,
        `voucher must sum to zero, got ${lineCredits + ledgers}`,
    );
});

test('the party is DEBITED the tax-inclusive total — the exact bug the old builder had', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    assert.match(xml, /<LEDGERNAME>Sangam Pharma Packers<\/LEDGERNAME>\s*<ISDEEMEDPOSITIVE>Yes<\/ISDEEMEDPOSITIVE>\s*<AMOUNT>-8326<\/AMOUNT>/);
    // 7056.00 was the pre-tax figure the old builder used. It must not be the party's.
    assert.doesNotMatch(xml, /<LEDGERNAME>Sangam Pharma Packers<\/LEDGERNAME>\s*<ISDEEMEDPOSITIVE>Yes<\/ISDEEMEDPOSITIVE>\s*<AMOUNT>-7056/);
});

// ---- STRUCTURE -----------------------------------------------------------

test('the ledger tag is LEDGERENTRIES.LIST — ALLLEDGERENTRIES.LIST appears nowhere', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    assert.match(xml, /<LEDGERENTRIES\.LIST>/);
    assert.doesNotMatch(xml, /<ALLLEDGERENTRIES\.LIST>/);
});

test('inventory entries sit at VOUCHER level, not nested inside a ledger entry', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    const inventoryAt = xml.indexOf('<ALLINVENTORYENTRIES.LIST>');
    const firstLedgerAt = xml.indexOf('<LEDGERENTRIES.LIST>');
    assert.ok(inventoryAt > 0 && firstLedgerAt > inventoryAt, 'inventory precedes the ledger entries at voucher level');

    // The old shape: an inventory list opened while a ledger entry was still open.
    const ledgerBlock = xml.slice(firstLedgerAt);
    assert.doesNotMatch(ledgerBlock, /<ALLINVENTORYENTRIES\.LIST>/);
});

test('every line carries a godown, a batch and its own sales-ledger allocation', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    assert.match(xml, new RegExp(`<GODOWNNAME>${COMPANY}</GODOWNNAME>`));
    assert.match(xml, /<BATCHNAME>Primary Batch<\/BATCHNAME>/);
    assert.match(xml, /<ACCOUNTINGALLOCATIONS\.LIST>\s*<LEDGERNAME>Interstate Sales Taxable<\/LEDGERNAME>/);
});

// ---- TAX -----------------------------------------------------------------

test('an interstate sale emits IGST alone — never IGST and CGST together', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    assert.match(xml, /<LEDGERNAME>IGST<\/LEDGERNAME>/);
    assert.doesNotMatch(xml, /<LEDGERNAME>CGST<\/LEDGERNAME>/);
    assert.doesNotMatch(xml, /<LEDGERNAME>SGST<\/LEDGERNAME>/);
});

test('a local sale emits CGST and SGST and no IGST', () => {
    const xml = buildSalesInvoiceXml(payload({
        supply_type: 'intra_state',
        buyer_state: 'Puducherry',
        place_of_supply: 'Puducherry',
        tax_ledgers: [{ ledger: 'CGST', amount: '635.0400' }, { ledger: 'SGST', amount: '635.0400' }],
        round_off: { ledger: 'Rounding Off', amount: '-0.0800' },
        lines: [{ ...payload().lines[0], sales_ledger: 'Local Sales Taxable' }],
    }), COMPANY);

    assert.match(xml, /<LEDGERNAME>CGST<\/LEDGERNAME>/);
    assert.match(xml, /<LEDGERNAME>SGST<\/LEDGERNAME>/);
    assert.doesNotMatch(xml, /<LEDGERNAME>IGST<\/LEDGERNAME>/);
    assert.match(xml, /<LEDGERNAME>Local Sales Taxable<\/LEDGERNAME>/);
});

test("ISDEEMEDPOSITIVE follows the ledger's ROLE, not the sign — Rounding Off stays 'No' while negative", () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    assert.match(
        xml,
        /<LEDGERNAME>Rounding Off<\/LEDGERNAME>\s*<ISDEEMEDPOSITIVE>No<\/ISDEEMEDPOSITIVE>\s*<AMOUNT>-0\.0800<\/AMOUNT>/,
        "a negative Rounding Off is still ISDEEMEDPOSITIVE=No — 20 of the 48 real rounding lines are negative",
    );
});

test('a whole-rupee total emits no Rounding Off line at all', () => {
    const xml = buildSalesInvoiceXml(payload({ round_off: null }), COMPANY);

    assert.doesNotMatch(xml, /Rounding Off/);
});

// ---- GST IDENTITY --------------------------------------------------------

test('the buyer state is STATENAME and PLACEOFSUPPLY; the company state is CMPGSTSTATE', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    assert.match(xml, /<STATENAME>Tamil Nadu<\/STATENAME>/);
    assert.match(xml, /<PLACEOFSUPPLY>Tamil Nadu<\/PLACEOFSUPPLY>/);
    assert.match(xml, /<CMPGSTSTATE>Puducherry<\/CMPGSTSTATE>/);
    assert.match(xml, /<PARTYGSTIN>33ABVFS0946B1Z5<\/PARTYGSTIN>/);
    assert.match(xml, /<CMPGSTIN>34AAWCS7109K1ZQ<\/CMPGSTIN>/);
});

test('an unregistered party simply omits PARTYGSTIN rather than emitting a blank one', () => {
    const xml = buildSalesInvoiceXml(payload({ party_gstin: null }), COMPANY);

    assert.doesNotMatch(xml, /<PARTYGSTIN>/);
    assert.match(xml, /<STATENAME>Tamil Nadu<\/STATENAME>/, 'the state still classifies the supply');
});

// ---- WHAT MUST NEVER BE EMITTED -----------------------------------------

test("Tally's own identity and the portal's e-invoice fields are never fabricated", () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    for (const forbidden of ['REMOTEID', 'VCHKEY', '<GUID>', 'ALTERID', 'MASTERID', '<IRN>', 'IRNQRCODE', 'IRNACKNO']) {
        assert.ok(!xml.includes(forbidden), `${forbidden} must never be emitted — it is Tally's or the IRP's, not ours`);
    }
});

test('the voucher is always ACTION="Create" — never Cancel', () => {
    const xml = buildSalesInvoiceXml(payload(), COMPANY);

    assert.match(xml, /ACTION="Create"/);
    assert.doesNotMatch(xml, /ACTION="Cancel"/);
});

// ---- THE COMPANY GATE ----------------------------------------------------

test('a payload with no allowed_company refuses to build', () => {
    const legacy = payload();
    delete legacy.allowed_company;
    assert.throws(() => buildSalesInvoiceXml(legacy, COMPANY), /no allowed_company/);
});

test('a mismatched allowed_company refuses — the export itself came from a "... Testing" company', () => {
    assert.throws(
        () => buildSalesInvoiceXml(payload({ allowed_company: `${COMPANY} Testing` }), COMPANY),
        /does not match/,
    );
});
