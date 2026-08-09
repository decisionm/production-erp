import type { TallySyncEntry } from '../../cloudApi';
import { buildDeliveryNoteXml, type DeliveryNotePayload } from './deliveryNote';
import { buildJournalEntryXml, type JournalEntryPayload } from './journalEntry';
import { buildManufacturingJournalXml, type ManufacturingJournalPayload } from './manufacturingJournal';
import { buildReceiptNoteXml, type ReceiptNotePayload } from './receiptNote';
import { buildSalesInvoiceXml, type SalesInvoicePayload } from './salesInvoice';
import { buildStockJournalXml, type StockJournalPayload } from './stockJournal';

/**
 * Dispatches by tally_voucher_type — matches whatever TallySyncService's
 * enqueueX() methods produce on the cloud side. Adding a new voucher type means:
 * a new payload type + builder file here, one new case below, and the matching
 * cloud-side enqueueX() — no other agent code changes.
 */
export function buildVoucherXml(entry: TallySyncEntry, companyName: string): string {
    switch (entry.tally_voucher_type) {
        case 'Sales':
            return buildSalesInvoiceXml(entry.payload as unknown as SalesInvoicePayload, companyName);
        case 'Journal':
            return buildJournalEntryXml(entry.payload as unknown as JournalEntryPayload, companyName);
        case 'Receipt Note':
            return buildReceiptNoteXml(entry.payload as unknown as ReceiptNotePayload, companyName);
        case 'Delivery Note':
            return buildDeliveryNoteXml(entry.payload as unknown as DeliveryNotePayload, companyName);
        case 'Manufacturing Journal':
            return buildManufacturingJournalXml(entry.payload as unknown as ManufacturingJournalPayload, companyName);
        case 'Stock Journal':
            // The consolidated shift voucher's own label (DEC-20260807-010).
            // The first shift vouchers shipped before this case existed and
            // were relabelled server-side to ride the builder above (PR #149);
            // once every factory agent runs >= 0.3.5 the server can label
            // shift vouchers honestly again — server flip strictly AFTER the
            // agent fleet updates, never before.
            return buildStockJournalXml(entry.payload as unknown as StockJournalPayload, companyName);
        default:
            throw new Error(`No XML builder for voucher type "${entry.tally_voucher_type}" (entry #${entry.id})`);
    }
}
