import { itemLabel } from '@/lib/itemLabel';
import type { TraceabilityReportRow } from './types';

/**
 * Pure helpers for the Production Reports page (Phase 7, WS-C): the
 * Traceability tab's filter object and its cache key, the lot picker's
 * options, and the efficiency column's honesty label. Nothing here reads
 * the network or invents a figure; vitest pins each without rendering.
 */

/**
 * The Traceability tab's filters as the server reads them
 * (TraceabilityReportRequest: date_from/date_to required, lot_id/item_id
 * optional). ONE object, built in ONE place, is what the query key, the
 * report request and the CSV export all read — the tab can never show one
 * lot and download another. Absent filters are absent keys, not undefined
 * values, so what reaches the wire is exactly what is chosen.
 */
export type TraceabilityFilters = {
    date_from: string;
    date_to: string;
    lot_id?: number;
    item_id?: number;
};

export function traceabilityFilters(
    range: [string, string],
    lotId: number | undefined,
    itemId: number | undefined,
): TraceabilityFilters {
    return {
        date_from: range[0],
        date_to: range[1],
        ...(lotId !== undefined ? { lot_id: lotId } : {}),
        ...(itemId !== undefined ? { item_id: itemId } : {}),
    };
}

/** The TanStack key for one filter set — every filter named, 'all' where none is chosen. */
export function traceabilityQueryKey(filters: TraceabilityFilters): (string | number)[] {
    return ['production', 'reports', 'traceability', filters.date_from, filters.date_to, filters.lot_id ?? 'all', filters.item_id ?? 'all'];
}

export interface LotFilterOption {
    value: number;
    label: string;
}

/**
 * The lot picker's options come from the REPORT'S OWN ROWS: a lot the
 * server did not list for this window cannot narrow it to anything, so
 * offering one would only produce an empty table. Labelled the way the
 * table names a lot — supplier lot number (or the id when the receipt
 * carried none), the material, the received date.
 */
export function lotFilterOptions(rows: TraceabilityReportRow[] | null | undefined): LotFilterOption[] {
    return (rows ?? []).map((lot) => ({
        value: lot.id,
        label: `${lot.supplier_lot_no ?? `Lot #${lot.id}`} · ${itemLabel(lot.item)} · ${lot.received_date ?? '—'}`,
    }));
}

/**
 * What the report's efficiency divides by, said beside the figure — the
 * same honesty rule Shift Summary applies (Phase 5.7, `efficiency_basis`).
 *
 * The production report's `efficiency_pct` is Σ actual pieces ÷ Σ expected
 * pieces (ProductionReportService, dictionary row 24), and expected pieces
 * comes from the STANDARD cycle time snapshotted at Start Batch, the active
 * cavities and the running hours net of the downtime logged at completion
 * (ShiftProductionEntryService::productionMetrics, through the entry's own
 * calculation_version formula) — the product standard,
 * not the supervisor-typed target the Shift Summary measures against. That
 * is the report's own documented definition, so it is what a payload with
 * no `efficiency_basis` reads as. A basis the server DOES name is read
 * verbatim ('supervisor_target' → "vs supervisor target"; any other name →
 * its words), never second-guessed here.
 */
export function efficiencyBasisLabel(basis: string | null | undefined): string {
    if (basis === null || basis === undefined || basis.trim() === '') return 'vs standard';

    return `vs ${basis.replace(/_/g, ' ')}`;
}

/** The Efficiency column heading: the grain named once ("pcs"), the basis beside it. */
export function efficiencyColumnTitle(basis: string | null | undefined): string {
    return `Efficiency (pcs, ${efficiencyBasisLabel(basis)})`;
}
