/**
 * DOCUMENT IDENTITY AND STATUS WORDS for the procurement registers that had
 * none: Purchase Requisitions and Goods Receipts (28-Aug audit, items 1 and
 * 10). Purchase Orders already keep theirs in purchaseOrders.ts (`poNumber`,
 * `statusTag`) — these follow the same shape so the three registers speak one
 * language, and they are pure so the drawer-title contract ("never
 * `#undefined`") is testable without a DOM.
 */

import type { IncomingInspection, InspectionResult } from '@/features/quality/types';
import type { GoodsReceiptNote, PurchaseRequisitionStatus } from './types';

// ------------------------------------------------------------ identities --

/** The ERP reference — "PR-{id}", the spelling the list's `q` reads back. */
export function prNumber(requisition: number | { id: number }): string {
    return `PR-${typeof requisition === 'number' ? requisition : requisition.id}`;
}

/**
 * The receipt's own number. The server has sent `document_number`
 * ("GRN-{id}") since Phase 6 and the register never read it — every surface
 * said `#3`, a bare row id. An older backend without the field still gets
 * the same spelling, built from the id.
 */
export function grnNumber(receipt: Pick<GoodsReceiptNote, 'id' | 'document_number'>): string {
    const sent = (receipt.document_number ?? '').trim();

    return sent !== '' ? sent : `GRN-${receipt.id}`;
}

/**
 * Drawer titles, null-safe BY CONTRACT. A Drawer's title is a header prop
 * that antd keeps rendering through the ~300ms close animation, after the
 * page has already set its record state to null — interpolating the record
 * into the title outside a null guard is exactly how "Goods Receipt
 * #undefined" reached the audit. The fallback is the plain document word,
 * which is what a closing drawer may honestly say.
 */
export function grnDrawerTitle(receipt: Pick<GoodsReceiptNote, 'id' | 'document_number'> | null): string {
    return receipt === null ? 'Goods Receipt' : `Goods Receipt ${grnNumber(receipt)}`;
}

export function prDrawerTitle(requisition: { id: number } | null): string {
    return requisition === null ? 'Purchase Requisition' : `Purchase Requisition ${prNumber(requisition)}`;
}

export function bagLabelsDrawerTitle(receipt: Pick<GoodsReceiptNote, 'id' | 'document_number'> | null): string {
    return receipt === null ? 'Bag labels' : `${grnNumber(receipt)} — bag labels ready`;
}

// --------------------------------------------------------------- statuses --

const REQUISITION_STATUS: Record<PurchaseRequisitionStatus, { color: string; label: string }> = {
    draft: { color: 'default', label: 'Draft' },
    approved: { color: 'green', label: 'Approved' },
    rejected: { color: 'red', label: 'Rejected' },
};

/** Sentence-case words, never the raw enum. An unknown status still renders. */
export function requisitionStatusTag(status: PurchaseRequisitionStatus | string): { color: string; label: string } {
    const known = REQUISITION_STATUS[status as PurchaseRequisitionStatus];
    if (known) return known;

    const words = String(status).replaceAll('_', ' ');

    return { color: 'default', label: words.charAt(0).toUpperCase() + words.slice(1) };
}

// -------------------------------------------------- vendor Tally mapping --

export type VendorLedgerWords =
    | { kind: 'not_mapped'; text: 'Not mapped' }
    | { kind: 'same_as_name'; text: 'Same as the vendor name' }
    | { kind: 'differs'; text: string };

/**
 * The vendor's Tally-ledger cell. Most imported vendors carry a ledger name
 * IDENTICAL to their own name (they came from Tally's ledgers), and printing
 * the same string twice per row taught readers to skip the column — hiding
 * the one row where the two genuinely differ, which is the only row the
 * column exists for. Same comparison rule as itemLabel: case and ALL
 * whitespace ignored, because two spellings that differ only by a space are
 * one value wearing a disguise.
 */
export function vendorLedgerWords(vendor: { name: string; tally_ledger_name?: string | null }): VendorLedgerWords {
    const ledger = (vendor.tally_ledger_name ?? '').trim();
    if (ledger === '') return { kind: 'not_mapped', text: 'Not mapped' };

    const bare = (value: string) => value.toLowerCase().replace(/\s+/g, '');

    return bare(ledger) === bare(vendor.name)
        ? { kind: 'same_as_name', text: 'Same as the vendor name' }
        : { kind: 'differs', text: ledger };
}

// ------------------------------------------------------- GRN ⇄ QC status --

export interface GrnQcSummary {
    state: 'no_lines' | 'awaiting' | 'in_progress' | 'done';
    color: string;
    words: string;
}

/**
 * Where a receipt stands with incoming inspection, derived from the
 * inspections register — the backend keeps no reverse link (a GRN cannot ask
 * "was I inspected?"), so the quality module's own list is the one honest
 * source. Grain is one inspection per GRN LINE; a receipt is done only when
 * every line has one, and the worst line's result names the whole receipt
 * (fail > partial > pass), because "QC passed" over one failed line would be
 * a lie a storekeeper acts on.
 */
export function grnQcSummary(
    lines: ReadonlyArray<{ id: number }>,
    inspectionByLineId: ReadonlyMap<number, Pick<IncomingInspection, 'result'>>,
): GrnQcSummary {
    if (lines.length === 0) return { state: 'no_lines', color: 'default', words: '—' };

    const results = lines
        .map((line) => inspectionByLineId.get(line.id)?.result)
        .filter((result): result is InspectionResult => result !== undefined);

    if (results.length === 0) return { state: 'awaiting', color: 'gold', words: 'Awaiting QC' };
    if (results.length < lines.length) {
        return { state: 'in_progress', color: 'gold', words: `QC ${results.length} of ${lines.length} lines` };
    }
    if (results.includes('fail')) return { state: 'done', color: 'red', words: 'QC failed' };
    if (results.includes('partial')) return { state: 'done', color: 'gold', words: 'QC partial' };

    return { state: 'done', color: 'green', words: 'QC passed' };
}

/** The inspections list keyed by the GRN line each one inspected. */
export function inspectionsByLine(
    inspections: ReadonlyArray<Pick<IncomingInspection, 'goods_receipt_note_line_id' | 'result'>> | undefined | null,
): Map<number, Pick<IncomingInspection, 'result'>> {
    const map = new Map<number, Pick<IncomingInspection, 'result'>>();
    for (const inspection of inspections ?? []) {
        map.set(inspection.goods_receipt_note_line_id, inspection);
    }

    return map;
}
