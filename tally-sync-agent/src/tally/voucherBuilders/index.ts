import type { TallySyncEntry } from '../../cloudApi';
import { buildDeliveryNoteXml, type DeliveryNotePayload } from './deliveryNote';
import { buildJournalEntryXml, type JournalEntryPayload } from './journalEntry';
import { buildManufacturingJournalXml, type ManufacturingJournalPayload } from './manufacturingJournal';
import { buildPurchaseOrderXml, type PurchaseOrderPayload } from './purchaseOrder';
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
        case 'Purchase Order':
            // Phase 6 (0.3.9): the ERP-raised purchase order as a Tally ORDER
            // voucher (DEC-20260812-002) — staged on the cloud behind
            // tally-sync.purchase_orders_enabled, which is OFF until the owner
            // opens the gate (Q35), so no entry of this type reaches an agent
            // today. The builder is complete and tested regardless; an agent
            // < 0.3.9 would fail such an entry with the "No XML builder" error
            // below, which is the honest outcome, never a silent post.
            return buildPurchaseOrderXml(entry.payload as unknown as PurchaseOrderPayload, companyName);
        case 'Stock Journal':
            // The consolidated shift voucher's own label (DEC-20260807-010).
            // The server ALREADY sends it: TallySyncService::enqueue... writes
            // tally_voucher_type 'Stock Journal' for every shift voucher. This
            // case is therefore load-bearing, not forward-looking — an agent
            // without it fails those vouchers with the "No XML builder" error
            // below, which is what entries #33/#34 hit on 07-Aug-2026.
            // The requirement that follows: every DEPLOYED agent must be
            // >= 0.3.5. An older agent still on a factory PC cannot post a
            // shift voucher at all.
            // (History: the first shift vouchers predate this case and were
            // relabelled server-side to ride the Manufacturing Journal builder
            // above — PR #149. That workaround is gone.)
            return buildStockJournalXml(entry.payload as unknown as StockJournalPayload, companyName);
        default:
            throw new Error(`No XML builder for voucher type "${entry.tally_voucher_type}" (entry #${entry.id})`);
    }
}
