import { describe, expect, it } from 'vitest';
import { EFFICIENCY_CEILING_PCT, completedTodayRow, completedTodayRows, configMissingTooltip, efficiencyBandFor } from './completedToday';
import type { ShiftProductionEntry } from './types';

/**
 * A completed entry as the list resource sends it — only the keys the row
 * mapper reads are meaningful here; the cast keeps the fixture honest about
 * being a partial wire shape rather than a hand-built domain object.
 */
const entry = (overrides: Partial<ShiftProductionEntry> = {}): ShiftProductionEntry =>
    ({
        id: 41,
        batch_number: '20260817-M03-002',
        production_date: '2026-08-17',
        batch_status: 'completed',
        status: 'pending',
        shift: { id: 1, name: 'Day', start_time: '06:00', end_time: '14:00', is_active: true },
        work_center: { id: 3, code: 'MC-03', name: 'Machine 3' },
        item: { id: 9, sku: '500ML KIDNEY', name: '500ml Kidney Bottle', uom: 'Nos' },
        finished_item: null,
        quantity_produced: '4800',
        gross_quantity_produced: null,
        quantity_scrap: '120',
        quantity_produced_kg: '61.9200',
        metrics: {
            expected_pieces: '5121.95',
            expected_boxes: 10,
            expected_pouches: null,
            actual_boxes: 10,
            actual_pouches: null,
            actual_pieces: '4800',
            efficiency_pct: 93.7,
            efficiency_band: 'watch',
            rejection_kg_production: '1.5480',
            rejection_kg_qc: null,
            rejection_diff_kg: null,
            lumps_kg: '0',
            issued_kg: '65.0000',
            good_production_kg: '61.9200',
            confirmed_rejection_kg: '1.5480',
            reconciliation_unaccounted_kg: '1.5320',
        },
        quality: {
            checked: false,
            stage_enabled: true,
            reviewed_nos: null,
            ok_nos: null,
            rejected_nos: null,
            checked_at: null,
            note: null,
            gross_quantity_produced: null,
            net_quantity_produced: '4800',
            rejection_kg: null,
            rejection_kg_basis: null,
            scrap_note: null,
        },
        tally: null,
        ...overrides,
    }) as unknown as ShiftProductionEntry;

describe('completedTodayRow — every figure is the resource\'s own, never recomputed', () => {
    it('reads machine, shift, SKU and the batch off the relations as sent', () => {
        const row = completedTodayRow(entry());

        expect(row.id).toBe(41);
        expect(row.key).toBe(41);
        expect(row.batchNumber).toBe('20260817-M03-002');
        expect(row.machine).toBe('Machine 3');
        expect(row.shift).toBe('Day');
        expect(row.sku).toBe('500ML KIDNEY');
        // The product name rides as secondary text; itemLabel's rule (no
        // "X — X" duplication) is honoured by delegating to it.
        expect(row.product).toBe('500ML KIDNEY — 500ml Kidney Bottle');
        // THE UNIT EVERY FIGURE ON THIS ROW IS IN, off the item master rather
        // than assumed. The table prints it once per row ("quantities in Nos"),
        // and it is what the mixed-unit branch on the Shift Floor redirects the
        // supervisor to when the day's batches cannot share one total.
        expect(row.uom).toBe('Nos');
    });

    it('leaves the unit null rather than guessing when the item master carries none', () => {
        // A product with no UOM cannot start a batch (ProductReadinessService
        // fails the `uom` check), so a blank here means unconfigured — the
        // table then prints no unit line at all rather than inventing one.
        const noUom = completedTodayRow(entry({ item: { id: 9, sku: 'X', name: 'X', uom: null } as never }));
        expect(noUom.uom).toBeNull();

        const noItem = completedTodayRow(entry({ item: undefined as unknown as ShiftProductionEntry['item'] }));
        expect(noItem.uom).toBeNull();
    });

    it('names the batch by id when it has no number, and never breaks on a missing item', () => {
        const row = completedTodayRow(entry({ batch_number: null, item: undefined as unknown as ShiftProductionEntry['item'] }));

        expect(row.batchNumber).toBe('#41');
        expect(row.sku).toBe('—');
        expect(row.product).toBe('—');
    });

    it('takes expected, actual, good, reject and efficiency straight from metrics/quality/columns', () => {
        const row = completedTodayRow(entry());

        // metrics.expected_pieces "5121.95" — grouped for the eye, not rounded, not recomputed.
        expect(row.expectedPieces).toBe('5,121.95');
        // No quality check yet: the supervisor's count IS the good count.
        expect(row.actualPieces).toBe('4,800');
        expect(row.goodPieces).toBe('4,800');
        expect(row.rejectPieces).toBe('120');
        expect(row.qcRejectedPieces).toBeNull();
        expect(row.efficiencyPct).toBe(93.7);
        expect(row.efficiency).toBe('93.7%');
        expect(row.efficiencyBand).toBe('watch');
    });

    it('after a quality rejection: actual is the gross count, good is the net, and the QC pieces are named', () => {
        const row = completedTodayRow(
            entry({
                quantity_produced: '4700',
                gross_quantity_produced: '4800',
                quality: {
                    ...entry().quality!,
                    checked: true,
                    reviewed_nos: 4800,
                    ok_nos: 4700,
                    rejected_nos: 100,
                    gross_quantity_produced: '4800',
                    net_quantity_produced: '4700',
                },
                metrics: { ...entry().metrics!, actual_pieces: '4700', efficiency_pct: 91.8, efficiency_band: 'watch' },
            }),
        );

        expect(row.actualPieces).toBe('4,800');
        expect(row.goodPieces).toBe('4,700');
        expect(row.rejectPieces).toBe('120');
        expect(row.qcRejectedPieces).toBe(100);
        expect(row.efficiency).toBe('91.8%');
    });

    it('prints a dash, never 0 or NaN, for a figure the server did not send', () => {
        const row = completedTodayRow(entry({ metrics: null, quantity_produced: null, quantity_scrap: '0' }));

        expect(row.expectedPieces).toBe('—');
        expect(row.actualPieces).toBe('—');
        expect(row.goodPieces).toBe('—');
        expect(row.rejectPieces).toBe('0');
        expect(row.efficiencyPct).toBeNull();
        expect(row.efficiency).toBe('—');
        expect(row.efficiencyBand).toBeNull();
    });

    it('carries the approval status and, when a voucher exists, the Tally state verbatim', () => {
        const noVoucher = completedTodayRow(entry({ status: 'pending', tally: null }));
        expect(noVoucher.approval).toBe('pending');
        expect(noVoucher.tally).toBeNull();

        const synced = completedTodayRow(
            entry({
                status: 'synced',
                tally: {
                    entry_id: 77,
                    voucher_type: 'Stock Journal',
                    status: 'synced',
                    voucher_number: 'SJ-20260817-S1',
                    synced_at: '2026-08-17T10:00:00+00:00',
                    flags: {},
                    link: '/tally-sync?entry=77',
                },
            }),
        );
        expect(synced.approval).toBe('synced');
        expect(synced.tally).toEqual({ status: 'synced', voucherNumber: 'SJ-20260817-S1', link: '/tally-sync?entry=77' });
    });

    it('a backend that predates the tally key reads as "no voucher", not as an error', () => {
        const row = completedTodayRow(entry({ tally: undefined }));
        expect(row.tally).toBeNull();
    });

    it('flags an incomplete configuration from the batch\'s own configuration_gaps snapshot, and nothing else', () => {
        const complete = completedTodayRow(entry({ configuration_gaps: { complete: true, missing: [], source: 'live' } }));
        expect(complete.configIncomplete).toBe(false);
        expect(complete.configMissing).toEqual([]);

        const incomplete = completedTodayRow(
            entry({ configuration_gaps: { complete: false, missing: ['counts', 'tally_identity'], source: 'snapshot' } }),
        );
        expect(incomplete.configIncomplete).toBe(true);
        expect(incomplete.configMissing).toEqual(['counts', 'tally_identity']);

        // Absent (older backend) or null: not incomplete — the tag says
        // "known to be missing something", never "unknown".
        expect(completedTodayRow(entry()).configIncomplete).toBe(false);
        expect(completedTodayRow(entry({ configuration_gaps: null })).configIncomplete).toBe(false);
    });

    it('names the Tally identity when the run posts as a packaging item different from the product', () => {
        const row = completedTodayRow(entry({ finished_item: { id: 12, name: '500ML KIDNEY TRAY' } }));
        expect(row.finishedItemName).toBe('500ML KIDNEY TRAY');

        const same = completedTodayRow(entry({ finished_item: { id: 9, name: '500ml Kidney Bottle' } }));
        expect(same.finishedItemName).toBeNull();
    });
});

describe('completedTodayRows', () => {
    it('keeps the server\'s order and maps every row', () => {
        const rows = completedTodayRows([entry({ id: 3 }), entry({ id: 1 }), entry({ id: 2 })]);
        expect(rows.map((r) => r.id)).toEqual([3, 1, 2]);
    });

    it('is empty for nothing, never undefined', () => {
        expect(completedTodayRows([])).toEqual([]);
        expect(completedTodayRows(undefined)).toEqual([]);
    });
});

describe('efficiencyBandFor — the approval screen\'s rule, one place', () => {
    it('trusts the server band, but a figure above 100 is over_standard whatever the band says', () => {
        expect(efficiencyBandFor(93.7, 'watch')).toBe('watch');
        expect(efficiencyBandFor(98, 'ok')).toBe('ok');
        expect(efficiencyBandFor(107, 'ok')).toBe('over_standard');
        // Dead-on 100 is the standard met, not beaten.
        expect(efficiencyBandFor(100, 'ok')).toBe('ok');
    });

    it('falls back to the fixed thresholds only when the server sent no band', () => {
        expect(efficiencyBandFor(96, undefined)).toBe('ok');
        expect(efficiencyBandFor(90, null)).toBe('watch');
        expect(efficiencyBandFor(70, undefined)).toBe('investigate');
        expect(efficiencyBandFor(null, undefined)).toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Phase 5.5 fix loop
// ---------------------------------------------------------------------------

describe('efficiencyBandFor — the ceiling is the backend\'s efficiency_over, not a hard-coded 100', () => {
    it('101% is "ok" under a 102% ceiling, over_standard under the default', () => {
        expect(efficiencyBandFor(101, 'ok', 102)).toBe('ok');
        expect(efficiencyBandFor(101, 'ok')).toBe('over_standard');
        expect(efficiencyBandFor(101, 'ok', EFFICIENCY_CEILING_PCT)).toBe('over_standard');
    });

    it('a dead-on ceiling is met, not beaten, whatever the ceiling is', () => {
        expect(efficiencyBandFor(102, 'ok', 102)).toBe('ok');
        expect(efficiencyBandFor(102.1, 'ok', 102)).toBe('over_standard');
    });

    it('the row mapper carries the ceiling through to the band', () => {
        const hot = entry({ metrics: { ...entry().metrics!, efficiency_pct: 101, efficiency_band: 'ok' } });
        expect(completedTodayRow(hot).efficiencyBand).toBe('over_standard');
        expect(completedTodayRow(hot, 102).efficiencyBand).toBe('ok');
        expect(completedTodayRows([hot], 102)[0].efficiencyBand).toBe('ok');
        expect(completedTodayRows([hot])[0].efficiencyBand).toBe('over_standard');
    });
});

describe('configMissingTooltip — the vocabulary in words, the raw keys kept on the row', () => {
    it('words the keys through the one vocabulary', () => {
        const row = completedTodayRow(entry({ configuration_gaps: { complete: false, missing: ['counts', 'tally_identity'], source: 'snapshot' } }));
        expect(row.configMissing).toEqual(['counts', 'tally_identity']);
        expect(configMissingTooltip(row)).toBe('Missing: counts and Tally identity');

        const one = completedTodayRow(entry({ configuration_gaps: { complete: false, missing: ['cycle_time'], source: 'live' } }));
        expect(configMissingTooltip(one)).toBe('Missing: cycle time');

        const three = completedTodayRow(entry({ configuration_gaps: { complete: false, missing: ['cavities', 'unit_weight', 'counts'], source: 'snapshot' } }));
        expect(configMissingTooltip(three)).toBe('Missing: cavities, unit weight and counts');
    });

    it('says nothing for a complete run, or an incomplete one that sent no words', () => {
        expect(configMissingTooltip(completedTodayRow(entry({ configuration_gaps: { complete: true, missing: [], source: 'snapshot' } })))).toBeNull();
        expect(configMissingTooltip(completedTodayRow(entry({ configuration_gaps: { complete: false, missing: [], source: 'snapshot' } })))).toBeNull();
        expect(configMissingTooltip(completedTodayRow(entry()))).toBeNull();
    });

    it('an unknown server key reads readably rather than raw', () => {
        const row = completedTodayRow(entry({ configuration_gaps: { complete: false, missing: ['mould_code'], source: 'snapshot' } }));
        expect(configMissingTooltip(row)).toBe('Missing: mould code');
    });
});
