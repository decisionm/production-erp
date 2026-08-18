import { describe, expect, it } from 'vitest';
import { CEC_PREVIEW_CAPTION, cecBatchRow, cecShiftSections, cecSummaryItems, cecSumsItems, figureText } from './cecPreview';
import type { CecBatch, CecReport, CecSums } from './types';

/**
 * A CEC report as GET production/cec sends it (CecReportTest's fixture,
 * 2026-08-10): the Morning shift's two machines, three batches; every
 * figure a string/number the SERVER computed. Only the keys the mapper reads
 * are load-bearing; the summary blocks are cast partials.
 */
const sums = (overrides: Partial<CecSums> = {}): CecSums => ({
    batches: 2,
    expected_pieces: '12735.8500',
    actual_pieces: '10080.0000',
    good_production_kg: '120.9600',
    rejection_kg: '7.7529',
    packs: 12,
    downtime_minutes_total: '30.00',
    skipped_nulls: { expected_pieces: 1, rejection_kg: 1 },
    basis: 'Plain sums of the batch figures above; a null is skipped and counted, never read as zero.',
    ...overrides,
});

const a1: CecBatch = {
    entry_id: 41,
    batch_number: '20260810-M01-001',
    item: { id: 9, sku: 'BTL-840', name: 'Bottle 840-pack' },
    expected_pieces: '12735.85',
    actual_pieces: '5880',
    good_production_kg: '70.5600',
    rejection_kg: '7.7529',
    rejection_kg_qc: null,
    efficiency_pct: 46.2,
    efficiency_band: 'investigate',
    expected_boxes: 15,
    packs: 7,
    downtime_minutes_total: '30.00',
    calculation_version: 'production_v3_unified',
    approval_status: 'pending',
    tally_status: null,
    tally: null,
};

const a2: CecBatch = {
    ...a1,
    entry_id: 42,
    batch_number: '20260810-M01-002',
    expected_pieces: null,
    actual_pieces: '4200',
    good_production_kg: '50.4000',
    rejection_kg: null,
    efficiency_pct: null,
    efficiency_band: null,
    expected_boxes: null,
    packs: 5,
    downtime_minutes_total: null,
};

const b1: CecBatch = {
    ...a1,
    entry_id: 43,
    batch_number: '20260810-M02-001',
    item: { id: 10, sku: 'BTL-1500', name: 'Bottle 1500-pack' },
    expected_pieces: '12000.00',
    actual_pieces: '10500',
    good_production_kg: '105.0000',
    rejection_kg: '2.1000',
    rejection_kg_qc: '2.0000',
    efficiency_pct: 87.5,
    efficiency_band: 'watch',
    packs: 7,
    downtime_minutes_total: '0.00',
    approval_status: 'approved',
    tally_status: 'pending',
    tally: { entry_id: 43, voucher_type: 'Stock Journal', status: 'pending', voucher_number: 'SJ-20260810-M', synced_at: null, flags: {} as never, link: '/tally-sync?entry=43' },
};

const morningSummary = {
    shift_id: 1,
    production_date: '2026-08-10',
    target_production_kg: '300.0000',
    actual_production_kg: '225.9600',
    rejection_kg: '9.8529',
    net_good_output_kg: '216.1071',
    efficiency_percent: 75.32,
    efficiency_basis: 'supervisor_target',
    rejection_percent: 4.36,
    machines_running: 0,
    machines_down: 0,
    machines_running_now: 0,
    machines_down_now: 0,
    idle_time_hours: '0.5000',
    no_of_mold_changes: 0,
    power_consumption_units: '120.0000',
    unit_per_kg: 0.531,
    power_interruption_hours: '0.5000',
} as unknown as CecReport['shifts'][number]['summary'];

const report = (overrides: Partial<CecReport> = {}): CecReport => ({
    format: 'BLOCKED — SOURCE DOCUMENT REQUIRED',
    figures_from: ['shift_summary', 'shift_production_entries'],
    production_date: '2026-08-10',
    shift_id: null,
    scope: 'day',
    shifts: [
        {
            shift: { id: 1, name: 'Morning' },
            summary: morningSummary,
            machines: [
                { machine: { id: 1, code: 'MC-01', name: 'Machine 1' }, batches: [a1, a2], sums: sums() },
                { machine: { id: 2, code: 'MC-02', name: 'Machine 2' }, batches: [b1], sums: sums({ batches: 1, expected_pieces: '12000.0000', actual_pieces: '10500.0000', good_production_kg: '105.0000', rejection_kg: '2.1000', packs: 7, downtime_minutes_total: '0.00', skipped_nulls: {} }) },
            ],
            sums: sums({ batches: 3, expected_pieces: '24735.8500', actual_pieces: '20580.0000', good_production_kg: '225.9600', rejection_kg: '9.8529', packs: 19, downtime_minutes_total: '30.00' }),
        },
    ],
    day: {
        summary: { ...morningSummary, shift_id: null } as unknown as CecReport['shifts'][number]['summary'],
        sums: sums({ batches: 3, expected_pieces: '24735.8500', actual_pieces: '20580.0000', good_production_kg: '225.9600', rejection_kg: '9.8529', packs: 19, downtime_minutes_total: '30.00' }),
    },
    ...overrides,
});

describe('the caption is fixed wording, not a layout claim', () => {
    it('reads exactly as the contract states it', () => {
        expect(CEC_PREVIEW_CAPTION).toBe('CEC preview — format pending: owner sample required');
    });
});

describe('figureText — shows what the server sent, invents nothing', () => {
    it('shows strings and numbers verbatim (no rounding, no regrouping)', () => {
        expect(figureText('12735.85')).toBe('12735.85');
        expect(figureText('70.5600')).toBe('70.5600');
        expect(figureText(46.2)).toBe('46.2');
        expect(figureText(0)).toBe('0');
    });

    it('reads a missing figure as "—", never 0', () => {
        expect(figureText(null)).toBe('—');
        expect(figureText(undefined)).toBe('—');
        expect(figureText('')).toBe('—');
    });
});

describe('cecBatchRow — every column is the server’s figure as sent', () => {
    it('places each figure in its column without recomputing any', () => {
        expect(cecBatchRow(a1)).toEqual({
            key: 41,
            entryId: 41,
            batch: '20260810-M01-001',
            sku: 'BTL-840',
            product: 'Bottle 840-pack',
            expectedPieces: '12735.85',
            actualPieces: '5880',
            goodKg: '70.5600',
            rejectionKg: '7.7529',
            rejectionKgQc: '—',
            efficiency: '46.2%',
            efficiencyBand: 'investigate',
            expectedBoxes: '15',
            packs: '7',
            downtimeMinutes: '30.00',
            approval: 'pending',
            tallyStatus: '—',
            tallyVoucher: '—',
            tallyLink: null,
        });
    });

    it('shows a run without a standard as "—" for expected/efficiency, never 0', () => {
        const row = cecBatchRow(a2);
        expect(row.expectedPieces).toBe('—');
        expect(row.efficiency).toBe('—');
        expect(row.efficiencyBand).toBeNull();
        expect(row.rejectionKg).toBe('—');
        expect(row.expectedBoxes).toBe('—');
        expect(row.downtimeMinutes).toBe('—');
    });

    it('carries the QC weight, the approval and the Tally voucher as sent', () => {
        const row = cecBatchRow(b1);
        expect(row.rejectionKgQc).toBe('2.0000');
        expect(row.approval).toBe('approved');
        expect(row.tallyStatus).toBe('pending');
        expect(row.tallyVoucher).toBe('SJ-20260810-M');
        expect(row.tallyLink).toBe('/tally-sync?entry=43');
    });

    it('names a batch by entry id when it has no number, and never breaks on a missing item', () => {
        const row = cecBatchRow({ ...a1, batch_number: null, item: null });
        expect(row.batch).toBe('#41');
        expect(row.sku).toBe('—');
        expect(row.product).toBe('—');
    });
});

describe('cecShiftSections — shift → machine → batches, in the server’s order', () => {
    it('nests the report as sent, machine name preferred over code, sums carried per level', () => {
        const sections = cecShiftSections(report());

        expect(sections).toHaveLength(1);
        expect(sections[0].key).toBe(1);
        expect(sections[0].shift).toBe('Morning');
        expect(sections[0].machines.map((m) => m.machine)).toEqual(['Machine 1', 'Machine 2']);
        expect(sections[0].machines[0].rows.map((r) => r.batch)).toEqual(['20260810-M01-001', '20260810-M01-002']);
        expect(sections[0].machines[1].rows.map((r) => r.batch)).toEqual(['20260810-M02-001']);
        expect(sections[0].sums.batches).toBe(3);
        expect(sections[0].machines[0].sums.good_production_kg).toBe('120.9600');
        expect(sections[0].summary.actual_production_kg).toBe('225.9600');
    });

    it('falls back to the machine code when no name is sent, and to "#id" for a machine since deleted', () => {
        const r = report();
        r.shifts[0].machines[0].machine = { id: 1, code: 'MC-01', name: null };
        expect(cecShiftSections(r)[0].machines[0].machine).toBe('MC-01');
        r.shifts[0].machines[0].machine = { id: 1, code: null, name: null };
        expect(cecShiftSections(r)[0].machines[0].machine).toBe('#1');
    });

    it('shows a batch with no approval status as "—"', () => {
        expect(cecBatchRow({ ...a1, approval_status: null }).approval).toBe('—');
    });

    it('is empty — not a crash — for a report with no shifts or missing arrays', () => {
        expect(cecShiftSections(report({ shifts: [] }))).toEqual([]);
        expect(cecShiftSections(null)).toEqual([]);
        expect(cecShiftSections(undefined)).toEqual([]);
        const r = report();
        r.shifts[0].machines = undefined as unknown as never;
        expect(cecShiftSections(r)[0].machines).toEqual([]);
    });
});

describe('cecSumsItems — the server’s plain sums as label/value, nulls honest, skipped nulls named', () => {
    it('lists the figures in a fixed order with the server’s strings verbatim', () => {
        expect(cecSumsItems(sums())).toEqual([
            { key: 'batches', label: 'Batches', value: '2' },
            { key: 'expected_pieces', label: 'Expected (pcs)', value: '12735.8500' },
            { key: 'actual_pieces', label: 'Actual (pcs)', value: '10080.0000' },
            { key: 'good_production_kg', label: 'Good (kg)', value: '120.9600' },
            { key: 'rejection_kg', label: 'Rejection (kg)', value: '7.7529' },
            { key: 'packs', label: 'Packs', value: '12' },
            { key: 'downtime_minutes_total', label: 'Downtime (min)', value: '30.00' },
            { key: 'skipped_nulls', label: 'Not summed (null)', value: 'expected pieces × 1 · rejection kg × 1' },
        ]);
    });

    it('shows a null sum as "—" and no skipped nulls as "none"', () => {
        const items = cecSumsItems(sums({ batches: 0, expected_pieces: null, actual_pieces: null, good_production_kg: null, rejection_kg: null, packs: null, downtime_minutes_total: null, skipped_nulls: {} }));
        expect(items.find((i) => i.key === 'good_production_kg')?.value).toBe('—');
        expect(items.find((i) => i.key === 'batches')?.value).toBe('0');
        expect(items.find((i) => i.key === 'skipped_nulls')?.value).toBe('none');
    });

    it('is empty for a missing sums block', () => {
        expect(cecSumsItems(undefined)).toEqual([]);
        expect(cecSumsItems(null)).toEqual([]);
    });
});

describe('cecSummaryItems — the Shift Summary figures the CEC carries, verbatim', () => {
    it('lists the summary’s own keys as sent, with a null percentage as "—"', () => {
        const items = cecSummaryItems(morningSummary);
        expect(items).toEqual([
            { key: 'target_production_kg', label: 'Target (kg)', value: '300.0000' },
            { key: 'actual_production_kg', label: 'Actual (kg)', value: '225.9600' },
            { key: 'rejection_kg', label: 'Rejection (kg)', value: '9.8529' },
            { key: 'net_good_output_kg', label: 'Net good (kg)', value: '216.1071' },
            { key: 'efficiency_percent', label: 'Efficiency (vs supervisor target)', value: '75.32%' },
            { key: 'rejection_percent', label: 'Rejection %', value: '4.36%' },
            { key: 'power_consumption_units', label: 'Power (units)', value: '120.0000' },
            { key: 'unit_per_kg', label: 'Unit / kg', value: '0.531' },
            { key: 'idle_time_hours', label: 'Idle — machines (h)', value: '0.5000' },
            { key: 'power_interruption_hours', label: 'Idle — power cuts (h)', value: '0.5000' },
            { key: 'no_of_mold_changes', label: 'Mold changes', value: '0' },
        ]);
    });

    it('labels efficiency plainly when the server sends no basis, and "—" when there is no target', () => {
        const items = cecSummaryItems({ ...morningSummary, efficiency_basis: undefined, efficiency_percent: null, target_production_kg: null });
        expect(items.find((i) => i.key === 'efficiency_percent')).toEqual({ key: 'efficiency_percent', label: 'Efficiency', value: '—' });
        expect(items.find((i) => i.key === 'target_production_kg')?.value).toBe('—');
    });

    it('is empty for a missing summary', () => {
        expect(cecSummaryItems(undefined)).toEqual([]);
    });
});
