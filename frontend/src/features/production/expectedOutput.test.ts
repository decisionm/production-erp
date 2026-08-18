import { describe, expect, it } from 'vitest';
import { VERSION_UNIFIED, cyclesFloor, expectedOutput, netRunningHours } from './expectedOutput';

describe('expectedOutput (the client mirror of the versioned server formula)', () => {
    // CT 13.5 s, 5 cavities, 10 h: 2666.67 cycles → v3 floors to 2666 → 13,330;
    // the unfloored legacy formula reads 13,333.33 — the pair WS-A's
    // CompletionDowntimeTest pin moved between.
    it('floors cycles before cavities multiply under production_v3_unified', () => {
        expect(expectedOutput(VERSION_UNIFIED, 13.5, 5, 10, 490, null)?.pieces).toBe(13330);
    });

    it('keeps the unfloored legacy number for an entry stamped before v3', () => {
        expect(expectedOutput('production_v2_floor', 13.5, 5, 10, 490, null)?.pieces).toBe(13333.33);
        expect(expectedOutput(null, 13.5, 5, 10, 490, null)?.pieces).toBe(13333.33);
    });

    it('agrees with itself when the division is exact', () => {
        expect(expectedOutput(VERSION_UNIFIED, 12, 4, 10, null, null)?.pieces).toBe(12000);
        expect(expectedOutput('production_v2_floor', 12, 4, 10, null, null)?.pieces).toBe(12000);
    });

    it('rounds boxes half-up and pouches per the packing setting, on the versioned pieces', () => {
        expect(expectedOutput(VERSION_UNIFIED, 13.5, 5, 10, 490, 96, 'ceil')).toEqual({ pieces: 13330, boxes: 27, pouches: 139 });
    });

    it('is quiet when an input is missing or zero', () => {
        expect(expectedOutput(VERSION_UNIFIED, 0, 5, 10, null, null)).toBeNull();
        expect(expectedOutput(VERSION_UNIFIED, 13.5, null, 10, null, null)).toBeNull();
        expect(expectedOutput(VERSION_UNIFIED, 13.5, 5, 0, null, null)).toBeNull();
    });
});

// ---------------------------------------------------------------------------
// Phase 5.5 fix loop — the mirror must be EXACT where the server is exact.
// The server floors bcdiv(seconds, ct, 0) on decimal strings; a float
// division here read 3999 cycles where the server stored 4000 (CT 10.8 ×
// 12 h: 43200 / 10.8 is exactly 4000, and IEEE 10.8 is a hair above it).
// ---------------------------------------------------------------------------

describe('cyclesFloor — integer-scaled, so an exact quotient is exact (the server figure, not a float neighbour)', () => {
    it.each([
        // CT 10.8: 6 h → 21600/10.8 = 2000; 12 h → 4000; 8 h → 2666.67 → 2666.
        [10.8, 6, 2000],
        [10.8, 12, 4000],
        [10.8, 8, 2666],
        // CT 2.7 and 5.4 — the same binary-inexact family.
        [2.7, 1, 1333],
        [2.7, 3, 4000],
        [2.7, 8, 10666],
        [5.4, 3, 2000],
        [5.4, 6, 4000],
        [5.4, 8, 5333],
        // The 13.5 / 10 h case, kept.
        [13.5, 10, 2666],
        // Exact division.
        [12, 8, 2400],
    ])('CT %s s over %s h → %s cycles', (ct, hours, cycles) => {
        expect(cyclesFloor(hours, ct)).toBe(cycles);
    });

    it('is quiet for a zero or negative input', () => {
        expect(cyclesFloor(0, 10.8)).toBe(0);
        expect(cyclesFloor(8, 0)).toBeNull();
        expect(cyclesFloor(-1, 10.8)).toBeNull();
    });
});

describe('expectedOutput under production_v3_unified reads the server figure at the exact quotients', () => {
    it.each([
        // CT 10.8, 5 cavities: 6 h → 10,000; 12 h → 20,000; 8 h → 2666 × 5 = 13,330 (the owner batch).
        [10.8, 5, 6, 10000],
        [10.8, 5, 12, 20000],
        [10.8, 5, 8, 13330],
        [2.7, 5, 3, 20000],
        [5.4, 5, 6, 20000],
        [13.5, 5, 10, 13330],
    ])('CT %s, %s cav, %s h → %s pieces', (ct, cavities, hours, pieces) => {
        expect(expectedOutput(VERSION_UNIFIED, ct, cavities, hours, null, null)?.pieces).toBe(pieces);
    });

    it('leaves the legacy branch exactly as it was', () => {
        expect(expectedOutput('production_v2_floor', 10.8, 5, 12, null, null)?.pieces).toBe(20000);
        expect(expectedOutput('production_v2_floor', 10.8, 5, 8, null, null)?.pieces).toBe(13333.33);
        expect(expectedOutput(null, 13.5, 5, 10, null, null)?.pieces).toBe(13333.33);
        expect(expectedOutput('legacy_v1', 12, 4, 10, null, null)?.pieces).toBe(12000);
    });
});

describe('netRunningHours — mirrors the server: downtime hours TRUNCATED to 6 dp, then subtracted, floored at zero', () => {
    it('one minute of downtime off 8 h at CT 12 leaves 2395 cycles — the server figure, not 2394', () => {
        // Server: bcdiv('1', '60', 6) = 0.016666 (truncated) → 8 − 0.016666 =
        // 7.983334 h → 28740.0024 s / 12 = 2395.0002 → 2395. A float
        // 8 − 1/60 = 7.98333… → 28740 / 12 lands a hair under 2395.
        const hours = netRunningHours(8, 1);
        expect(hours).toBe(7.983334);
        expect(cyclesFloor(hours!, 12)).toBe(2395);
        expect(expectedOutput(VERSION_UNIFIED, 12, 5, hours, null, null)?.pieces).toBe(11975);
    });

    it('truncates rather than rounds (0.016666, never 0.016667), and floors at zero', () => {
        expect(netRunningHours(1, 1)).toBe(0.983334);
        expect(netRunningHours(8, 30)).toBe(7.5);
        expect(netRunningHours(1, 90)).toBe(0);
        expect(netRunningHours(8, 0)).toBe(8);
    });

    it('is null without gross hours', () => {
        expect(netRunningHours(null, 30)).toBeNull();
        expect(netRunningHours(undefined, 30)).toBeNull();
    });
});

describe('netRunningHours + cyclesFloor together — a case where the float mirror lost a shot the server kept', () => {
    it('CT 10 s, 10 h gross, 63 min down: 8.95 h → 3222 cycles (float 8.949999… read 3221)', () => {
        const hours = netRunningHours(10, 63);
        expect(hours).toBe(8.95);
        expect(cyclesFloor(hours!, 10)).toBe(3222);
        expect(expectedOutput(VERSION_UNIFIED, 10, 4, hours, null, null)?.pieces).toBe(12888);
    });
});
