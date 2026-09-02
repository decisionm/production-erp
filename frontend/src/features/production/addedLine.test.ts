import { describe, expect, it } from 'vitest';
import { addedLineWarning } from './addedLine';

describe('addedLineWarning (DEC-20260902-019)', () => {
    it('flags an unclassified item', () => {
        expect(addedLineWarning(null)).toBe('Unclassified');
        expect(addedLineWarning(undefined)).toBe('Unclassified');
    });
    it('names Other as spare, tooling or consumable', () => {
        expect(addedLineWarning('other')).toBe('Other: spare, tooling or consumable');
    });
    it('is silent for raw and packing material', () => {
        expect(addedLineWarning('raw_material')).toBeNull();
        expect(addedLineWarning('packing_material')).toBeNull();
    });
});
