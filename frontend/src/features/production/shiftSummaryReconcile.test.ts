import { describe, expect, it } from 'vitest';
import { addKg, formatKg4, parseKg4, reconcileShiftSummary, type ShiftSummaryReconcile } from './shiftSummaryReconcile';
import type { ShiftProductionEntry } from './types';

/** The verdict, asserted readable — the null (unreadable) case has its own tests below. */
const verdict = (...args: Parameters<typeof reconcileShiftSummary>): ShiftSummaryReconcile => {
    const result = reconcileShiftSummary(...args);
    expect(result).not.toBeNull();
    return result as ShiftSummaryReconcile;
};

/**
 * A completed entry as the entries index sends it — only the two keys the
 * reconcile reads (batch_status, quantity_produced_kg) are meaningful; the
 * cast keeps the fixture honest about being a partial wire shape.
 */
const entry = (quantityProducedKg: string | null, overrides: Partial<ShiftProductionEntry> = {}): ShiftProductionEntry =>
    ({
        id: 1,
        batch_status: 'completed',
        quantity_produced_kg: quantityProducedKg,
        ...overrides,
    }) as unknown as ShiftProductionEntry;

describe('parseKg4 / formatKg4 — 4-dp fixed-point, no float drift', () => {
    it('reads the server’s 4-dp decimal strings exactly', () => {
        expect(parseKg4('61.9200')).toBe(619200n);
        expect(parseKg4('4120.0000')).toBe(41200000n);
        expect(parseKg4('0.0001')).toBe(1n);
        expect(parseKg4('0')).toBe(0n);
    });

    it('reads a shorter or longer-integer string the way bcadd(…, 4) does', () => {
        expect(parseKg4('5')).toBe(50000n);
        expect(parseKg4('5.1')).toBe(51000n);
        expect(parseKg4('.5')).toBe(5000n);
        expect(parseKg4('123456789.1234')).toBe(1234567891234n);
    });

    it('treats null / undefined / "" as the server does (?? "0")', () => {
        expect(parseKg4(null)).toBe(0n);
        expect(parseKg4(undefined)).toBe(0n);
        expect(parseKg4('')).toBe(0n);
    });

    it('refuses anything that is not a decimal string rather than guessing', () => {
        expect(parseKg4('abc')).toBeNull();
        expect(parseKg4('1,234.5')).toBeNull();
        expect(parseKg4('1.23456')).toBeNull(); // more than 4 dp is not a kg column value
        expect(parseKg4('1e3')).toBeNull();
    });

    it('formats back with exactly four decimals', () => {
        expect(formatKg4(619200n)).toBe('61.9200');
        expect(formatKg4(0n)).toBe('0.0000');
        expect(formatKg4(1n)).toBe('0.0001');
        expect(formatKg4(-15320n)).toBe('-1.5320');
    });
});

describe('addKg — the sum that would drift as floats', () => {
    it('sums 0.1 + 0.2 to 0.3000, not 0.30000000000000004', () => {
        expect(addKg(['0.1000', '0.2000'])).toBe('0.3000');
    });

    it('sums the classic float-drift triple exactly', () => {
        // 1.1 + 2.2 + 3.3 = 6.6000000000000005 in IEEE-754.
        expect(addKg(['1.1000', '2.2000', '3.3000'])).toBe('6.6000');
    });

    it('sums nothing to 0.0000 — the server’s reduce seed', () => {
        expect(addKg([])).toBe('0.0000');
    });
});

describe('reconcileShiftSummary — Σ completed batches vs the summary’s actual_production_kg', () => {
    it('is equal when the sum matches to the last of four decimals', () => {
        const result = verdict('4120.0000', [entry('2000.0000'), entry('1500.5000'), entry('619.5000')]);

        expect(result).toEqual({
            sumKg: '4120.0000',
            summaryKg: '4120.0000',
            batches: 3,
            equal: true,
            difference: '0.0000',
            direction: null,
        });
    });

    it('names the difference and which side is larger when the batches exceed the summary', () => {
        const result = verdict('4100.0000', [entry('2000.0000'), entry('2120.0000')]);

        expect(result.equal).toBe(false);
        expect(result.sumKg).toBe('4120.0000');
        expect(result.summaryKg).toBe('4100.0000');
        expect(result.difference).toBe('20.0000');
        expect(result.direction).toBe('batches_over');
    });

    it('names the difference when the summary exceeds the batches', () => {
        const result = verdict('4120.0000', [entry('2000.0000')]);

        expect(result.equal).toBe(false);
        expect(result.difference).toBe('2120.0000');
        expect(result.direction).toBe('summary_over');
    });

    it('does not drift on decimal sums that floats get wrong', () => {
        // Float: 0.1 + 0.2 !== 0.3 — this reconcile must still say equal.
        const result = verdict('0.3000', [entry('0.1000'), entry('0.2000')]);
        expect(result.equal).toBe(true);
        expect(result.difference).toBe('0.0000');
    });

    it('counts a null kg as 0 exactly as the server’s ?? "0" does', () => {
        const result = verdict('61.9200', [entry('61.9200'), entry(null)]);
        expect(result.equal).toBe(true);
        expect(result.batches).toBe(2);
    });

    it('sums only completed batches, mirroring the server’s where(batch_status, completed)', () => {
        const result = verdict('61.9200', [
            entry('61.9200'),
            entry('999.0000', { batch_status: 'in_progress' }),
            entry('50.0000', { batch_status: 'cancelled' }),
        ]);
        expect(result.equal).toBe(true);
        expect(result.batches).toBe(1);
        expect(result.sumKg).toBe('61.9200');
    });

    it('with no completed batches, an empty summary (0.0000) is equal and any other figure differs', () => {
        expect(verdict('0.0000', []).equal).toBe(true);
        expect(verdict('0.0000', []).batches).toBe(0);
        const differs = verdict('12.0000', []);
        expect(differs.equal).toBe(false);
        expect(differs.difference).toBe('12.0000');
        expect(differs.direction).toBe('summary_over');
    });

    it('normalises the summary figure to 4 dp for display', () => {
        const result = verdict('4120', [entry('4120.0000')]);
        expect(result.summaryKg).toBe('4120.0000');
        expect(result.equal).toBe(true);
    });

    it('returns null rather than a wrong verdict when the summary figure is unreadable', () => {
        expect(reconcileShiftSummary(null, [entry('1.0000')])).toBeNull();
        expect(reconcileShiftSummary(undefined, [entry('1.0000')])).toBeNull();
        expect(reconcileShiftSummary('not-a-number', [entry('1.0000')])).toBeNull();
    });

    it('returns null rather than a wrong verdict when a batch kg is unreadable', () => {
        expect(reconcileShiftSummary('1.0000', [entry('garbage')])).toBeNull();
    });
});
