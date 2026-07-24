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
 * Emitted as a plain STOCK JOURNAL (not a BOM-driven Manufacturing Journal) so
 * it works whether or not the client has Tally's Manufacturing Journal/BOM
 * feature enabled (master plan §6 fallback).
 *
 * COLUMN + STOCK DIRECTION — verified against SWAASHPET's real production
 * voucher (Stock Journal #118) and three live test vouchers (SPE-3/4/5):
 * TallyPrime decides which side of a Stock Journal a line lands on — and which
 * way its stock moves — by ISDEEMEDPOSITIVE, *not* by the INVENTORYENTRIESIN/
 * OUT.LIST tag name (a tag-only swap renders identically, which is exactly how
 * SPE-3 and SPE-5 came out the same).
 *
 *   ISDEEMEDPOSITIVE=No   → Source (Consumption), LEFT  — stock DECREASES
 *   ISDEEMEDPOSITIVE=Yes  → Destination (Production), RIGHT — stock INCREASES
 *
 * So consumed raw materials → No, produced finished goods → Yes. Tags are still
 * paired conventionally (consumption in OUT.LIST, production in IN.LIST) to
 * match a hand-made voucher, but the ISDEEMEDPOSITIVE value is what's load-
 * bearing. Value (RATE/AMOUNT) is left for Tally to derive from item costs.
 */
export function buildManufacturingJournalXml(payload: ManufacturingJournalPayload, companyName: string): string {
    const consumed = payload.consumed
        .map((line) => stockEntry('consumption', line, payload.godown, 'Primary Batch'))
        .join('\n');

    const produced = payload.produced
        .map((line) => stockEntry('production', line, payload.godown, payload.batch_number || 'Primary Batch'))
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
    role: 'consumption' | 'production',
    line: { item: string; quantity: string },
    godown: string | null,
    batchName: string,
): string {
    // See the builder docblock: ISDEEMEDPOSITIVE (not the tag) drives both the
    // column and the stock direction. Production = Yes (Destination, stock in),
    // consumption = No (Source, stock out). Godown/batch in the batch
    // allocation; value omitted so Tally derives it from item cost.
    const isProduction = role === 'production';
    const tag = isProduction ? 'IN' : 'OUT';
    const deemedPositive = isProduction ? 'Yes' : 'No';
    const batch = godown
        ? `
                <BATCHALLOCATIONS.LIST>
                  <GODOWNNAME>${escapeXml(godown)}</GODOWNNAME>
                  <BATCHNAME>${escapeXml(batchName)}</BATCHNAME>
                  <ACTUALQTY>${escapeXml(line.quantity)}</ACTUALQTY>
                  <BILLEDQTY>${escapeXml(line.quantity)}</BILLEDQTY>
                </BATCHALLOCATIONS.LIST>`
        : '';

    return `            <INVENTORYENTRIES${tag}.LIST>
              <STOCKITEMNAME>${escapeXml(line.item)}</STOCKITEMNAME>
              <ISDEEMEDPOSITIVE>${deemedPositive}</ISDEEMEDPOSITIVE>
              <ACTUALQTY>${escapeXml(line.quantity)}</ACTUALQTY>
              <BILLEDQTY>${escapeXml(line.quantity)}</BILLEDQTY>${batch}
            </INVENTORYENTRIES${tag}.LIST>`;
}
