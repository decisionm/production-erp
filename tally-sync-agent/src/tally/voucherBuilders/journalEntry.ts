import { escapeXml, envelope, toTallyDate } from './xmlHelpers';

/** Matches TallySyncService::enqueueJournalEntry()'s payload shape exactly. */
export interface JournalEntryPayload {
    voucher_type: 'Journal';
    voucher_date: string;
    voucher_number: string;
    narration: string | null;
    lines: { ledger: string; debit: string; credit: string; memo: string | null }[];
}

/**
 * BEST-EFFORT TEMPLATE — NOT YET VALIDATED AGAINST A REAL TALLY INSTANCE.
 * See the warning in salesInvoice.ts — same caveat applies here. Journal
 * vouchers are structurally simpler than Sales (no inventory entries), so
 * this one is more likely to be close to correct as-is, but still confirm
 * against a real export before trusting it in production.
 */
export function buildJournalEntryXml(payload: JournalEntryPayload, companyName: string): string {
    const ledgerEntries = payload.lines
        .map((line) => {
            const isDebit = Number(line.debit) > 0;
            const amount = isDebit ? line.debit : line.credit;
            return `            <ALLLEDGERENTRIES.LIST>
              <LEDGERNAME>${escapeXml(line.ledger)}</LEDGERNAME>
              <ISDEEMEDPOSITIVE>${isDebit ? 'Yes' : 'No'}</ISDEEMEDPOSITIVE>
              <AMOUNT>${isDebit ? '-' : ''}${escapeXml(amount)}</AMOUNT>
              <NARRATION>${escapeXml(line.memo ?? '')}</NARRATION>
            </ALLLEDGERENTRIES.LIST>`;
        })
        .join('\n');

    const message = `          <VOUCHER VCHTYPE="Journal" ACTION="Create">
            <DATE>${toTallyDate(payload.voucher_date)}</DATE>
            <VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>
            <VOUCHERNUMBER>${escapeXml(payload.voucher_number)}</VOUCHERNUMBER>
            <NARRATION>${escapeXml(payload.narration ?? '')}</NARRATION>
${ledgerEntries}
          </VOUCHER>`;

    return envelope(companyName, message);
}
