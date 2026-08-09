import { envelope, escapeXml, toTallyDate } from './xmlHelpers';

/** Matches TallySyncService's consolidated shift-voucher payload shape. */
export interface StockJournalPayload {
    voucher_type: string;
    voucher_date: string;
    voucher_number: string;
    batch_number?: string | null;
    godown?: string | null;
    narration: string | null;
    produced: { item: string; quantity: string; godown?: string | null }[];
    consumed: { item: string; quantity: string; godown?: string | null }[];
}

/**
 * The consolidated shift Stock Journal — DEC-20260807-010's "one Stock
 * Journal per (production_date, shift)", in the accountant's own format:
 * their 38 real Stock Journals were this shape's design evidence, and the
 * per-batch builder (manufacturingJournal.ts) proved the emitted XML against
 * the live TallyPrime (voucher #118, SPE-3/4/5).
 *
 * Same load-bearing rule as that builder: ISDEEMEDPOSITIVE decides the
 * column and the stock direction, not the tag name —
 *
 *   ISDEEMEDPOSITIVE=No   → Source (Consumption), LEFT  — stock DECREASES
 *   ISDEEMEDPOSITIVE=Yes  → Destination (Production), RIGHT — stock INCREASES
 *
 * One deliberate difference from the per-batch builder: per-line godowns are
 * honoured on BOTH sides. A consolidated voucher's produced lines each carry
 * the FG godown their member entry actually booked into, so the line's own
 * godown wins and the voucher-level godown is only the fallback. Value
 * (RATE/AMOUNT) is left for Tally to derive from item costs, as ever.
 */
export function buildStockJournalXml(payload: StockJournalPayload, companyName: string): string {
    const consumed = payload.consumed
        .map((line) => stockEntry('consumption', line, line.godown ?? payload.godown ?? null))
        .join('\n');

    const produced = payload.produced
        .map((line) => stockEntry('production', line, line.godown ?? payload.godown ?? null))
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
): string {
    const isProduction = role === 'production';
    const tag = isProduction ? 'IN' : 'OUT';
    const deemedPositive = isProduction ? 'Yes' : 'No';
    const batch = godown
        ? `
                <BATCHALLOCATIONS.LIST>
                  <GODOWNNAME>${escapeXml(godown)}</GODOWNNAME>
                  <BATCHNAME>Primary Batch</BATCHNAME>
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
