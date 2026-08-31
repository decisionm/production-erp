import { envelope, escapeXml, requireAllowedCompanyFor, toTallyDate } from './xmlHelpers';

/** Matches TallySyncService::enqueueDelivery()'s payload shape. */
export interface DeliveryNotePayload {
    voucher_type: 'Delivery Note';
    voucher_date: string;
    voucher_number: string;
    /** The customer's TALLY ledger name — never the ERP's own label for them. */
    party_ledger: string;
    party_gstin: string | null;
    godown: string;
    narration: string | null;
    /** The one Tally company this voucher may post to. Checked byte-for-byte. */
    allowed_company: string;
    lines: { item: string; quantity: string; uom: string | null }[];
}

/**
 * A VOUCHER TYPE THIS FACTORY HAS NEVER USED — AND THAT IS WHY IT CANNOT BE VALIDATED AGAINST AN EXPORT
 *
 * Every other builder here was checked against real vouchers the factory's own
 * Tally produced. This one cannot be: the July-2026 export contains ZERO
 * Delivery Notes, and none of the 177 real Sales vouchers references one. The
 * factory books Sales Order → Sales Invoice with no delivery-note stage.
 *
 * The ERP posts one anyway, by owner decision (DEC-20260831-007), which makes
 * this the INTRODUCTION of a practice rather than the mirroring of one. The
 * consequence to be honest about: the structure below is reasoned from the
 * Sales voucher's inventory block, not measured from a real Delivery Note, so
 * the FIRST LIVE POST is the check on it. Nothing here is a template dressed up
 * as evidence.
 *
 * A Delivery Note is a pure INVENTORY voucher: stock leaves, the party is the
 * customer, and there is NO pricing and no accounting allocation — the bill is
 * the Sales voucher's job. That much is a property of the voucher type rather
 * than of this factory, and it is why no ledger entry appears below.
 */
export function buildDeliveryNoteXml(payload: DeliveryNotePayload, companyName: string): string {
    // The 28-Aug rehearsal posted a voucher into an obsolete Tally company
    // because nothing checked the destination. Checked here for the same
    // reason it is checked on the Sales voucher.
    requireAllowedCompanyFor('Delivery note', payload.allowed_company, companyName);

    const inventory = payload.lines
        .map((line) => {
            const qty = line.uom ? `${line.quantity} ${line.uom}.` : line.quantity;

            return `              <ALLINVENTORYENTRIES.LIST>
                <STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>
                <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>
                <ACTUALQTY>${escapeXml(qty)}</ACTUALQTY>
                <BILLEDQTY>${escapeXml(qty)}</BILLEDQTY>
                <BATCHALLOCATIONS.LIST>
                  <GODOWNNAME>${escapeXml(payload.godown)}</GODOWNNAME>
                  <BATCHNAME>Primary Batch</BATCHNAME>
                  <ACTUALQTY>${escapeXml(qty)}</ACTUALQTY>
                  <BILLEDQTY>${escapeXml(qty)}</BILLEDQTY>
                </BATCHALLOCATIONS.LIST>
              </ALLINVENTORYENTRIES.LIST>`;
        })
        .join('\n');

    const optional = (tag: string, value: string | null): string =>
        value ? `\n            <${tag}>${escapeXml(value)}</${tag}>` : '';

    const message = `          <VOUCHER VCHTYPE="Delivery Note" ACTION="Create" OBJVIEW="Invoice Voucher View">
            <DATE>${toTallyDate(payload.voucher_date)}</DATE>
            <EFFECTIVEDATE>${toTallyDate(payload.voucher_date)}</EFFECTIVEDATE>
            <VOUCHERTYPENAME>Delivery Note</VOUCHERTYPENAME>
            <VOUCHERNUMBER>${escapeXml(payload.voucher_number)}</VOUCHERNUMBER>
            <PARTYLEDGERNAME>${escapeXml(payload.party_ledger)}</PARTYLEDGERNAME>
            <PARTYNAME>${escapeXml(payload.party_ledger)}</PARTYNAME>
            <BASICBUYERNAME>${escapeXml(payload.party_ledger)}</BASICBUYERNAME>${optional('PARTYGSTIN', payload.party_gstin)}
            <ISINVOICE>No</ISINVOICE>
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
${inventory}
          </VOUCHER>`;

    return envelope(companyName, message);
}
