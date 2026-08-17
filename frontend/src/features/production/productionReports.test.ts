import { describe, expect, it } from 'vitest';
import {
    efficiencyBasisLabel,
    efficiencyColumnTitle,
    lotFilterOptions,
    traceabilityFilters,
    traceabilityQueryKey,
} from './productionReports';
import type { TraceabilityReportRow } from './types';

const lot = (id: number, overrides: Partial<TraceabilityReportRow> = {}): TraceabilityReportRow => ({
    id,
    supplier_lot_no: `SL-${id}`,
    received_date: '2026-08-10',
    item: { id: 30, sku: 'RM-RELPET', name: 'Relpet' },
    bag_count: 40,
    total_received_kg: '1000.0000',
    bags: [],
    ...overrides,
});

describe('traceabilityFilters', () => {
    it('is the range alone when no lot or item is chosen — no undefined keys ride to the server or the export', () => {
        const filters = traceabilityFilters(['2026-08-10', '2026-08-16'], undefined, undefined);
        expect(filters).toEqual({ date_from: '2026-08-10', date_to: '2026-08-16' });
        expect(Object.keys(filters)).toEqual(['date_from', 'date_to']);
    });

    it('carries lot_id and item_id exactly when chosen — the one object the query AND the export read', () => {
        expect(traceabilityFilters(['2026-08-10', '2026-08-16'], 12, 30)).toEqual({
            date_from: '2026-08-10',
            date_to: '2026-08-16',
            lot_id: 12,
            item_id: 30,
        });
        expect(traceabilityFilters(['2026-08-10', '2026-08-16'], undefined, 30)).toEqual({
            date_from: '2026-08-10',
            date_to: '2026-08-16',
            item_id: 30,
        });
    });
});

describe('traceabilityQueryKey', () => {
    it('names every filter, "all" for an absent one, so a lot or item change is a new cache entry', () => {
        expect(traceabilityQueryKey({ date_from: '2026-08-10', date_to: '2026-08-16' })).toEqual([
            'production', 'reports', 'traceability', '2026-08-10', '2026-08-16', 'all', 'all',
        ]);
        expect(traceabilityQueryKey({ date_from: '2026-08-10', date_to: '2026-08-16', lot_id: 12, item_id: 30 })).toEqual([
            'production', 'reports', 'traceability', '2026-08-10', '2026-08-16', 12, 30,
        ]);
    });
});

describe('lotFilterOptions', () => {
    it('one option per lot in the report, labelled by supplier lot number, material and received date', () => {
        expect(lotFilterOptions([lot(12), lot(13, { received_date: '2026-08-12' })])).toEqual([
            { value: 12, label: 'SL-12 · RM-RELPET — Relpet · 2026-08-10' },
            { value: 13, label: 'SL-13 · RM-RELPET — Relpet · 2026-08-12' },
        ]);
    });

    it('a lot with no supplier number is named by its id, never left blank; a missing date reads as a dash', () => {
        expect(lotFilterOptions([lot(14, { supplier_lot_no: null, received_date: null })])).toEqual([
            { value: 14, label: 'Lot #14 · RM-RELPET — Relpet · —' },
        ]);
    });

    it('null / undefined rows (still loading, or the 404 for a deployment without traceability) give no options', () => {
        expect(lotFilterOptions(null)).toEqual([]);
        expect(lotFilterOptions(undefined)).toEqual([]);
    });
});

describe('efficiencyBasisLabel', () => {
    it('reads the basis the server names, the way Shift Summary does', () => {
        expect(efficiencyBasisLabel('supervisor_target')).toBe('vs supervisor target');
    });

    it('with no basis on the payload it reads the report\'s own documented definition — the standard, never a supervisor target', () => {
        expect(efficiencyBasisLabel(null)).toBe('vs standard');
        expect(efficiencyBasisLabel(undefined)).toBe('vs standard');
    });

    it('a basis this build does not know is read verbatim (underscores as spaces) rather than guessed at', () => {
        expect(efficiencyBasisLabel('standard_expected_pieces')).toBe('vs standard expected pieces');
    });
});

describe('efficiencyColumnTitle', () => {
    it('names the grain once and the basis beside it', () => {
        expect(efficiencyColumnTitle(undefined)).toBe('Efficiency (pcs, vs standard)');
        expect(efficiencyColumnTitle('supervisor_target')).toBe('Efficiency (pcs, vs supervisor target)');
    });
});
