import type { TallySyncStatus } from '@/features/tally-sync/types';
import type { ProductionMetrics } from './types';
import type { CecBatch, CecReport, CecShiftBlock, CecSums, ShiftKpiReport, ShiftProductionEntryStatus } from './types';

/**
 * CEC preview (Phase 5.7, WS-C) — the pure part of CecPreviewPanel: it takes
 * `GET production/cec` as the server sends it and lays the figures out
 * shift → machine → batches for the eye. NOTHING IS COMPUTED HERE: every
 * cell is the server's own string or number shown verbatim (no rounding,
 * no regrouping, no totals of our own — the sums shown are the server's
 * labelled plain sums), a key the server does not send reads "—", and the
 * caption says what this is — a preview of the DATA while the FORMAT stays
 * blocked for want of the owner's sample. No layout is asserted or
 * invented; the day a sample lands, the columns are its columns.
 */

/** Fixed wording — the panel's caption, verbatim from the Phase 5.7 contract. */
export const CEC_PREVIEW_CAPTION = 'CEC preview — format pending: owner sample required';

/** A figure as text: strings and numbers verbatim, a missing one as "—". */
export function figureText(value: string | number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'number') return String(value);
    return value === '' ? '—' : value;
}

/** A percentage the server sent as a number, with its sign; "—" for none. */
function percentText(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : `${value}%`;
}

export interface CecPreviewBatchRow {
    key: number;
    entryId: number;
    /** batch_number, else "#entry_id". */
    batch: string;
    sku: string;
    product: string;
    expectedPieces: string;
    actualPieces: string;
    goodKg: string;
    rejectionKg: string;
    rejectionKgQc: string;
    /** "46.2%" or "—" — the server's efficiency_pct as sent. */
    efficiency: string;
    efficiencyBand: ProductionMetrics['efficiency_band'] | null;
    expectedBoxes: string;
    packs: string;
    downtimeMinutes: string;
    approval: ShiftProductionEntryStatus | '—';
    tallyStatus: TallySyncStatus | '—';
    tallyVoucher: string;
    tallyLink: string | null;
}

/** One batch line — each column the server's figure, placed. */
export function cecBatchRow(batch: CecBatch): CecPreviewBatchRow {
    const number = (batch.batch_number ?? '').trim();
    const item = batch.item ?? null;
    const sku = (item?.sku ?? '').trim();
    const name = (item?.name ?? '').trim();
    const tally = batch.tally ?? null;
    return {
        key: batch.entry_id,
        entryId: batch.entry_id,
        batch: number !== '' ? number : `#${batch.entry_id}`,
        sku: sku === '' ? '—' : sku,
        product: name === '' ? '—' : name,
        expectedPieces: figureText(batch.expected_pieces),
        actualPieces: figureText(batch.actual_pieces),
        goodKg: figureText(batch.good_production_kg),
        rejectionKg: figureText(batch.rejection_kg),
        rejectionKgQc: figureText(batch.rejection_kg_qc),
        efficiency: percentText(batch.efficiency_pct),
        efficiencyBand: batch.efficiency_band ?? null,
        expectedBoxes: figureText(batch.expected_boxes),
        packs: figureText(batch.packs),
        downtimeMinutes: figureText(batch.downtime_minutes_total),
        approval: batch.approval_status ?? '—',
        tallyStatus: batch.tally_status ?? '—',
        tallyVoucher: figureText(tally?.voucher_number),
        tallyLink: tally?.link ?? null,
    };
}

export interface CecPreviewItem {
    key: string;
    label: string;
    value: string;
}

/**
 * The server's plain sums for a block, as label/value in a fixed order —
 * a null sum reads "—" (nothing to sum is not zero), and the batches each
 * figure could not include are named ("expected pieces × 1"), as the
 * server counted them.
 */
export function cecSumsItems(sums: CecSums | null | undefined): CecPreviewItem[] {
    if (!sums) return [];
    const skipped = Object.entries(sums.skipped_nulls ?? {});
    return [
        { key: 'batches', label: 'Batches', value: String(sums.batches) },
        { key: 'expected_pieces', label: 'Expected (pcs)', value: figureText(sums.expected_pieces) },
        { key: 'actual_pieces', label: 'Actual (pcs)', value: figureText(sums.actual_pieces) },
        { key: 'good_production_kg', label: 'Good (kg)', value: figureText(sums.good_production_kg) },
        { key: 'rejection_kg', label: 'Rejection (kg)', value: figureText(sums.rejection_kg) },
        { key: 'packs', label: 'Packs', value: figureText(sums.packs) },
        { key: 'downtime_minutes_total', label: 'Downtime (min)', value: figureText(sums.downtime_minutes_total) },
        {
            key: 'skipped_nulls',
            label: 'Not summed (null)',
            value: skipped.length === 0 ? 'none' : skipped.map(([k, n]) => `${k.replace(/_/g, ' ')} × ${n}`).join(' · '),
        },
    ];
}

/**
 * The Shift Summary figures the CEC carries for a shift (or the day),
 * verbatim — the report's own strings, its percentages with a sign, its
 * efficiency labelled against the basis the server names.
 */
export function cecSummaryItems(summary: ShiftKpiReport | null | undefined): CecPreviewItem[] {
    if (!summary) return [];
    return [
        { key: 'target_production_kg', label: 'Target (kg)', value: figureText(summary.target_production_kg) },
        { key: 'actual_production_kg', label: 'Actual (kg)', value: figureText(summary.actual_production_kg) },
        { key: 'rejection_kg', label: 'Rejection (kg)', value: figureText(summary.rejection_kg) },
        { key: 'net_good_output_kg', label: 'Net good (kg)', value: figureText(summary.net_good_output_kg) },
        {
            key: 'efficiency_percent',
            label: summary.efficiency_basis === 'supervisor_target' ? 'Efficiency (vs supervisor target)' : 'Efficiency',
            value: percentText(summary.efficiency_percent),
        },
        { key: 'rejection_percent', label: 'Rejection %', value: percentText(summary.rejection_percent) },
        { key: 'power_consumption_units', label: 'Power (units)', value: figureText(summary.power_consumption_units) },
        { key: 'unit_per_kg', label: 'Unit / kg', value: figureText(summary.unit_per_kg) },
        { key: 'idle_time_hours', label: 'Idle — machines (h)', value: figureText(summary.idle_time_hours) },
        { key: 'power_interruption_hours', label: 'Idle — power cuts (h)', value: figureText(summary.power_interruption_hours) },
        { key: 'no_of_mold_changes', label: 'Mold changes', value: figureText(summary.no_of_mold_changes) },
    ];
}

export interface CecPreviewMachine {
    key: number;
    /** The machine's name, else its code, else "#id" for one since deleted. */
    machine: string;
    rows: CecPreviewBatchRow[];
    sums: CecSums;
}

export interface CecPreviewShift {
    key: number;
    shift: string;
    summary: ShiftKpiReport;
    machines: CecPreviewMachine[];
    sums: CecSums;
}

/** The whole report nested as sent: shift → machine → batch rows, with each level's summary/sums. */
export function cecShiftSections(report: CecReport | null | undefined): CecPreviewShift[] {
    const shifts: CecShiftBlock[] = Array.isArray(report?.shifts) ? report.shifts : [];
    return shifts.map((block) => ({
        key: block.shift.id,
        shift: block.shift.name,
        summary: block.summary,
        machines: (Array.isArray(block.machines) ? block.machines : []).map((machineBlock) => {
            const name = (machineBlock.machine.name ?? '').trim();
            const code = (machineBlock.machine.code ?? '').trim();
            return {
                key: machineBlock.machine.id,
                machine: name !== '' ? name : code !== '' ? code : `#${machineBlock.machine.id}`,
                rows: (Array.isArray(machineBlock.batches) ? machineBlock.batches : []).map(cecBatchRow),
                sums: machineBlock.sums,
            };
        }),
        sums: block.sums,
    }));
}
