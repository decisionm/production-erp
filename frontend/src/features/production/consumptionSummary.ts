import type { ConsumptionVariance } from './types';

const DASH = '—';

function signed(kg: string): string {
    return kg.startsWith('-') ? kg : `+${kg}`;
}

/**
 * DEC-20260902-022: the four consumption figures a signer must see before
 * signing. Figures only; the server never refuses on them. Strings pass
 * through untouched — no float arithmetic on a kilogram.
 */
export function consumptionSummary(v: ConsumptionVariance | null | undefined): { label: string; value: string }[] {
    if (!v) {
        return [
            { label: 'Expected kg', value: DASH },
            { label: 'Actual kg', value: DASH },
            { label: 'Variance', value: DASH },
            { label: 'Unaccounted kg', value: DASH },
        ];
    }
    const variance = v.variance_kg !== null && v.variance_pct !== null ? `${signed(v.variance_kg)} kg (${v.variance_pct}%)` : DASH;
    return [
        { label: 'Expected kg', value: v.expected_kg ?? DASH },
        { label: 'Actual kg', value: v.actual_kg },
        { label: 'Variance', value: variance },
        { label: 'Unaccounted kg', value: v.unaccounted_kg ?? DASH },
    ];
}
