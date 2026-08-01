import type { ReactElement, ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Input, Modal, Segmented, Space, Steps, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/features/auth/store';
import {
    accountantApproveShiftProductionEntry,
    getTraceabilityReport,
    getVoucherPreview,
    listMachineDowntimeLogs,
    listShiftProductionEntries,
    pmApproveShiftProductionEntry,
    rejectShiftProductionEntry,
} from '@/features/production/api';
import type {
    ConsumptionVariance,
    MachineDowntimeLog,
    ProductionMetrics,
    ReadableStockShortfall,
    ShiftProductionEntry,
    ShiftProductionEntryStatus,
    TraceabilityReportRow,
    VoucherPreview,
} from '@/features/production/types';
import { readStockShortfalls } from '@/features/production/types';
import { type PackingRounding, roundPer, useProductionSettings } from '@/features/production/packing';
import { itemLabel } from '@/lib/itemLabel';

const statusColor: Record<ShiftProductionEntryStatus, string> = {
    pending: 'processing',
    pm_approved: 'cyan',
    accountant_approved: 'geekblue',
    approved: 'success',
    rejected: 'error',
    synced: 'success',
    failed: 'error',
};

const statusLabel: Record<ShiftProductionEntryStatus, string> = {
    pending: 'Awaiting Plant Manager',
    pm_approved: 'Awaiting Accountant',
    // The accountant is the final approver, so nothing is ever written with
    // this status any more. It stays mapped only so a historical row that
    // still carries it renders as a legacy state rather than a blank tag —
    // "Awaiting MD" would name a desk that no longer has a queue and send
    // someone looking for an approval button that does not exist.
    accountant_approved: 'Approved (legacy stage)',
    approved: 'Approved — syncing',
    rejected: 'Rejected',
    synced: 'Synced to Tally',
    failed: 'Sync failed',
};

/** "20.0000" → "20", "1.50" → "1.5", up to 2 decimals. "—" for null/unparseable. */
const fmtKg = (v: string | null | undefined): string => {
    if (v === null || v === undefined) return '—';
    const n = parseFloat(v);
    if (Number.isNaN(n)) return '—';
    return String(parseFloat(n.toFixed(2)));
};

/** Same as fmtKg but with an explicit "+" on positive values (for variances). */
const fmtSignedKg = (v: string | null | undefined): string => {
    const s = fmtKg(v);
    return s !== '—' && parseFloat(s) > 0 ? `+${s}` : s;
};

/**
 * "standard: 50 trays · 120 pouches · 9 boxes" — expected packing from the
 * item's packing master (produced / nos-per-tray|pouch|box, rounded per the
 * shared packing-rounding mode) for eyeballing the entered counts. Null when
 * nothing is computable (no standards on the item, or no produced quantity),
 * in which case the row renders exactly as before.
 */
const packingStandardNote = (row: ShiftProductionEntry, mode?: PackingRounding): string | null => {
    const produced = row.quantity_produced === null ? NaN : parseFloat(row.quantity_produced);
    if (!Number.isFinite(produced) || produced <= 0) return null;
    const parts: string[] = [];
    const item = row.item;
    if (item?.nos_per_tray && item.nos_per_tray >= 1) parts.push(`${roundPer(produced / item.nos_per_tray, mode)} trays`);
    if (item?.nos_per_pouch && item.nos_per_pouch >= 1) parts.push(`${roundPer(produced / item.nos_per_pouch, mode)} pouches`);
    if (item?.nos_per_box && item.nos_per_box >= 1) parts.push(`${roundPer(produced / item.nos_per_box, mode)} boxes`);
    return parts.length > 0 ? `standard: ${parts.join(' · ')}` : null;
};

// Bands are ruled server-side from config/production.php tolerances — the
// UI only colour-maps them. Client thresholds remain solely as a fallback
// for rows cached before bands existed.
const BAND_TAG: Record<string, ReactElement> = {
    ok: <Tag color="green">OK</Tag>,
    watch: <Tag color="orange">Watch</Tag>,
    investigate: <Tag color="red">Investigate</Tag>,
    // Deliberately not a grade — the run beat a standard it cannot beat, so
    // either the entry or the standard is wrong and someone must look before
    // signing. Red, like Investigate, because that is what it asks for.
    over_standard: <Tag color="red">Over 100% — check entry or standard</Tag>,
};

// BAND_TAG is keyed by `string`, so an unmapped band would type-check happily
// and render `undefined`. `?? null` keeps a band this build has never heard of
// from silently blanking a tag that used to be there.
const bandTag = (band: string) => BAND_TAG[band] ?? null;

const varianceTag = (pct: number | null, band?: 'ok' | 'watch' | 'investigate' | null) => {
    if (band) return bandTag(band);
    if (pct === null) return null;
    const abs = Math.abs(pct);
    if (abs <= 2) return BAND_TAG.ok;
    if (abs <= 5) return BAND_TAG.watch;
    return BAND_TAG.investigate;
};

/**
 * Efficiency is actual pieces ÷ what the standard cycle time says the machine
 * could have made, so 100% is a ceiling, not a target to beat. Anything above
 * it means a number is wrong — the produced count, the hours, the cavities, or
 * a standard cycle time set slower than the machine really runs.
 *
 * Fallback only: the live threshold is backend `production.tolerances`
 * .efficiency_over, served by /production/settings, so a deployment that later
 * allows a small measurement margin doesn't leave this screen shouting at runs
 * the backend calls fine. 100 is what it defaults to, and what is used while
 * settings load or against a backend too old to send it.
 */
const EFFICIENCY_CEILING_PCT = 100;

/**
 * The PERCENTAGE decides "over standard", before the band: 107% satisfies
 * efficiency_ok (95), so a band-first read paints it green "OK" — which is
 * exactly how an impossible figure reached the approvers unquestioned, and
 * still would for any entry banded by a backend older than this rule.
 * Compared with `>`, not `>=`, mirroring the backend: a dead-on 100.0 is the
 * standard being met, not beaten.
 */
const efficiencyTag = (pct: number | null, band?: ProductionMetrics['efficiency_band'], ceiling = EFFICIENCY_CEILING_PCT) => {
    if (pct !== null && pct > ceiling) return BAND_TAG.over_standard;
    if (band) return bandTag(band);
    if (pct === null) return null;
    if (pct >= 95) return BAND_TAG.ok;
    if (pct >= 85) return BAND_TAG.watch;
    return BAND_TAG.investigate;
};

/**
 * Expected-vs-actual material use for a completed batch — the block the Plant
 * Manager and Accountant scan to decide whether consumption needs questioning
 * before it posts to Tally. `unaccounted_kg` is the number to investigate.
 */
function VarianceSection({ variance }: { variance: ConsumptionVariance }) {
    if (variance.norm_source === null) {
        return (
            <>
                <Typography.Title level={5} style={{ marginTop: 16 }}>Material Usage vs Norm</Typography.Title>
                <Descriptions column={1} size="small" bordered>
                    <Descriptions.Item label="Actual consumed">{fmtKg(variance.actual_kg)} Kg</Descriptions.Item>
                </Descriptions>
                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
                    No norm set for this product
                </Typography.Text>
            </>
        );
    }

    const unaccounted = variance.unaccounted_kg === null ? null : parseFloat(variance.unaccounted_kg);
    const unaccountedHot = unaccounted !== null && !Number.isNaN(unaccounted) && Math.abs(unaccounted) > 0.5;

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Material Usage vs Norm</Typography.Title>
            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Expected (per norm)">
                    {variance.expected_kg === null ? '—' : `${fmtKg(variance.expected_kg)} Kg`}
                </Descriptions.Item>
                <Descriptions.Item label="Actual consumed">{fmtKg(variance.actual_kg)} Kg</Descriptions.Item>
                <Descriptions.Item label="Variance">
                    {variance.variance_kg === null ? (
                        '—'
                    ) : (
                        <Space size={4} wrap>
                            <span>
                                {fmtSignedKg(variance.variance_kg)} Kg
                                {variance.variance_pct !== null
                                    ? ` (${variance.variance_pct > 0 ? '+' : ''}${variance.variance_pct}%)`
                                    : ''}
                            </span>
                            {varianceTag(variance.variance_pct, variance.variance_band)}
                        </Space>
                    )}
                </Descriptions.Item>
            </Descriptions>
            {variance.unaccounted_kg !== null && (
                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
                    of which rejection {fmtKg(variance.rejection_kg)} kg · scrap {fmtKg(variance.scrap_kg)} kg ·{' '}
                    <Typography.Text type={unaccountedHot ? 'danger' : 'secondary'} strong={unaccountedHot}>
                        unaccounted {fmtSignedKg(variance.unaccounted_kg)} kg
                    </Typography.Text>
                </Typography.Text>
            )}
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>
                norm: {variance.norm_source === 'bom' ? 'BOM' : 'product weight'}
            </Typography.Text>
        </>
    );
}

/**
 * A figure that could not be computed. The approver's next question is
 * always "why is this blank" — so the section stays visible and names the
 * input that is missing, instead of quietly disappearing.
 */
function MissingInput({ what, inputs }: { what: string; inputs: string[] }) {
    return (
        <Alert
            type="warning"
            showIcon
            style={{ marginTop: 8 }}
            message={`${what} cannot be calculated`}
            description={
                <>
                    <Typography.Text type="secondary">Missing:</Typography.Text>
                    <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                        {inputs.map((input) => (
                            <li key={input}>{input}</li>
                        ))}
                    </ul>
                </>
            }
        />
    );
}

/** Which expected-output inputs this entry is short of (empty = computable). */
function missingExpectedInputs(row: ShiftProductionEntry): string[] {
    const missing: string[] = [];
    const ct = row.standard_cycle_time === null ? NaN : parseFloat(row.standard_cycle_time);
    if (!Number.isFinite(ct) || ct <= 0) missing.push('standard cycle time — snapshotted from the item master at Start Batch');
    if (!(row.active_cavities ?? row.standard_cavities)) missing.push('active cavities — recorded at Start Batch');
    const hours = row.running_hours === null ? NaN : parseFloat(row.running_hours);
    if (!Number.isFinite(hours) || hours <= 0) missing.push('running hours — entered by the supervisor at Complete Batch');
    return missing;
}

/** pieces × nominal weight ÷ 1000, or null when the item carries no weight. */
function piecesToKg(pieces: string | number | null, nominalWeightGrams: string | null | undefined): number | null {
    const p = typeof pieces === 'number' ? pieces : pieces === null ? NaN : parseFloat(pieces);
    const g = nominalWeightGrams === null || nominalWeightGrams === undefined ? NaN : parseFloat(nominalWeightGrams);
    if (!Number.isFinite(p) || !Number.isFinite(g) || g <= 0) return null;
    return (p * g) / 1000;
}

/**
 * The CONTENT of one expected/actual row — deliberately not a component
 * wrapping Descriptions.Item: antd reads its children's own props, so a
 * custom element in that position is not reliably rendered.
 */
function comparison(expected: ReactNode, actual: ReactNode, note?: string): ReactNode {
    return (
        <>
            <Space size={16} wrap>
                <span>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>expected </Typography.Text>
                    {expected}
                </span>
                <span>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>actual </Typography.Text>
                    <Typography.Text strong>{actual}</Typography.Text>
                </span>
            </Space>
            {note && (
                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                    {note}
                </Typography.Text>
            )}
        </>
    );
}

/**
 * Expected vs actual, side by side: pieces, cartons and kilograms, plus the
 * efficiency, rejection and lump figures the Plant Manager and Accountant
 * decide on. Where a figure is missing this says which input is absent
 * rather than hiding the row.
 */
function ExpectedVsActualSection({ row, metrics }: { row: ShiftProductionEntry; metrics: ProductionMetrics | null }) {
    // Same cached query the page already uses — the over-100% threshold is
    // deployment config, so it is read, never assumed.
    const efficiencyCeiling = useProductionSettings()?.tolerances?.efficiency_over ?? EFFICIENCY_CEILING_PCT;
    const missing = missingExpectedInputs(row);
    const weight = row.item?.nominal_weight_grams;
    const expectedKg = metrics ? piecesToKg(metrics.expected_pieces, weight) : null;
    const actualKg = row.quantity_produced_kg === null ? null : parseFloat(row.quantity_produced_kg);

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Expected vs Actual</Typography.Title>
            {metrics?.blocks_approval && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="Blocks approval"
                    description="Unaccounted material is at/over the configured blocking tolerance — the accountant cannot post this entry until it is corrected or rejected back to the floor."
                />
            )}
            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Pieces">
                    {comparison(
                        metrics?.expected_pieces != null ? `${fmtKg(metrics.expected_pieces)} pcs` : '—',
                        metrics?.actual_pieces != null ? `${fmtKg(metrics.actual_pieces)} pcs` : `${row.quantity_produced ?? '—'} pcs`,
                    )}
                </Descriptions.Item>
                <Descriptions.Item label="Cartons">
                    {comparison(
                        metrics?.expected_boxes != null ? `${metrics.expected_boxes} boxes` : '—',
                        metrics?.actual_boxes != null ? `${metrics.actual_boxes} boxes` : (row.no_of_box ?? '—'),
                        metrics?.expected_boxes == null && !row.item?.nos_per_box
                            ? 'expected cartons need pieces-per-box on the product standard'
                            : undefined,
                    )}
                </Descriptions.Item>
                {(metrics?.expected_pouches != null || metrics?.actual_pouches != null) && (
                    <Descriptions.Item label="Pouches">
                        {comparison(
                            metrics?.expected_pouches != null ? `${metrics.expected_pouches}` : '—',
                            metrics?.actual_pouches != null ? `${metrics.actual_pouches}` : '—',
                        )}
                    </Descriptions.Item>
                )}
                <Descriptions.Item label="Kilograms">
                    {comparison(
                        expectedKg === null ? '—' : `${expectedKg.toFixed(2)} kg`,
                        actualKg === null ? '—' : `${actualKg.toFixed(2)} kg`,
                        expectedKg === null && !weight
                            ? 'kg needs a nominal weight (grams) on the item master'
                            : 'pieces × nominal weight ÷ 1000',
                    )}
                </Descriptions.Item>
                <Descriptions.Item label="Efficiency">
                    {metrics?.efficiency_pct !== null && metrics !== null ? (
                        <>
                            <Space size={6}>
                                {`${metrics.efficiency_pct}%`}
                                {efficiencyTag(metrics.efficiency_pct, metrics.efficiency_band, efficiencyCeiling)}
                            </Space>
                            {/* The tag says something is wrong; this says what
                                to do about it, on the screen where it is signed
                                off. Never blocks approval — the approver may
                                well decide the run really was that fast and the
                                standard is what needs correcting. */}
                            {metrics.efficiency_pct > efficiencyCeiling && (
                                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>
                                    A machine cannot beat its own standard. Check the produced count, hours and cavities; if
                                    they are right, the standard cycle time is set too slow and should be corrected on Product
                                    Standards.
                                </Typography.Text>
                            )}
                        </>
                    ) : (
                        // Pieces, not cartons: the ratio moved to the piece
                        // grain (loose pieces were being thrown away).
                        <Typography.Text type="secondary">— actual pieces ÷ expected pieces</Typography.Text>
                    )}
                </Descriptions.Item>
                <Descriptions.Item label="Rejection">
                    {metrics === null || (metrics.rejection_kg_production === null && metrics.rejection_kg_qc === null) ? (
                        <Typography.Text type="secondary">none recorded</Typography.Text>
                    ) : (
                        <>
                            production {fmtKg(metrics.rejection_kg_production)} kg · QC {fmtKg(metrics.rejection_kg_qc)} kg
                            {metrics.rejection_diff_kg !== null ? ` · diff ${fmtSignedKg(metrics.rejection_diff_kg)} kg` : ''}
                        </>
                    )}
                </Descriptions.Item>
                <Descriptions.Item label="Lumps">{metrics ? `${fmtKg(metrics.lumps_kg)} kg` : '—'}</Descriptions.Item>
            </Descriptions>
            {missing.length > 0 && <MissingInput what="Expected output" inputs={missing} />}
        </>
    );
}

/**
 * Material expected vs actual and the unaccounted figure — the number the
 * accountant investigates before it posts.
 */
function MaterialVarianceSection({ metrics }: { metrics: ProductionMetrics | null }) {
    if (metrics === null) return null;
    const unaccounted = metrics.reconciliation_unaccounted_kg === null ? null : parseFloat(metrics.reconciliation_unaccounted_kg);
    const hot = metrics.unaccounted_band
        ? metrics.unaccounted_band === 'investigate'
        : unaccounted !== null && !Number.isNaN(unaccounted) && Math.abs(unaccounted) > 0.5;

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Material Reconciliation</Typography.Title>
            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Issued / Good / Lumps">
                    {fmtKg(metrics.issued_kg)} / {fmtKg(metrics.good_production_kg)} / {fmtKg(metrics.lumps_kg)} kg
                </Descriptions.Item>
                <Descriptions.Item label="Unaccounted">
                    {metrics.reconciliation_unaccounted_kg === null ? (
                        <Typography.Text type="secondary">
                            — needs both issued material lines and a produced quantity in kg
                        </Typography.Text>
                    ) : (
                        <>
                            <Typography.Text type={hot ? 'danger' : undefined} strong={hot}>
                                {fmtSignedKg(metrics.reconciliation_unaccounted_kg)} kg
                            </Typography.Text>
                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                issued − good − rejection (QC wins) − lumps
                            </Typography.Text>
                        </>
                    )}
                </Descriptions.Item>
            </Descriptions>
        </>
    );
}

/**
 * The batch issued more of a material than the ledger recorded, so the balance
 * went negative — and the completion was ALLOWED, because the shift genuinely
 * consumed the resin whether or not the computer knew any had arrived.
 *
 * This is where that fact is repaid. The floor is not asked to reconcile stock
 * mid-shift; the accountant, signing the money, is told exactly which material,
 * from which bin, by how much — and what to do about it.
 *
 * Deliberately NOT a gate. `blocks_approval` is the only thing that refuses an
 * approval and it stays that way: refusing here would push the argument back to
 * the supervisor, which is the failure this whole change exists to undo.
 */
function StockShortfallSection({ shortfalls }: { shortfalls: ReadableStockShortfall[] }) {
    if (shortfalls.length === 0) return null;

    // Red, matching the row tag that brought the approver here. The one thing
    // red usually means on this screen — "you cannot sign this" — is denied in
    // as many words at the bottom of the block, because the loudness is the
    // point: this is the moment, and the desk, where the stock gets corrected.
    return (
        <Alert
            type="error"
            showIcon
            style={{ marginBottom: 16 }}
            message="This batch consumed more than the recorded stock:"
            description={
                <>
                    {/* ONE number, the one the server actually computes: the gap.
                        The backend records short_kg (issued minus the balance it
                        found under the decrement's own row lock) and nothing
                        else, so printing a "requested / available" pair here
                        would put two dashes on the screen the incident exists to
                        make readable. The gap is also the figure the accountant
                        has to make good, which is what this block is asking for. */}
                    <ul style={{ margin: '4px 0 8px', paddingLeft: 18 }}>
                        {shortfalls.map((line) => (
                            <li key={line.key}>
                                <Typography.Text strong>{line.shortKg ?? '—'} kg</Typography.Text> of {line.item} came
                                out of {line.warehouse} that the stock record did not have
                            </li>
                        ))}
                    </ul>
                    <Typography.Text>
                        The material was really used — receive it against a purchase, or enter its opening stock, on the{' '}
                        <Link to="/production/day-bin">Factory Day Bin</Link> page.
                    </Typography.Text>
                    <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>
                        This does not stop approval — the batch can be signed as it stands.
                    </Typography.Text>
                </>
            }
        />
    );
}

/**
 * Breakdowns logged on this machine on this production date. The log list is
 * paginated with no server-side filter, so when the page does not reach back
 * as far as the entry, this says so rather than claiming there were none.
 */
function DowntimeSection({ row, logs, loading }: { row: ShiftProductionEntry; logs: MachineDowntimeLog[] | undefined; loading: boolean }) {
    const mine = (logs ?? []).filter((log) => log.work_center?.id === row.work_center?.id && log.production_date === row.production_date);
    const oldestLoaded = (logs ?? []).reduce<string | null>(
        (oldest, log) => (oldest === null || log.production_date < oldest ? log.production_date : oldest),
        null,
    );
    // The window genuinely may not reach this entry — an old batch with an
    // empty list is "not loaded", not "no breakdowns".
    const outsideWindow = mine.length === 0 && oldestLoaded !== null && row.production_date < oldestLoaded;
    const totalMinutes = mine.reduce((sum, log) => sum + (log.total_minutes === null ? 0 : parseFloat(log.total_minutes)), 0);

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Downtime</Typography.Title>
            {loading && <Typography.Text type="secondary">Loading breakdown logs…</Typography.Text>}
            {!loading && outsideWindow && (
                <MissingInput
                    what="Downtime for this batch"
                    inputs={[`breakdown logs are only loaded back to ${oldestLoaded} — this batch is ${row.production_date}`]}
                />
            )}
            {!loading && !outsideWindow && mine.length === 0 && (
                <Typography.Text type="secondary">
                    No breakdown logged on {row.work_center?.name ?? 'this machine'} on {row.production_date}.
                </Typography.Text>
            )}
            {!loading && mine.length > 0 && (
                <>
                    <Typography.Paragraph style={{ marginBottom: 8 }}>
                        <Typography.Text strong>{mine.length}</Typography.Text> breakdown{mine.length > 1 ? 's' : ''} ·{' '}
                        <Typography.Text strong>{Math.round(totalMinutes)}</Typography.Text> minutes
                        {mine.some((log) => log.total_minutes === null) && (
                            <Typography.Text type="secondary"> (still-open breakdowns count as 0 until closed)</Typography.Text>
                        )}
                    </Typography.Paragraph>
                    <Table
                        size="small"
                        rowKey="id"
                        pagination={false}
                        dataSource={mine}
                        columns={[
                            { title: 'Problem', dataIndex: 'nature_of_problem' },
                            { title: 'From', render: (_, log: MachineDowntimeLog) => dayjs(log.from_time).format('HH:mm') },
                            { title: 'To', render: (_, log: MachineDowntimeLog) => (log.to_time ? dayjs(log.to_time).format('HH:mm') : 'open') },
                            { title: 'Minutes', render: (_, log: MachineDowntimeLog) => log.total_minutes ?? '—' },
                            { title: 'Remedy', render: (_, log: MachineDowntimeLog) => log.remedy ?? '—' },
                        ]}
                    />
                </>
            )}
        </>
    );
}

/**
 * Which supplier lot and which physical bags fed this batch — the answer a
 * customer complaint needs. Derived from the traceability report's
 * lot → bag → fed-segment chain, matched on this entry's id.
 */
function SourceMaterialSection({
    row,
    lots,
    loading,
    windowFrom,
    windowTo,
}: {
    row: ShiftProductionEntry;
    lots: TraceabilityReportRow[] | null | undefined;
    loading: boolean;
    windowFrom: string;
    windowTo: string;
}) {
    // `bags`/`fed` come back only when the report loaded them — a lot row
    // without them is a lot that fed nothing here, never a reason to throw.
    const fedRows = (lots ?? []).flatMap((lot) =>
        (lot.bags ?? [])
            .filter((bag) => (bag.fed ?? []).some((feed) => feed.segment?.id === row.id))
            .map((bag) => ({
                key: `${lot.id}-${bag.id}`,
                lot: lot.supplier_lot_no ?? `Lot #${lot.id}`,
                material: itemLabel(lot.item),
                barcode: bag.barcode,
                loaded_kg: (bag.fed ?? [])
                    .filter((feed) => feed.segment?.id === row.id)
                    .reduce((sum, feed) => sum + parseFloat(feed.loaded_kg), 0),
            })),
    );

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Source Material</Typography.Title>
            {loading && <Typography.Text type="secondary">Loading scanned bags…</Typography.Text>}
            {!loading && lots === null && (
                <MissingInput
                    what="The lot and bag barcodes behind this batch"
                    inputs={['traceability is switched off on this server, so no bag scans are recorded']}
                />
            )}
            {!loading && lots !== null && fedRows.length === 0 && (
                <MissingInput
                    what="The lot and bag barcodes behind this batch"
                    inputs={[
                        `no scanned bag fed this batch from any lot received between ${windowFrom} and ${windowTo}`,
                        'either the bags were not scanned into the day bin, or they came from an older lot than that window',
                    ]}
                />
            )}
            {!loading && fedRows.length > 0 && (
                <Table
                    size="small"
                    rowKey="key"
                    pagination={false}
                    dataSource={fedRows}
                    columns={[
                        { title: 'Material', dataIndex: 'material' },
                        { title: 'Supplier Lot', dataIndex: 'lot' },
                        { title: 'Bag Barcode', dataIndex: 'barcode' },
                        { title: 'Loaded (kg)', render: (_, r) => r.loaded_kg.toFixed(4) },
                    ]}
                />
            )}
        </>
    );
}

/**
 * The voucher Tally WILL receive, resolved against real masters. Strictly a
 * preview: this endpoint is read-only and posts nothing — the accountant's
 * approval is the only thing that may.
 */
function VoucherPreviewSection({ preview, loading }: { preview: VoucherPreview | undefined; loading: boolean }) {
    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Tally Voucher — preview only</Typography.Title>
            {loading && <Typography.Text type="secondary">Resolving the voucher…</Typography.Text>}
            {!loading && preview === undefined && (
                <MissingInput what="The Tally voucher" inputs={['the voucher preview could not be loaded for this entry']} />
            )}
            {!loading && preview !== undefined && (
                <>
                    <Alert
                        type={preview.postable ? 'success' : 'warning'}
                        showIcon
                        style={{ marginBottom: 8 }}
                        message={preview.postable ? 'Resolves cleanly — nothing posted yet' : 'Tally would reject this voucher as it stands'}
                        description={
                            (preview.problems ?? []).length > 0 ? (
                                <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                                    {(preview.problems ?? []).map((problem) => (
                                        <li key={problem}>{problem}</li>
                                    ))}
                                </ul>
                            ) : (
                                'Nothing is sent to Tally until the Accountant approves.'
                            )
                        }
                    />
                    <Table
                        size="small"
                        rowKey={(line) => `${line.side}-${line.item}-${line.godown}`}
                        pagination={false}
                        dataSource={preview.lines ?? []}
                        columns={[
                            { title: 'Side', dataIndex: 'side' },
                            { title: 'Item', render: (_, line) => line.item ?? '—' },
                            { title: 'Qty', render: (_, line) => `${line.quantity ?? '—'} ${line.uom ?? ''}` },
                            { title: 'Godown', render: (_, line) => line.godown ?? '—' },
                            {
                                title: 'Problems',
                                render: (_, line) => ((line.problems ?? []).length === 0 ? '—' : (line.problems ?? []).join('; ')),
                            },
                        ]}
                    />
                </>
            )}
        </>
    );
}

/**
 * The chain: Supervisor submits → Plant Manager verifies → Accountant
 * reconciles and posts → Tally. The accountant is FINAL; there is no MD stage.
 * Each row's available action depends on its stage AND the viewer's role — the
 * stage config drives both the button and the visibility.
 */
const STAGES: {
    status: ShiftProductionEntryStatus;
    action: string;
    roles: string[];
    mutate: (id: number) => Promise<ShiftProductionEntry>;
}[] = [
    { status: 'pending', action: 'Approve (Plant Manager)', roles: ['Plant Manager', 'Administrator'], mutate: pmApproveShiftProductionEntry },
    // The accountant's approval posts to Tally and ends the chain (team
    // decision 2026-07-26). Nothing follows it.
    { status: 'pm_approved', action: 'Approve & Post (Accountant)', roles: ['Accounts', 'Administrator'], mutate: accountantApproveShiftProductionEntry },
];

export default function ApproveProductionPage() {
    const [status, setStatus] = useState<ShiftProductionEntryStatus>('pending');
    const [detailRow, setDetailRow] = useState<ShiftProductionEntry | null>(null);
    const [rejectingRow, setRejectingRow] = useState<ShiftProductionEntry | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const queryClient = useQueryClient();
    const settings = useProductionSettings();
    const user = useAuthStore((s) => s.user);
    const myRoles = user?.roles?.map((r) => r.name) ?? [];

    const stageFor = (s: ShiftProductionEntryStatus) => STAGES.find((st) => st.status === s);
    const canActOn = (row: ShiftProductionEntry) => {
        const stage = stageFor(row.status);
        return stage !== undefined && stage.roles.some((r) => myRoles.includes(r));
    };

    const { data, isLoading } = useQuery({
        queryKey: ['production', 'shift-production-entries', status],
        queryFn: () => listShiftProductionEntries(status),
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'shift-production-entries'] });

    // ------------------------------------------------------------------
    // Detail-drawer context. All three are READ-ONLY endpoints — opening the
    // drawer must never move an entry a step closer to Tally.
    // ------------------------------------------------------------------
    const { data: voucherPreview, isFetching: voucherLoading } = useQuery({
        queryKey: ['production', 'voucher-preview', detailRow?.id],
        queryFn: () => getVoucherPreview(detailRow!.id),
        enabled: detailRow !== null,
    });

    const { data: downtimeLogs, isFetching: downtimeLoading } = useQuery({
        queryKey: ['production', 'machine-downtime-logs'],
        queryFn: listMachineDowntimeLogs,
        enabled: detailRow !== null,
    });

    // The traceability report keys off the LOT's received date, not the
    // batch's, so the window reaches back the report's full 92-day cap —
    // and the section says so when nothing matches.
    const traceFrom = detailRow ? dayjs(detailRow.production_date).subtract(92, 'day').format('YYYY-MM-DD') : '';
    const traceTo = detailRow ? detailRow.production_date : '';
    const { data: traceLots, isFetching: traceLoading } = useQuery({
        queryKey: ['production', 'traceability-report', traceFrom, traceTo],
        queryFn: () => getTraceabilityReport({ date_from: traceFrom, date_to: traceTo }),
        enabled: detailRow !== null,
    });

    const approveMutation = useMutation({
        mutationFn: (row: ShiftProductionEntry) => stageFor(row.status)!.mutate(row.id),
        onSuccess: () => {
            invalidate();
            setDetailRow(null);
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not approve',
                content: error?.response?.data?.message ?? 'This entry may have already been decided — refresh and try again.',
            });
        },
    });

    const rejectMutation = useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) => rejectShiftProductionEntry(id, reason || undefined),
        onSuccess: () => {
            invalidate();
            setRejectingRow(null);
            setRejectReason('');
            setDetailRow(null);
        },
        onError: (error: any) => {
            Modal.error({
                title: 'Could not reject',
                content: error?.response?.data?.message ?? 'This entry may have already been decided — refresh and try again.',
            });
        },
    });

    const chainStep = (row: ShiftProductionEntry): number => {
        if (row.status === 'pending') return 1;
        if (row.status === 'pm_approved') return 2;
        return 3; // approved / synced / failed — accountant done, Tally next/done
    };

    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>Approve Production</Typography.Title>
            <Typography.Paragraph type="secondary">
                Every completed batch passes the chain — Supervisor → Plant Manager → Accountant — and posts
                to Tally the moment the Accountant approves. Rejection at any stage sends it back to the supervisor.
            </Typography.Paragraph>

            <Segmented
                value={status}
                onChange={(v) => setStatus(v as ShiftProductionEntryStatus)}
                options={[
                    { label: 'Plant Manager', value: 'pending' },
                    { label: 'Accountant', value: 'pm_approved' },
                    { label: 'Approved', value: 'approved' },
                    { label: 'Synced', value: 'synced' },
                    { label: 'Failed', value: 'failed' },
                    { label: 'Rejected', value: 'rejected' },
                ]}
                style={{ marginBottom: 16, maxWidth: '100%', overflowX: 'auto' }}
            />

            <Table<ShiftProductionEntry>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                locale={{ emptyText: `Nothing waiting here.` }}
                columns={[
                    { title: 'Date', dataIndex: 'production_date' },
                    { title: 'Shift', render: (_, row) => row.shift?.name ?? '—' },
                    { title: 'Machine', render: (_, row) => row.work_center?.name ?? '—' },
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
                    { title: 'Batch #', dataIndex: 'batch_number', render: (v: string | null) => v ?? '—' },
                    { title: 'Produced', dataIndex: 'quantity_produced' },
                    { title: 'Produced (Kg)', dataIndex: 'quantity_produced_kg', render: (v: string | null) => v ?? '—' },
                    { title: 'Rejected', dataIndex: 'quantity_scrap' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        // The shortfall rides in the status cell rather than in a
                        // column of its own: a column would be blank on almost
                        // every row and would shift the layout for everyone, while
                        // the thing an approver needs is to spot the one row that
                        // needs a second look before opening anything.
                        render: (s: ShiftProductionEntryStatus, row: ShiftProductionEntry) => (
                            <Space size={4} wrap>
                                <Tag color={statusColor[s]}>{statusLabel[s]}</Tag>
                                {readStockShortfalls(row).length > 0 && <Tag color="red">Stock went negative</Tag>}
                            </Space>
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailRow(row)}>View</Button>
                                {canActOn(row) && (
                                    <>
                                        <Button
                                            size="small"
                                            type="primary"
                                            loading={approveMutation.isPending}
                                            onClick={() => approveMutation.mutate(row)}
                                        >
                                            {stageFor(row.status)!.action}
                                        </Button>
                                        <Button size="small" danger onClick={() => setRejectingRow(row)}>
                                            Reject
                                        </Button>
                                    </>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Drawer
                title={`Batch #${detailRow?.id} — ${detailRow?.work_center?.name ?? '—'} · ${detailRow?.item?.sku ?? '—'}`}
                open={detailRow !== null}
                onClose={() => setDetailRow(null)}
                width="min(100vw, 520px)"
                destroyOnHidden
            >
                {detailRow && (
                    <>
                        {detailRow.status === 'failed' && (
                            <Alert
                                type="error"
                                showIcon
                                style={{ marginBottom: 16 }}
                                message="Tally rejected or could not receive this voucher"
                                description={
                                    <>
                                        {detailRow.sync_error ?? 'No error detail recorded.'}
                                        <br />
                                        <Typography.Text type="secondary">
                                            Fix the cause (Tally open with the right company, item names matching),
                                            then retry it from the Tally Sync page — nothing needs re-entry here.
                                        </Typography.Text>
                                    </>
                                }
                            />
                        )}
                        {/* Above the chain, not buried in a metrics section: it
                            is a before-you-sign fact, and it must be readable on
                            a row whose metrics block is null for any other
                            reason. */}
                        <StockShortfallSection shortfalls={readStockShortfalls(detailRow)} />
                        <Steps
                            size="small"
                            current={chainStep(detailRow)}
                            status={detailRow.status === 'rejected' ? 'error' : detailRow.status === 'failed' ? 'error' : 'process'}
                            style={{ marginBottom: 20 }}
                            items={[
                                { title: 'Supervisor' },
                                { title: 'Plant Mgr' },
                                { title: 'Accountant' },
                                { title: 'Tally' },
                            ]}
                        />
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailRow.status]}>{statusLabel[detailRow.status]}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Date">{detailRow.production_date}</Descriptions.Item>
                            <Descriptions.Item label="Shift">{detailRow.shift?.name ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Batch Number">{detailRow.batch_number ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Produced">
                                {detailRow.quantity_produced} Nos{detailRow.quantity_produced_kg ? ` (${detailRow.quantity_produced_kg} Kg)` : ''}
                            </Descriptions.Item>
                            <Descriptions.Item label="Rejected">
                                {detailRow.quantity_scrap} Nos{detailRow.quantity_rejection_kg ? ` (${detailRow.quantity_rejection_kg} Kg)` : ''}
                            </Descriptions.Item>
                            <Descriptions.Item label="Rejection Reason">{detailRow.scrap_reason?.name ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Packing">
                                {detailRow.nos_per_tray ?? '—'}/tray × {detailRow.no_of_trays ?? '—'} trays,{' '}
                                {detailRow.nos_per_box ?? '—'}/box × {detailRow.no_of_box ?? '—'} boxes
                                {/* Pouch/loose figures only exist for pouch-packed items /
                                    Wave A entries — appended so older rows render unchanged. */}
                                {detailRow.no_of_pouches != null &&
                                    `, ${detailRow.item?.nos_per_pouch ?? '—'}/pouch × ${detailRow.no_of_pouches} pouches`}
                                {detailRow.loose_pieces != null && `, ${detailRow.loose_pieces} loose`}
                                {packingStandardNote(detailRow, settings?.packing_rounding) && (
                                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                        {packingStandardNote(detailRow, settings?.packing_rounding)}
                                    </Typography.Text>
                                )}
                            </Descriptions.Item>
                            <Descriptions.Item label="Operator">{detailRow.operator?.name ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailRow.notes ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Plant Manager">
                                {detailRow.plant_manager_signed_by
                                    ? `${detailRow.plant_manager_signed_by.name} · ${detailRow.plant_manager_signed_at?.slice(0, 16).replace('T', ' ') ?? ''}`
                                    : '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Accountant">
                                {detailRow.accountant_signed_by
                                    ? `${detailRow.accountant_signed_by.name} · ${detailRow.accountant_signed_at?.slice(0, 16).replace('T', ' ') ?? ''}`
                                    : '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Final Approval">
                                {detailRow.approved_by ? detailRow.approved_by.name : '—'}
                            </Descriptions.Item>
                            {detailRow.rejection_reason && (
                                <Descriptions.Item label="Rejected Because">{detailRow.rejection_reason}</Descriptions.Item>
                            )}
                        </Descriptions>

                        {/* Expected vs actual first: it is the question both
                            approvers are actually answering. The sections
                            below explain any gap it shows. */}
                        <ExpectedVsActualSection row={detailRow} metrics={detailRow.metrics} />

                        <DowntimeSection row={detailRow} logs={downtimeLogs?.data} loading={downtimeLoading} />

                        <MaterialVarianceSection metrics={detailRow.metrics} />

                        {detailRow.variance && <VarianceSection variance={detailRow.variance} />}

                        {(detailRow.material_consumptions ?? []).length > 0 && (
                            <>
                                <Typography.Title level={5} style={{ marginTop: 16 }}>Material Consumption</Typography.Title>
                                <Table
                                    size="small"
                                    rowKey="id"
                                    pagination={false}
                                    dataSource={detailRow.material_consumptions ?? []}
                                    columns={[
                                        { title: 'Item', render: (_, row) => itemLabel(row.item) },
                                        // The line's own source warehouse. A cell that
                                        // cannot name it must read "—": this dereference
                                        // was unguarded while the backend only ever sent
                                        // the key when the relation happened to be eager-
                                        // loaded, so the first batch completed with a
                                        // day-bin material line blanked the whole drawer.
                                        { title: 'From', render: (_, row) => row.warehouse?.code ?? '—' },
                                        { title: 'Kg', dataIndex: 'quantity_issued_kg' },
                                    ]}
                                />
                            </>
                        )}

                        {(detailRow.scraps ?? []).length > 0 && (
                            <>
                                <Typography.Title level={5} style={{ marginTop: 16 }}>Scrap Detail</Typography.Title>
                                <Table
                                    size="small"
                                    rowKey="id"
                                    pagination={false}
                                    dataSource={detailRow.scraps ?? []}
                                    columns={[
                                        { title: 'Type', dataIndex: 'type' },
                                        { title: 'Nos', dataIndex: 'quantity_nos', render: (v: string | null) => v ?? '—' },
                                        { title: 'Kg', dataIndex: 'quantity_kg', render: (v: string | null) => v ?? '—' },
                                        { title: 'Reason', render: (_, row) => row.scrap_reason?.name ?? '—' },
                                    ]}
                                />
                            </>
                        )}

                        <SourceMaterialSection
                            row={detailRow}
                            lots={traceLots}
                            loading={traceLoading}
                            windowFrom={traceFrom}
                            windowTo={traceTo}
                        />

                        <VoucherPreviewSection preview={voucherPreview} loading={voucherLoading} />

                        {canActOn(detailRow) && (
                            <Space style={{ marginTop: 24 }}>
                                <Button type="primary" loading={approveMutation.isPending} onClick={() => approveMutation.mutate(detailRow)}>
                                    {stageFor(detailRow.status)!.action}
                                </Button>
                                <Button danger onClick={() => setRejectingRow(detailRow)}>Reject</Button>
                            </Space>
                        )}
                    </>
                )}
            </Drawer>

            <Modal
                maskClosable={false}
                title={`Reject Batch #${rejectingRow?.id}`}
                open={rejectingRow !== null}
                onCancel={() => {
                    setRejectingRow(null);
                    setRejectReason('');
                }}
                onOk={() => rejectingRow && rejectMutation.mutate({ id: rejectingRow.id, reason: rejectReason })}
                confirmLoading={rejectMutation.isPending}
                okText="Reject"
                okButtonProps={{ danger: true }}
                destroyOnHidden
            >
                <Input.TextArea
                    rows={3}
                    placeholder="Reason (optional) — helps the supervisor fix and resubmit"
                    value={rejectReason}
                    onChange={(e) => setRejectReason(e.target.value)}
                />
            </Modal>
        </>
    );
}
