import { describe, expect, it } from 'vitest';
import { addedLineWarning } from './addedLine';

describe('addedLineWarning (DEC-20260902-019)', () => {
    it('flags an unclassified item', () => {
        expect(addedLineWarning(null)).toBe('Unclassified');
        expect(addedLineWarning(undefined)).toBe('Unclassified');
    });
    it('names each of the four categories worth a second look', () => {
        expect(addedLineWarning('other')).toBe('Other');
        expect(addedLineWarning('spare_tooling')).toBe('Spare or tooling');
        expect(addedLineWarning('work_in_progress')).toBe('Work in progress');
        expect(addedLineWarning('consumable')).toBe('Consumable');
    });
    it('is silent for raw material, packing material and finished good', () => {
        expect(addedLineWarning('raw_material')).toBeNull();
        expect(addedLineWarning('packing_material')).toBeNull();
        expect(addedLineWarning('finished_good')).toBeNull();
    });
});
