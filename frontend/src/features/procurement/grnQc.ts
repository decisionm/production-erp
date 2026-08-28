/**
 * WHERE AN ARRIVAL STANDS WITH INCOMING QC, in words — 28-Aug audit
 * finding 9. Two readers, one vocabulary: the GRN list's compact QC column
 * (one line for the whole receipt) and the drawer's per-line cell. Pure
 * module — pinned by grnQc.test.ts.
 *
 * The rules are the ones the backend enforces, not new ones:
 *   - an inspection is a line's disposition (0..1 — a second is refused);
 *   - bags in waiting_qc are the physical hold (DEC-20260825-001): the
 *     material's kilograms cannot leave the store while they stand;
 *   - a line with no bag-tracked lots has no hold by construction, and no
 *     inspection requirement this module may invent (whether EVERY arrival
 *     must pass QA is expressly open — the same decision says so). Such a
 *     line reads "No inspection recorded", a fact, not a demand.
 */
import type { GoodsReceiptLineQc, GoodsReceiptNoteLine } from './types';

export interface QcLine {
    /** Drives the antd Tag. */
    color: string;
    text: string;
    /** True when "Record inspection" is worth offering — nothing has been recorded yet. */
    offerInspection: boolean;
}

/** One line's QC cell. */
export function lineQcLine(qc: GoodsReceiptLineQc | null | undefined): QcLine {
    if (qc === null || qc === undefined) {
        return { color: 'default', text: 'QC not readable here', offerInspection: false };
    }

    if (qc.inspection) {
        const { result, accepted_quantity, rejected_quantity } = qc.inspection;
        if (result === 'pass') return { color: 'green', text: 'QC passed', offerInspection: false };
        if (result === 'fail') return { color: 'red', text: `QC rejected — ${rejected_quantity} rejected`, offerInspection: false };
        if (result === 'partial') {
            return {
                color: 'gold',
                text: `QC partial — ${accepted_quantity} accepted, ${rejected_quantity} rejected`,
                offerInspection: false,
            };
        }

        return { color: 'default', text: `QC recorded (${result})`, offerInspection: false };
    }

    if (qc.bags && qc.bags.waiting_qc > 0) {
        return {
            color: 'orange',
            text: `Waiting for QC — ${qc.bags.waiting_qc} of ${qc.bags.total} bags held`,
            offerInspection: true,
        };
    }

    return { color: 'default', text: 'No inspection recorded', offerInspection: true };
}

/**
 * The whole receipt's QC standing, for the register's compact column —
 * worst news first: a rejection outranks a hold outranks "nothing yet",
 * and only a receipt whose every line was inspected says "QC done".
 */
export function receiptQcLine(lines: Pick<GoodsReceiptNoteLine, 'qc'>[]): QcLine {
    const known = lines.map((line) => line.qc).filter((qc): qc is GoodsReceiptLineQc => qc !== null && qc !== undefined);
    if (known.length === 0) {
        return { color: 'default', text: '—', offerInspection: false };
    }

    if (known.some((qc) => qc.inspection?.result === 'fail' || qc.inspection?.result === 'partial')) {
        return { color: 'red', text: 'QC rejection recorded', offerInspection: false };
    }
    if (known.some((qc) => !qc.inspection && (qc.bags?.waiting_qc ?? 0) > 0)) {
        return { color: 'orange', text: 'Waiting for QC', offerInspection: true };
    }
    if (known.every((qc) => qc.inspection !== null)) {
        return { color: 'green', text: 'QC done', offerInspection: false };
    }

    return { color: 'default', text: 'No inspection recorded', offerInspection: true };
}
