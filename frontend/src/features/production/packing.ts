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
    /**
     * Phase 6 lot/barcode traceability master switch — backend config
     * `production.traceability_enabled` (env PROD_TRACEABILITY, default true).
     * Optional: older backends don't send it. Treat anything but `true` as off;
     * with it off the entire traceability UI must be invisible and inert.
     */
    traceability_enabled?: boolean;
    /**
     * Which warehouse IS the factory day bin (app_settings, set on the Day Bin
     * (factory) page — not deploy config). null/absent = not chosen yet, in
     * which case every screen behaves exactly as it did before the day bin
     * existed. Screens that also need the bin's BALANCES read
     * GET /production/factory-day-bin instead, which carries this id too.
     */
    day_bin_warehouse_id?: number | null;
    /**
     * The factory's machine rule, published by the backend so a screen can
     * STATE which machines a product runs on: a mould below
     * `cavity_threshold` cavities runs on any machine, at or above it only on
     * `restricted_machines`.
     *
     * Published rather than expanded into product-machine rows on purpose. The
     * rule answers every product from the cavity count a screen already has,
     * whereas rows would need approving one by one and would then outrank the
     * workbook they were copied from the moment a figure was corrected there.
     *
     * Optional — an older backend does not send it, and a deployment that has
     * not named its high-cavity machines sends an empty list. In both cases a
     * screen must say nothing rather than guess a machine name.
     */
    machine_capability?: {
        cavity_threshold: number;
        restricted_machines: { id: number; name: string }[];
    } | null;
}

export function useProductionSettings(): ProductionSettings | undefined {
    const { data } = useQuery({
        queryKey: ['production', 'settings'],
        queryFn: async (): Promise<ProductionSettings | null> => {
            try {
                const { data: response } = await api.get<{ data: ProductionSettings }>('/production/settings');
                return response.data;
            } catch (error: any) {
                // A user without the production module (e.g. procurement-only)
                // gets a 403 here — that is a normal answer ("no settings for
                // you"), not an error to surface or retry. Cached as null so
                // the screen renders its no-settings fallback quietly.
                if (error?.response?.status === 403) return null;
                throw error;
            }
        },
        // Settings are deployment config — a failure is not transient, and
        // every consumer of this hook mounts often; retries would only spam.
        retry: false,
        staleTime: 5 * 60 * 1000,
    });

    return data ?? undefined;
}

/** Round a packing figure (pieces ÷ per-unit standard) per the configured mode. */
export function roundPer(value: number, mode: PackingRounding = PACKING_ROUNDING): number {
    if (mode === 'round') return Math.round(value);
    if (mode === 'floor') return Math.floor(value);
    return Math.ceil(value);
}
