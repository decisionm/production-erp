import { inventoryEntry } from './receiptNote';
import { envelope, escapeXml, toTallyDate } from './xmlHelpers';

/** Matches TallySyncService::enqueueDelivery()'s payload shape. */
export interface DeliveryNotePayload {
    voucher_type: 'Delivery Note';
    voucher_date: string;
    voucher_number: string;
    party_ledger: string | null;
    party_gstin: string | null;
    godown: string | null;
    narration: string | null;
    lines: { item: string; quantity: string }[];
}

/**
 * BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE.
 * A Delivery Note is a pure INVENTORY voucher (finished goods leaving stock;
 * the accounting Sales bill happens separately). Stock goes OUT, party is the
 * customer, no pricing. Validate the tag structure against a real export.
 */
export function buildDeliveryNoteXml(payload: DeliveryNotePayload, companyName: string): string {
    const inventory = payload.lines
        .map((line) => inventoryEntry(line, payload.godown, /* stockIn */ false))
        .join('\n');

    const party = payload.party_ledger
        ? `\n            <PARTYLEDGERNAME>${escapeXml(payload.party_ledger)}</PARTYLEDGERNAME>`
        : '';

    const message = `          <VOUCHER VCHTYPE="Delivery Note" ACTION="Create">
            <DATE>${toTallyDate(payload.voucher_date)}</DATE>
            <VOUCHERTYPENAME>Delivery Note</VOUCHERTYPENAME>
            <VOUCHERNUMBER>${escapeXml(payload.voucher_number)}</VOUCHERNUMBER>${party}
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
${inventory}
          </VOUCHER>`;

    return envelope(companyName, message);
}
