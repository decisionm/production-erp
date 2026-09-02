import type { ConsumptionVariance } from './types';

const DASH = '—';

function signed(kg: string): string {
    return kg.startsWith('-') ? kg : `+${kg}`;
}

/**
 * The kilograms are the fact the backend actually computed; the percentage is
 * a derived convenience it can only produce when `expected_kg` is non-zero
 * (division by zero). A norm that resolves to zero still leaves a real
 * `variance_kg` — that must not vanish behind a dash just because there is no
 * percentage to pair it with.
 */
function varianceValue(v: ConsumptionVariance): string {
    if (v.variance_kg === null) return DASH;
    if (v.variance_pct === null) return `${signed(v.variance_kg)} kg`;
    return `${signed(v.variance_kg)} kg (${v.variance_pct}%)`;
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
    return [
        { label: 'Expected kg', value: v.expected_kg ?? DASH },
        { label: 'Actual kg', value: v.actual_kg },
        { label: 'Variance', value: varianceValue(v) },
        { label: 'Unaccounted kg', value: v.unaccounted_kg ?? DASH },
    ];
}
