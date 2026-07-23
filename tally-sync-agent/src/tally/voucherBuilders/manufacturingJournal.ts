import { envelope, escapeXml, toTallyDate } from './xmlHelpers';

/** Matches TallySyncService::enqueueShiftProductionEntry()'s payload shape. */
export interface ManufacturingJournalPayload {
    voucher_type: 'Manufacturing Journal';
    voucher_date: string;
    voucher_number: string;
    batch_number: string | null;
    godown: string | null;
    narration: string | null;
    produced: { item: string; quantity: string }[];
    consumed: { item: string; quantity: string }[];
}

/**
 * BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE.
 * Emitted as a plain STOCK JOURNAL (consumption OUT + production IN) rather than
 * a BOM-driven Manufacturing Journal, so it works whether or not the client has
 * Tally's Manufacturing Journal/BOM feature enabled (master plan §6 fallback).
 * The produced finished goods carry the shift batch number. Value (RATE/AMOUNT)
 * is left to Tally to derive from item costs. Validate against a real export:
 * INVENTORYENTRIESIN/OUT, ISDEEMEDPOSITIVE and batch/godown tags vary by version.
 */
export function buildManufacturingJournalXml(payload: ManufacturingJournalPayload, companyName: string): string {
    const consumed = payload.consumed
        .map((line) => stockEntry('OUT', line, payload.godown, 'Primary Batch'))
        .join('\n');

    const produced = payload.produced
        .map((line) => stockEntry('IN', line, payload.godown, payload.batch_number || 'Primary Batch'))
        .join('\n');

    const message = `          <VOUCHER VCHTYPE="Stock Journal" ACTION="Create">
            <DATE>${toTallyDate(payload.voucher_date)}</DATE>
            <VOUCHERTYPENAME>Stock Journal</VOUCHERTYPENAME>
            <VOUCHERNUMBER>${escapeXml(payload.voucher_number)}</VOUCHERNUMBER>
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
${consumed}
${produced}
          </VOUCHER>`;

    return envelope(companyName, message);
}

function stockEntry(
    direction: 'IN' | 'OUT',
    line: { item: string; quantity: string },
    godown: string | null,
    batchName: string,
): string {
    // OUT = consumption (deemed positive), IN = production. Godown/batch in the
    // batch allocation; value omitted so Tally derives it from item cost.
    const deemedPositive = direction === 'OUT' ? 'Yes' : 'No';
    const batch = godown
        ? `
                <BATCHALLOCATIONS.LIST>
                  <GODOWNNAME>${escapeXml(godown)}</GODOWNNAME>
                  <BATCHNAME>${escapeXml(batchName)}</BATCHNAME>
                  <ACTUALQTY>${escapeXml(line.quantity)}</ACTUALQTY>
                  <BILLEDQTY>${escapeXml(line.quantity)}</BILLEDQTY>
                </BATCHALLOCATIONS.LIST>`
        : '';

    return `            <INVENTORYENTRIES${direction}.LIST>
              <STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>
              <ISDEEMEDPOSITIVE>${deemedPositive}</ISDEEMEDPOSITIVE>
              <ACTUALQTY>${escapeXml(line.quantity)}</ACTUALQTY>
              <BILLEDQTY>${escapeXml(line.quantity)}</BILLEDQTY>${batch}
            </INVENTORYENTRIES${direction}.LIST>`;
}
