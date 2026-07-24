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
 * VALIDATED against the client's real TallyPrime (24-Jul-2026, voucher SPE-3,
 * SWAASHPET 26-27): the voucher structure imports cleanly as a plain STOCK
 * JOURNAL — emitted as such (not a BOM-driven Manufacturing Journal) so it
 * works whether or not the client has Tally's Manufacturing Journal/BOM
 * feature enabled (master plan §6 fallback).
 *
 * Direction mapping learned the hard way from that test — Tally renders:
 *   INVENTORYENTRIESIN.LIST  → the LEFT  "Source (Consumption)" column
 *   INVENTORYENTRIESOUT.LIST → the RIGHT "Destination (Production)" column
 * (i.e. the tag names read like stock direction but Tally treats them as
 * voucher-side names — the first cut had them swapped and the produced
 * bottles showed up as consumption.) So: consumed goods → IN, produced
 * goods → OUT. The produced finished goods carry the shift batch number.
 * Value (RATE/AMOUNT) is left to Tally to derive from item costs.
 */
export function buildManufacturingJournalXml(payload: ManufacturingJournalPayload, companyName: string): string {
    const consumed = payload.consumed
        .map((line) => stockEntry('IN', line, payload.godown, 'Primary Batch'))
        .join('\n');

    const produced = payload.produced
        .map((line) => stockEntry('OUT', line, payload.godown, payload.batch_number || 'Primary Batch'))
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
    // IN = Source/Consumption (deemed positive), OUT = Destination/Production —
    // see the direction note in the builder docblock. Godown/batch in the
    // batch allocation; value omitted so Tally derives it from item cost.
    const deemedPositive = direction === 'IN' ? 'Yes' : 'No';
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
