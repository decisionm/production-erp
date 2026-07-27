import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Packing suggestion rounding, shared by every screen that converts a piece
 * count into tray/pouch/box counts ("suggestions" and "standard:" notes).
 * The authoritative value is backend config `production.packing_rounding`
 * (env PROD_PACKING_ROUNDING), served by /production/settings — frontend
 * and backend must never disagree about a suggestion.
 */
export type PackingRounding = 'ceil' | 'round' | 'floor';

/** Fallback while settings load (and the pre-config behaviour). */
export const PACKING_ROUNDING: PackingRounding = 'ceil';

export interface ProductionSettings {
    packing_rounding: PackingRounding;
    tolerances: Record<string, number | null>;
}

export function useProductionSettings(): ProductionSettings | undefined {
    const { data } = useQuery({
        queryKey: ['production', 'settings'],
        queryFn: async () => {
            const { data: response } = await api.get<{ data: ProductionSettings }>('/production/settings');
            return response.data;
        },
        staleTime: 5 * 60 * 1000,
    });

    return data;
}

/** Round a packing figure (pieces ÷ per-unit standard) per the configured mode. */
export function roundPer(value: number, mode: PackingRounding = PACKING_ROUNDING): number {
    if (mode === 'round') return Math.round(value);
    if (mode === 'floor') return Math.floor(value);
    return Math.ceil(value);
}
