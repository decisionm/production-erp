import { describe, expect, it } from 'vitest';
import { VERSION_UNIFIED, expectedOutput } from './expectedOutput';

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
