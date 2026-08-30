import { escapeXml, envelope, requireAllowedCompanyFor, toTallyDate } from './xmlHelpers';

/** One posting line of the voucher: a ledger, its role and its amount. */
interface LedgerLine {
    ledger: string;
    amount: string;
}

/** Matches TallySyncService::enqueueSalesInvoice()'s payload shape exactly. */
export interface SalesInvoicePayload {
    voucher_type: 'Sales';
    voucher_date: string;
    voucher_number: string;
    /** The customer's own PO string, verbatim. Optional — 5 of 54 real vouchers have none. */
    reference: string | null;

    /** The customer's TALLY ledger name — never the ERP's own label for them. */
    party_ledger: string;
    party_gstin: string | null;
    /** Goods + tax + rounding, as a POSITIVE number. Emitted negated: the party is debited. */
    party_amount: string;

    company_gstin: string;
    company_state: string;
    /** The BUYER's state, which is also the place of supply. Never the company's. */
    buyer_state: string;
    place_of_supply: string;

    supply_type: 'inter_state' | 'intra_state';
    taxable_value: string;
    /** IGST alone when interstate; CGST then SGST when local. Never both sets. */
    tax_ledgers: LedgerLine[];
    /** Omitted entirely — null, never a zero line — when the total lands whole. */
    round_off: LedgerLine | null;

    godown: string;
    narration: string | null;
    /** The one Tally company this voucher may post to. Checked byte-for-byte. */
    allowed_company: string;
    lines: {
        item: string;
        quantity: string;
        uom: string | null;
        rate: string;
        amount: string;
        sales_ledger: string;
        godown: string;
    }[];
}

/**
 * DERIVED FROM THE STRUCTURE OF 55 REAL SALES VOUCHER EXPORTS — NOT YET POSTED TO A REAL TALLY (flag off; owner gate Q70 and the GST master data)
 *
 * BUILT AGAINST THE FACTORY'S OWN VOUCHERS, not against a guess.
 *
 * The previous version of this file said of itself "BEST-EFFORT TEMPLATE — NOT
 * YET VALIDATED AGAINST A REAL TALLY INSTANCE", and it was right to. Measured
 * against the 55 real Sales vouchers the factory exported from its own Tally
 * (read 30-Aug-2026), it was wrong in eight ways: it emitted no CGST/SGST/IGST,
 * no 'Rounding Off', no godown, no per-line accounting allocation and no party
 * GSTIN; it nested the inventory entries INSIDE a ledger entry; it used
 * ALLLEDGERENTRIES.LIST, a tag that appears ZERO times in the real export; and
 * it debited the party the PRE-TAX total. A voucher built from it carried no
 * tax and a party balance wrong by the tax.
 *
 * THE INVARIANT THIS SHAPE SERVES — true of all 54 live vouchers in that export
 * with no exceptions:
 *
 *     sum(line amounts) + tax + rounding + party = 0        (party negative)
 *
 * THE STRUCTURE, as the real vouchers have it:
 *   VOUCHER
 *     ├── ALLINVENTORYENTRIES.LIST   (one per line, AT VOUCHER LEVEL)
 *     │     ├── BATCHALLOCATIONS.LIST      (godown + batch)
 *     │     └── ACCOUNTINGALLOCATIONS.LIST (the sales ledger — this is the copy
 *     │                                     that participates in the balance)
 *     └── LEDGERENTRIES.LIST         (party, then tax, then rounding last)
 *
 * TWO RULES THAT LOOK WRONG AND ARE NOT:
 *
 * 1. ISDEEMEDPOSITIVE IS DERIVED FROM THE LEDGER'S ROLE, NEVER FROM THE SIGN OF
 *    THE AMOUNT. It is 'Yes' for the party and 'No' for everything else. The
 *    tempting biconditional (Yes ⟺ amount < 0) holds for the party, tax and
 *    sales lines but BREAKS on 'Rounding Off', which is 'No' in all 48 real
 *    vouchers that carry it even though 20 of those amounts are negative.
 *
 * 2. NEGATIVE MEANS DEBIT. The party is debited and carries a negative amount;
 *    sales, tax and rounding are credited and carry positive ones. Inverting
 *    either produces a voucher that still balances and books the mirror image
 *    of the transaction.
 *
 * WHAT IS DELIBERATELY NOT EMITTED:
 *   - REMOTEID / VCHKEY / GUID / ALTERID / MASTERID — Tally's own sync identity.
 *     Every real voucher carries them because the file is an export; emitting one
 *     with ACTION="Create" risks ALTERING an existing voucher instead of creating
 *     a new one.
 *   - IRN / IRNQRCODE / IRNACKNO / e-way-bill numbers — these come back FROM the
 *     government portal after filing. Fabricating them claims a registration the
 *     document does not have.
 *   - The ~150 constant Yes/No export flags. Which of them Tally REQUIRES on
 *     import is unverified; they are omitted rather than cargo-culted.
 *
 * STILL UNVERIFIED AGAINST A LIVE TALLY (no instance was available): whether
 * Tally's import accepts UTF-8 (the exports are UTF-16LE), and whether the
 * RATE/ACTUALQTY unit suffixes below are required or merely echoed on export.
 */
export function buildSalesInvoiceXml(payload: SalesInvoicePayload, companyName: string): string {
    // The 28-Aug rehearsal posted a voucher into an obsolete Tally company
    // because nothing checked the destination. It is checked here, for the same
    // reason it is checked on Receipt Notes and Purchase Orders — and it matters
    // more on this voucher, because the factory's own export was taken from a
    // company literally named "... Testing".
    requireAllowedCompanyFor('Sales invoice', payload.allowed_company, companyName);

    const inventory = payload.lines
        .map((line) => {
            // "980.0000 Nos." — the quantity carries its unit. Real vouchers also
            // carry a dual-unit form ("912.0000 Nos. =  16.286 Kgs.") where the
            // STOCK ITEM MASTER defines an alternate unit; that is a property of
            // the master, not of the voucher, so it is left to Tally rather than
            // synthesised here from a nominal weight.
            const qty = line.uom ? `${line.quantity} ${line.uom}.` : line.quantity;
            const rate = line.uom ? `${line.rate}/${line.uom}.` : line.rate;

            return `              <ALLINVENTORYENTRIES.LIST>
                <STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>
                <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
                <RATE>${escapeXml(rate)}</RATE>
                <AMOUNT>${escapeXml(line.amount)}</AMOUNT>
                <ACTUALQTY>${escapeXml(qty)}</ACTUALQTY>
                <BILLEDQTY>${escapeXml(qty)}</BILLEDQTY>
                <BATCHALLOCATIONS.LIST>
                  <GODOWNNAME>${escapeXml(line.godown)}</GODOWNNAME>
                  <BATCHNAME>Primary Batch</BATCHNAME>
                  <AMOUNT>${escapeXml(line.amount)}</AMOUNT>
                  <ACTUALQTY>${escapeXml(qty)}</ACTUALQTY>
                  <BILLEDQTY>${escapeXml(qty)}</BILLEDQTY>
                </BATCHALLOCATIONS.LIST>
                <ACCOUNTINGALLOCATIONS.LIST>
                  <LEDGERNAME>${escapeXml(line.sales_ledger)}</LEDGERNAME>
                  <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
                  <AMOUNT>${escapeXml(line.amount)}</AMOUNT>
                </ACCOUNTINGALLOCATIONS.LIST>
              </ALLINVENTORYENTRIES.LIST>`;
        })
        .join('\n');

    // The party first, then tax, then rounding last — the order the real
    // vouchers use. ISDEEMEDPOSITIVE is the ROLE, not the sign.
    const ledgerEntry = (ledger: string, amount: string, isParty: boolean): string =>
        `              <LEDGERENTRIES.LIST>
                <LEDGERNAME>${escapeXml(ledger)}</LEDGERNAME>
                <ISDEEMEDPOSITIVE>${isParty ? 'Yes' : 'No'}</ISDEEMEDPOSITIVE>
                <AMOUNT>${escapeXml(amount)}</AMOUNT>
              </LEDGERENTRIES.LIST>`;

    const ledgers = [
        ledgerEntry(payload.party_ledger, `-${payload.party_amount}`, true),
        ...payload.tax_ledgers.map((tax) => ledgerEntry(tax.ledger, tax.amount, false)),
        ...(payload.round_off ? [ledgerEntry(payload.round_off.ledger, payload.round_off.amount, false)] : []),
    ].join('\n');

    const optional = (tag: string, value: string | null): string =>
        value ? `\n            <${tag}>${escapeXml(value)}</${tag}>` : '';

    const message = `          <VOUCHER VCHTYPE="Sales" ACTION="Create" OBJVIEW="Invoice Voucher View">
            <DATE>${toTallyDate(payload.voucher_date)}</DATE>
            <EFFECTIVEDATE>${toTallyDate(payload.voucher_date)}</EFFECTIVEDATE>
            <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>
            <VOUCHERNUMBER>${escapeXml(payload.voucher_number)}</VOUCHERNUMBER>${optional('REFERENCE', payload.reference)}
            <PARTYLEDGERNAME>${escapeXml(payload.party_ledger)}</PARTYLEDGERNAME>
            <PARTYNAME>${escapeXml(payload.party_ledger)}</PARTYNAME>
            <BASICBUYERNAME>${escapeXml(payload.party_ledger)}</BASICBUYERNAME>
            <BASICBASEPARTYNAME>${escapeXml(payload.party_ledger)}</BASICBASEPARTYNAME>${optional('PARTYGSTIN', payload.party_gstin)}
            <CMPGSTIN>${escapeXml(payload.company_gstin)}</CMPGSTIN>
            <CMPGSTSTATE>${escapeXml(payload.company_state)}</CMPGSTSTATE>
            <STATENAME>${escapeXml(payload.buyer_state)}</STATENAME>
            <PLACEOFSUPPLY>${escapeXml(payload.place_of_supply)}</PLACEOFSUPPLY>
            <ISINVOICE>Yes</ISINVOICE>
            <VCHENTRYMODE>Item Invoice</VCHENTRYMODE>
            <PERSISTEDVIEW>Invoice Voucher View</PERSISTEDVIEW>
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
${inventory}
${ledgers}
          </VOUCHER>`;

    return envelope(companyName, message);
}
