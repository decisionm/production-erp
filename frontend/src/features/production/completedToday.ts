import type { TallySyncStatus } from '@/features/tally-sync/types';
import { itemLabel } from '@/lib/itemLabel';
import type { ProductionMetrics, ShiftProductionEntry, ShiftProductionEntryStatus } from './types';

/**
 * Completed Today's row, read off the entries list resource (Phase 5.5,
 * WS-C) — machine · shift · SKU · expected · actual · good · reject ·
 * efficiency · approval/Tally state.
 *
 * EVERY FIGURE IS THE SERVER'S. `expected` is metrics.expected_pieces,
 * `efficiency` is metrics.efficiency_pct, `good` is quantity_produced (net of
 * any quality rejection — the same figure metrics.actual_pieces and
 * quality.net_quantity_produced carry), `actual` is the supervisor's count
 * before the gate (gross_quantity_produced, which is only set once quality
 * has checked; until then the net IS the count). Nothing here multiplies,
 * divides or subtracts a factory quantity: this module groups digits for
 * the eye and picks which sent figure goes in which column, and that is all
 * — the estimation formula lives in ONE place on the server, versioned per
 * entry, and a second copy here would be the divergence Phase 5.5 closed.
 *
 * Pure, so the mapping is pinned by vitest without rendering the 8,600-line
 * page it is extracted from.
 */
export interface CompletedTodayRow {
    key: number;
    id: number;
    /** batch_number, or "#id" for a batch that never got one. */
    batchNumber: string;
    machine: string;
    shift: string;
    /** The product's SKU as the item master carries it; "—" when unknown. */
    sku: string;
    /** itemLabel(item) — the product named without "X — X" duplication. */
    product: string;
    /**
     * The Tally identity this batch's finished goods post as, when it is a
     * different item from the product (a packaging-level item, Phase 5);
     * null when it is the product itself or none was frozen.
     */
    finishedItemName: string | null;
    /** metrics.expected_pieces, grouped; "—" until the batch has metrics. */
    expectedPieces: string;
    /** The supervisor's count (gross before quality, else the produced count); "—" when none. */
    actualPieces: string;
    /** quantity_produced — net of quality's rejection; "—" when none. */
    goodPieces: string;
    /** quantity_scrap — the pieces rejected at completion. */
    rejectPieces: string;
    /** quality.rejected_nos once quality has checked; null before. */
    qcRejectedPieces: number | null;
    /** metrics.efficiency_pct as sent (can exceed 100 — the ratio is honest). */
    efficiencyPct: number | null;
    /** "93.7%" or "—". */
    efficiency: string;
    efficiencyBand: EfficiencyBand | null;
    approval: ShiftProductionEntryStatus;
    /** The voucher that carries this batch, or null when none exists yet. */
    tally: CompletedTodayTallyState | null;
    /** configuration_gaps.complete === false — the run started without something the configuration should hold. */
    configIncomplete: boolean;
    /** configuration_gaps.missing, verbatim vocabulary ("counts", "tally_identity", …). */
    configMissing: string[];
}

export interface CompletedTodayTallyState {
    status: TallySyncStatus;
    voucherNumber: string | null;
    /** "/tally-sync?entry={id}" — the deep link the Tally Sync page honours. */
    link: string;
}

export type EfficiencyBand = NonNullable<ProductionMetrics['efficiency_band']>;

/** 100% is a ceiling, not a target — anything above it means a number is wrong. */
const EFFICIENCY_CEILING_PCT = 100;

/**
 * The band to colour an efficiency by — the approval screen's rule, kept
 * identical here so the floor and the approvers read the same colour for
 * the same figure. The PERCENTAGE decides "over standard" before the band
 * (a 107% banded "ok" by an older backend must still be red); the server's
 * band is trusted otherwise; the fixed thresholds are only for a backend
 * that sent no band at all. Compared with `>`, mirroring the backend: a
 * dead-on 100.0 is the standard met, not beaten.
 */
export function efficiencyBandFor(pct: number | null, band: ProductionMetrics['efficiency_band'] | undefined): EfficiencyBand | null {
    if (pct !== null && pct > EFFICIENCY_CEILING_PCT) return 'over_standard';
    if (band) return band;
    if (pct === null) return null;
    if (pct >= 95) return 'ok';
    if (pct >= 85) return 'watch';
    return 'investigate';
}

/**
 * A decimal string as the server sends it ("5121.95", "4800"), grouped with
 * Indian separators for the eye and NOT rounded — the digits are the
 * server's; "—" for nothing. Never NaN, never 0 for null.
 */
export function pieces(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const n = typeof value === 'number' ? value : parseFloat(value);
    if (Number.isNaN(n)) return '—';
    return n.toLocaleString('en-IN', { maximumFractionDigits: 4 });
}

/**
 * The batch's `configuration_gaps` (Phase 5.5, WS-B) read defensively: a
 * backend that predates the key sends nothing, and nothing is "not known to
 * be incomplete", never "incomplete".
 */
type ConfigurationGaps = { complete?: boolean | null; missing?: string[] | null } | null | undefined;

const configurationGapsOf = (entry: ShiftProductionEntry): ConfigurationGaps =>
    (entry as ShiftProductionEntry & { configuration_gaps?: ConfigurationGaps }).configuration_gaps;

export function completedTodayRow(entry: ShiftProductionEntry): CompletedTodayRow {
    const item = entry.item ?? null;
    const sku = (item?.sku ?? '').trim();
    const finished = entry.finished_item ?? null;
    const finishedName = (finished?.name ?? '').trim();
    const productName = (item?.name ?? '').trim();
    const metrics = entry.metrics ?? null;
    const quality = entry.quality ?? null;
    const gaps = configurationGapsOf(entry);
    const tally = entry.tally ?? null;
    const efficiencyPct = metrics?.efficiency_pct ?? null;

    return {
        key: entry.id,
        id: entry.id,
        batchNumber: entry.batch_number ?? `#${entry.id}`,
        machine: entry.work_center?.name ?? '—',
        shift: entry.shift?.name ?? '—',
        sku: sku === '' ? '—' : sku,
        product: itemLabel(item),
        finishedItemName:
            finished !== null && finishedName !== '' && finishedName.toLowerCase() !== productName.toLowerCase()
                ? finishedName
                : null,
        expectedPieces: pieces(metrics?.expected_pieces),
        actualPieces: pieces(entry.gross_quantity_produced ?? quality?.gross_quantity_produced ?? entry.quantity_produced),
        goodPieces: pieces(entry.quantity_produced),
        rejectPieces: pieces(entry.quantity_scrap),
        qcRejectedPieces: quality?.checked ? (quality.rejected_nos ?? null) : null,
        efficiencyPct,
        efficiency: efficiencyPct === null ? '—' : `${efficiencyPct}%`,
        efficiencyBand: efficiencyBandFor(efficiencyPct, metrics?.efficiency_band),
        approval: entry.status,
        tally: tally === null ? null : { status: tally.status, voucherNumber: tally.voucher_number, link: tally.link },
        configIncomplete: gaps?.complete === false,
        configMissing: gaps?.complete === false ? [...(gaps.missing ?? [])] : [],
    };
}

/** The whole page, in the server's order (production_date desc, id desc). */
export function completedTodayRows(entries: ShiftProductionEntry[] | null | undefined): CompletedTodayRow[] {
    return (entries ?? []).map(completedTodayRow);
}
