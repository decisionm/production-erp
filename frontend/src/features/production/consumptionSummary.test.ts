import { describe, expect, it } from 'vitest';
import { consumptionSummary } from './consumptionSummary';

describe('consumptionSummary (DEC-20260902-022)', () => {
    it('lists expected, actual, variance and unaccounted as figures', () => {
        expect(consumptionSummary({
            norm_source: 'bom', expected_kg: '100.0000', actual_kg: '104.5000', variance_kg: '4.5000', variance_pct: 4.5,
            rejection_kg: '0', scrap_kg: '1.0000', unaccounted_kg: '3.5000',
        })).toEqual([
            { label: 'Expected kg', value: '100.0000' },
            { label: 'Actual kg', value: '104.5000' },
            { label: 'Variance', value: '+4.5000 kg (4.5%)' },
            { label: 'Unaccounted kg', value: '3.5000' },
        ]);
    });

    it('shows the kilograms alone when the norm resolves to zero (variance_pct is null, variance_kg is not)', () => {
        expect(consumptionSummary({
            norm_source: 'item_weight', expected_kg: '0.0000', actual_kg: '4.5000', variance_kg: '4.5000', variance_pct: null,
            rejection_kg: '0', scrap_kg: '0', unaccounted_kg: '4.5000',
        })).toEqual([
            { label: 'Expected kg', value: '0.0000' },
            { label: 'Actual kg', value: '4.5000' },
            { label: 'Variance', value: '+4.5000 kg' },
            { label: 'Unaccounted kg', value: '4.5000' },
        ]);
    });

    it('shows a dash where no norm exists, never a zero', () => {
        expect(consumptionSummary({
            norm_source: null, expected_kg: null, actual_kg: '12.0000', variance_kg: null, variance_pct: null,
            rejection_kg: '0', scrap_kg: '0', unaccounted_kg: null,
        })).toEqual([
            { label: 'Expected kg', value: '—' },
            { label: 'Actual kg', value: '12.0000' },
            { label: 'Variance', value: '—' },
            { label: 'Unaccounted kg', value: '—' },
        ]);
    });

    it('returns the four dashes for a batch not yet completed', () => {
        expect(consumptionSummary(null).map((r) => r.value)).toEqual(['—', '—', '—', '—']);
    });
});
