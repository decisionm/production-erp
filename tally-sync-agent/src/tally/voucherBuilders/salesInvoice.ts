import { escapeXml, envelope, toTallyDate } from './xmlHelpers';

/** Matches TallySyncService::enqueueSalesInvoice()'s payload shape exactly. */
export interface SalesInvoicePayload {
    voucher_type: 'Sales';
    voucher_date: string;
    voucher_number: string;
    party_ledger: string;
    party_gstin: string | null;
    // The client's Sales ledger from Settings → Ledger Mappings (null if unmapped;
    // falls back to "Sales Account"). Config, not hardcoded.
    sales_ledger: string | null;
    narration: string | null;
    lines: { item: string; quantity: string; rate: string; amount: string }[];
    total_amount: string;
}

/**
 * BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE.
 *
 * Per docs/archive/TALLY-SYNC-MASTER-PLAN.md §3: the reliable way to get this right is
 * to create one real Sales voucher by hand in Tally, export it as XML
 * (Gateway of Tally → Display/Export → the voucher → Export), and match
 * this structure to that export exactly — Tally's accepted tag set and
 * required fields have historically varied a little by version. Treat this
 * as the starting skeleton, not a finished implementation.
 *
 * Known simplification: assumes a single "Sales Account" ledger for all
 * line items (no per-item ledger mapping) and doesn't yet emit GST tax
 * ledger entries (CGST/SGST/IGST) — those need real ledger names from the
 * target Tally company's chart of accounts before this can post a
 * GST-correct voucher.
 */
export function buildSalesInvoiceXml(payload: SalesInvoicePayload, companyName: string): string {
    const inventoryEntries = payload.lines
        .map(
            (line) => `              <ALLINVENTORYENTRIES.LIST>
                <STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>
                <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
                <RATE>${escapeXml(line.rate)}</RATE>
                <AMOUNT>${escapeXml(line.amount)}</AMOUNT>
                <ACTUALQTY>${escapeXml(line.quantity)}</ACTUALQTY>
                <BILLEDQTY>${escapeXml(line.quantity)}</BILLEDQTY>
              </ALLINVENTORYENTRIES.LIST>`,
        )
        .join('\n');

    const message = `          <VOUCHER VCHTYPE="Sales" ACTION="Create">
            <DATE>${toTallyDate(payload.voucher_date)}</DATE>
            <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>
            <VOUCHERNUMBER>${escapeXml(payload.voucher_number)}</VOUCHERNUMBER>
            <PARTYLEDGERNAME>${escapeXml(payload.party_ledger)}</PARTYLEDGERNAME>
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>${escapeXml(payload.party_ledger)}</LEDGERNAME>
              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>
              <AMOUNT>-${escapeXml(payload.total_amount)}</AMOUNT>
            </ALLLEDGERENTRIES.LIST>
            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>${escapeXml(payload.sales_ledger ?? 'Sales Account')}</LEDGERNAME>
              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
              <AMOUNT>${escapeXml(payload.total_amount)}</AMOUNT>
${inventoryEntries}
            </ALLLEDGERENTRIES.LIST>
          </VOUCHER>`;

    return envelope(companyName, message);
}
