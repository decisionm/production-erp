import type { TallySyncEntry } from '../../cloudApi';
import { buildSalesInvoiceXml, type SalesInvoicePayload } from './salesInvoice';
import { buildJournalEntryXml, type JournalEntryPayload } from './journalEntry';

/**
 * Dispatches by tally_voucher_type — matches whatever TallySyncService's
 * enqueueX() methods produce on the cloud side. Adding a new voucher type
 * (e.g. the planned Manufacturing Journal — see master plan §4/Phase 4)
 * means: a new payload type + builder file here, one new case below, and
 * the matching cloud-side enqueueX() — no other agent code changes.
 */
export function buildVoucherXml(entry: TallySyncEntry, companyName: string): string {
    switch (entry.tally_voucher_type) {
        case 'Sales':
            return buildSalesInvoiceXml(entry.payload as unknown as SalesInvoicePayload, companyName);
        case 'Journal':
            return buildJournalEntryXml(entry.payload as unknown as JournalEntryPayload, companyName);
        default:
            throw new Error(`No XML builder for voucher type "${entry.tally_voucher_type}" (entry #${entry.id})`);
    }
}
