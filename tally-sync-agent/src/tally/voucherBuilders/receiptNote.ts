import { envelope, escapeXml, requireAllowedCompanyFor, toTallyDate } from './xmlHelpers';

/** Matches TallySyncService::enqueueGoodsReceiptNote()'s payload shape. */
export interface ReceiptNotePayload {
    voucher_type: 'Receipt Note';
    voucher_date: string;
    voucher_number: string;
    party_ledger: string | null;
    party_gstin: string | null;
    /**
     * The one Tally company this voucher may post to
     * (tally-sync.receipt_notes_allowed_company on the cloud, stamped at
     * enqueue). Checked byte-for-byte against this agent's configured
     * company before anything is built — the 28-Aug rehearsal posted a
     * Receipt Note into an obsolete company because nothing checked.
     * Payloads staged before the field existed carry no key at all, which
     * fails the same check: an unknown destination is refused, not assumed.
     */
    allowed_company?: string | null;
    godown: string | null;
    narration: string | null;
    lines: { item: string; quantity: string; rate: string; amount: string }[];
    total_amount: string;
}

/**
 * BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE.
 * A Receipt Note is a pure INVENTORY voucher (goods received into stock; the
 * accounting Purchase bill happens separately), so it carries the supplier as
 * the party, the items, and the godown — no accounting ledger allocation.
 * Reverse-engineer against a real export before trusting it (master plan §3):
 * ISDEEMEDPOSITIVE / batch / godown tag names vary by Tally version.
 */
export function buildReceiptNoteXml(payload: ReceiptNotePayload, companyName: string): string {
    requireAllowedCompanyFor('Receipt Note', payload.allowed_company, companyName);

    const inventory = payload.lines
        .map((line) => inventoryEntry(line, payload.godown, /* stockIn */ true))
        .join('\n');

    const party = payload.party_ledger
        ? `\n            <PARTYLEDGERNAME>${escapeXml(payload.party_ledger)}</PARTYLEDGERNAME>`
        : '';

    const message = `          <VOUCHER VCHTYPE="Receipt Note" ACTION="Create">
            <DATE>${toTallyDate(payload.voucher_date)}</DATE>
            <VOUCHERTYPENAME>Receipt Note</VOUCHERTYPENAME>
            <VOUCHERNUMBER>${escapeXml(payload.voucher_number)}</VOUCHERNUMBER>${party}
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
${inventory}
          </VOUCHER>`;

    return envelope(companyName, message);
}

/** One inventory line; the godown (if any) goes in BATCHALLOCATIONS. */
export function inventoryEntry(
    line: { item: string; quantity: string; rate?: string; amount?: string },
    godown: string | null,
    stockIn: boolean,
): string {
    const rate = line.rate ?? '0';
    const amount = line.amount ?? '0';
    const batch = godown
        ? `
                <BATCHALLOCATIONS.LIST>
                  <GODOWNNAME>${escapeXml(godown)}</GODOWNNAME>
                  <BATCHNAME>Primary Batch</BATCHNAME>
                  <AMOUNT>${escapeXml(amount)}</AMOUNT>
                  <ACTUALQTY>${escapeXml(line.quantity)}</ACTUALQTY>
                  <BILLEDQTY>${escapeXml(line.quantity)}</BILLEDQTY>
                </BATCHALLOCATIONS.LIST>`
        : '';

    return `              <ALLINVENTORYENTRIES.LIST>
                <STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>
                <ISDEEMEDPOSITIVE>${stockIn ? 'Yes' : 'No'}</ISDEEMEDPOSITIVE>
                <RATE>${escapeXml(rate)}</RATE>
                <AMOUNT>${escapeXml(amount)}</AMOUNT>
                <ACTUALQTY>${escapeXml(line.quantity)}</ACTUALQTY>
                <BILLEDQTY>${escapeXml(line.quantity)}</BILLEDQTY>${batch}
              </ALLINVENTORYENTRIES.LIST>`;
}
