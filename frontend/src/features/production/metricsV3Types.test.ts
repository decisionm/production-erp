import { describe, expect, it } from 'vitest';
import { VERSION_UNIFIED } from './expectedOutput';
import type { BatchEstimation, CalculationVersion, ProductionMetrics } from './types';

/**
 * The v3 keys on the two engine payloads, PINNED AT THE TYPE LEVEL (Phase 7,
 * WS-C). The four names below are what ShiftProductionEntryService::
 * productionMetrics and BatchEstimationService::estimate emit
 * (calculation_version · downtime_netted · expected_pieces_gross ·
 * downtime_impact_pieces). `satisfies` on an object literal is a compile
 * error on an excess or mistyped key, so renaming or dropping one of them
 * in types.ts turns `npm run typecheck` red here before any screen reads
 * the wrong name — the runtime assertions are the same fact stated for
 * vitest.
 */
const completedMetrics = {
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
    lumps_kg: '0.0000',
    issued_kg: '65.0000',
    good_production_kg: '61.9200',
    confirmed_rejection_kg: '1.5480',
    reconciliation_unaccounted_kg: '1.5320',
    // The v3 block — the engine's stamp, the netting flag, and the two
    // targets() figures the legacy formulas never had (null there, never
    // recomputed).
    calculation_version: 'production_v3_unified',
    downtime_netted: true,
    expected_pieces_gross: 5333,
    downtime_impact_pieces: 211,
} satisfies ProductionMetrics;

const legacyMetrics = {
    ...completedMetrics,
    calculation_version: 'legacy_v1',
    expected_pieces_gross: null,
    downtime_impact_pieces: null,
} satisfies ProductionMetrics;

const preview = {
    planned_hours: '8.00',
    standard_cycle_time: '10.80',
    standard_cavities: 2,
    active_cavities: 2,
    expected_cycles: 2666,
    expected_pieces: 5332,
    expected_kg: '68.7828',
    nos_per_tray: null,
    nos_per_box: 490,
    nos_per_pouch: null,
    expected_trays: null,
    expected_boxes: 11,
    expected_pouches: null,
    expected_materials: [],
    recipe_source: null,
    // The preview is stamped with the version a batch started from it will
    // carry, and says it has netted no downtime (it cannot know any yet).
    calculation_version: VERSION_UNIFIED,
    downtime_netted: false,
} satisfies BatchEstimation;

describe('v3 engine keys on ProductionMetrics / BatchEstimation', () => {
    it('ProductionMetrics names calculation_version, downtime_netted, expected_pieces_gross, downtime_impact_pieces', () => {
        expect(completedMetrics.calculation_version).toBe('production_v3_unified');
        expect(completedMetrics.downtime_netted).toBe(true);
        expect(completedMetrics.expected_pieces_gross).toBe(5333);
        expect(completedMetrics.downtime_impact_pieces).toBe(211);
    });

    it('a legacy entry carries null for the two v3-only figures — never a number computed after the fact', () => {
        expect(legacyMetrics.expected_pieces_gross).toBeNull();
        expect(legacyMetrics.downtime_impact_pieces).toBeNull();
    });

    it('BatchEstimation names calculation_version and downtime_netted (false before the run)', () => {
        expect(preview.calculation_version).toBe('production_v3_unified');
        expect(preview.downtime_netted).toBe(false);
    });

    it('the stamp names are the engine\'s three, and the client mirror\'s VERSION_UNIFIED is one of them', () => {
        const stamps: CalculationVersion[] = ['legacy_v1', 'production_v2_floor', 'production_v3_unified'];
        expect(stamps).toContain(VERSION_UNIFIED);
    });
});
