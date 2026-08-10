import type { FinishedCarton } from '@/features/production/types';

function fmtPieces(value: string): string {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parseFloat(parsed.toFixed(4)).toLocaleString('en-IN') : value;
}

function fmtKg(value: string): string {
    const parsed = Number(value);
    return Number.isFinite(parsed)
        ? parsed.toLocaleString('en-IN', { maximumFractionDigits: 3 })
        : value;
}

/**
 * The human-readable lines under the barcode (DEC-20260807-009). Weight is
 * NET only — gross needs the empty carton's tare, which the factory has not
 * stated (pending Q15), and a figure the data cannot support is omitted, not
 * estimated. Customer/PO appears only when the batch really is against a
 * sales order — no such linkage exists in the schema today, so the line
 * simply never renders yet.
 *
 * DEC-20260810-001 adds ONE line: the batch's completion date (factory
 * wall-clock, from the label read's `completion` block — the shift it names
 * is on the line above already). Nothing else on the label changes, the
 * barcode payload stays the carton code only, and no cost, rate or lot
 * identity may ever reach this function — those live solely on the
 * permission-gated internal trace tier.
 *
 * A pure module (the shiftClock.ts precedent) so the label's exact wording
 * is testable without rendering the print window.
 */
export function labelDetails(carton: FinishedCarton): string[] {
    const sku = carton.item?.sku ?? 'Item';
    const lines = [
        `${sku} — ${carton.item?.name ?? 'Finished goods'}`,
        `${fmtPieces(carton.pieces)} pcs${carton.is_partial ? ' · PARTIAL BOX' : ''}`,
    ];
    if (carton.batch?.nos_per_box != null) {
        lines.push(`Nos per box: ${fmtPieces(carton.batch.nos_per_box)}`);
    }
    if (carton.net_weight_kg != null) {
        lines.push(`Net weight: ${fmtKg(carton.net_weight_kg)} kg`);
    }
    lines.push(
        `Batch ${carton.batch?.batch_number ?? '—'} · ${carton.batch?.production_date ?? '—'}`,
        `${carton.batch?.machine ?? '—'} · ${carton.batch?.shift ?? '—'} shift`,
    );
    // Absent when the server could not resolve the instant (a completion
    // that recorded no material lines) — a missing date prints missing,
    // never approximated from the production date beside it.
    if (carton.completion?.completed_on != null) {
        lines.push(`Completed: ${carton.completion.completed_on}`);
    }
    if (carton.sales_order) {
        const so = [
            carton.sales_order.customer,
            carton.sales_order.order_no ? `PO ${carton.sales_order.order_no}` : null,
        ]
            .filter(Boolean)
            .join(' · ');
        if (so) lines.push(so);
    }
    return lines;
}
