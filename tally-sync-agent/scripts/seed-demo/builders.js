const { escapeXml, toTallyDate } = require('./xml');

function ledgerXml({ name, parent, openingBalance }) {
    return `      <LEDGER NAME="${escapeXml(name)}" ACTION="Create">
        <NAME>${escapeXml(name)}</NAME>
        <PARENT>${escapeXml(parent)}</PARENT>
        <ISBILLWISEON>Yes</ISBILLWISEON>
        <AFFECTSSTOCK>No</AFFECTSSTOCK>
        ${openingBalance ? `<OPENINGBALANCE>${escapeXml(openingBalance)}</OPENINGBALANCE>` : ''}
      </LEDGER>`;
}

function stockGroupXml({ name, parent }) {
    // Tally's reserved root ("Primary") is stored internally with a
    // control-char language marker (`\x04 Primary`) — sending the plain
    // string "Primary" as PARENT fails with "Stock Group 'Primary' does not
    // exist!". Omitting PARENT entirely defaults a new group to the root,
    // which is what we want for top-level groups anyway.
    const parentTag = parent && parent !== 'Primary' ? `\n        <PARENT>${escapeXml(parent)}</PARENT>` : '';
    return `      <STOCKGROUP NAME="${escapeXml(name)}" ACTION="Create">
        <NAME>${escapeXml(name)}</NAME>${parentTag}
      </STOCKGROUP>`;
}

function simpleUnitXml({ symbol, formalName }) {
    return `      <UNIT NAME="${escapeXml(symbol)}" ACTION="Create">
        <NAME>${escapeXml(symbol)}</NAME>
        <ISSIMPLEUNIT>Yes</ISSIMPLEUNIT>
        <ISUSERDEFINED>Yes</ISUSERDEFINED>
        <SYMBOL>${escapeXml(symbol)}</SYMBOL>
        <FORMALNAME>${escapeXml(formalName)}</FORMALNAME>
        <DECIMALPLACES>2</DECIMALPLACES>
      </UNIT>`;
}

function compoundUnitXml({ symbol, formalName, baseUnit, conversion }) {
    return `      <UNIT NAME="${escapeXml(symbol)}" ACTION="Create">
        <NAME>${escapeXml(symbol)}</NAME>
        <ISSIMPLEUNIT>No</ISSIMPLEUNIT>
        <ISUSERDEFINED>Yes</ISUSERDEFINED>
        <SYMBOL>${escapeXml(symbol)}</SYMBOL>
        <FORMALNAME>${escapeXml(formalName)}</FORMALNAME>
        <BASEUNITS>${escapeXml(baseUnit)}</BASEUNITS>
        <ADDITIONALUNITS>${escapeXml(symbol)}</ADDITIONALUNITS>
        <CONVERSION>${escapeXml(conversion)}</CONVERSION>
      </UNIT>`;
}

function godownXml({ name }) {
    return `      <GODOWN NAME="${escapeXml(name)}" ACTION="Create">
        <NAME>${escapeXml(name)}</NAME>
        <PARENT>Main Location</PARENT>
      </GODOWN>`;
}

function costCategoryXml({ name }) {
    return `      <COSTCATEGORY NAME="${escapeXml(name)}" ACTION="Create">
        <NAME>${escapeXml(name)}</NAME>
        <ALLOCATEREVENUE>Yes</ALLOCATEREVENUE>
        <ALLOCATENONREVENUE>Yes</ALLOCATENONREVENUE>
      </COSTCATEGORY>`;
}

function costCentreXml({ name, category }) {
    return `      <COSTCENTRE NAME="${escapeXml(name)}" ACTION="Create">
        <NAME>${escapeXml(name)}</NAME>
        <CATEGORY>${escapeXml(category)}</CATEGORY>
        <PARENT>Primary</PARENT>
      </COSTCENTRE>`;
}

function voucherTypeXml({ name, parent }) {
    return `      <VOUCHERTYPE NAME="${escapeXml(name)}" ACTION="Create">
        <NAME>${escapeXml(name)}</NAME>
        <PARENT>${escapeXml(parent)}</PARENT>
        <NUMBERINGMETHOD>Automatic</NUMBERINGMETHOD>
      </VOUCHERTYPE>`;
}

/**
 * BOM shape is the one part of this seed script that could not be validated
 * against a real Tally export before running — Tally's stock-item BOM XML
 * has historically varied by version (see the caveat already flagged in
 * ../../README.md for voucher builders; same logic applies here). This is
 * the commonly-documented shape; if Tally rejects it, the fix is to
 * hand-build one item's BOM in the GUI, export it, and match this to that
 * export exactly.
 */
function stockItemXml({ name, parent, baseUnit, altUnit, gstHsn, gstRate, components }) {
    // Confirmed against a live object export: the real field is
    // ADDITIONALUNITS (not ALTERNATEUNITS), and CONVERSION is a plain
    // multiplier — "how many BASEUNITS equal 1 of this unit" — not the
    // "1000=9.5" string this originally guessed.
    const altUnitTags = altUnit
        ? `
        <ADDITIONALUNITS>${escapeXml(altUnit.symbol)}</ADDITIONALUNITS>
        <CONVERSION>${escapeXml(altUnit.conversion)}</CONVERSION>`
        : '';

    // Confirmed against a live object export: GSTAPPLICABLE is a real,
    // working field. HSNCODE and a nested GSTDETAILS.LIST are NOT — Tally
    // silently drops both (no error, field just absent on re-export). This
    // version of Tally likely expects HSN/rate via a separate "GST
    // Classification" master, or manual entry per item's GST Details
    // screen — left as a known gap rather than guessed further.
    const gstTags = gstHsn ? `\n        <GSTAPPLICABLE>Yes</GSTAPPLICABLE>` : '';

    // BOM (Set Components) is an even deeper unknown: the guessed
    // COMPONENTLIST.LIST/BOMNAME shape was also silently dropped. Skipped
    // here entirely rather than ship a tag block that looks functional but
    // isn't — see ../../README.md's existing caveat about validating Tally
    // XML shapes against a real instance before trusting them. `components`
    // is intentionally unused until that shape is confirmed.
    void components;

    return `      <STOCKITEM NAME="${escapeXml(name)}" ACTION="Create">
        <NAME>${escapeXml(name)}</NAME>
        <PARENT>${escapeXml(parent)}</PARENT>
        <BASEUNITS>${escapeXml(baseUnit)}</BASEUNITS>${altUnitTags}${gstTags}
      </STOCKITEM>`;
}

/**
 * Plain ledger voucher (Journal by default, or Credit Note/Receipt when
 * voucher.vchType is set — both are pure-ledger-safe voucher types too).
 * Follows the same debit/credit sign convention as the shipped agent's
 * ../../src/tally/voucherBuilders/journalEntry.ts: debit lines get
 * ISDEEMEDPOSITIVE=Yes and a negative AMOUNT, credit lines get No/positive.
 */
function journalVoucherXml(voucher) {
    const vchType = voucher.vchType || 'Journal';
    const voucherTypeName = voucher.voucherTypeName || vchType;

    const ledgerEntries = voucher.lines
        .map((line) => {
            const isDebit = Number(line.debit) > 0;
            const amount = isDebit ? line.debit : line.credit;
            const billTag = line.billRef
                ? `
              <BILLALLOCATIONS.LIST>
                <NAME>${escapeXml(line.billRef)}</NAME>
                <BILLTYPE>New Ref</BILLTYPE>
                <AMOUNT>${isDebit ? '-' : ''}${escapeXml(amount)}</AMOUNT>
              </BILLALLOCATIONS.LIST>`
                : '';
            return `            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>${escapeXml(line.ledger)}</LEDGERNAME>
              <ISDEEMEDPOSITIVE>${isDebit ? 'Yes' : 'No'}</ISDEEMEDPOSITIVE>
              <AMOUNT>${isDebit ? '-' : ''}${escapeXml(amount)}</AMOUNT>${billTag}
            </ALLLEDGERENTRIES.LIST>`;
        })
        .join('\n');

    return `      <VOUCHER VCHTYPE="${escapeXml(vchType)}" ACTION="Create">
        <DATE>${toTallyDate(voucher.date)}</DATE>
        <VOUCHERTYPENAME>${escapeXml(voucherTypeName)}</VOUCHERTYPENAME>
        <VOUCHERNUMBER>${escapeXml(voucher.number)}</VOUCHERNUMBER>
        <NARRATION>${escapeXml(voucher.narration ?? '')}</NARRATION>
${ledgerEntries}
      </VOUCHER>`;
}

module.exports = {
    ledgerXml,
    stockGroupXml,
    journalVoucherXml,
    simpleUnitXml,
    compoundUnitXml,
    godownXml,
    costCategoryXml,
    costCentreXml,
    voucherTypeXml,
    stockItemXml,
};
