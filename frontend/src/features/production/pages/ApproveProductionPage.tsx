import type { ReactElement, ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Input, Modal, Segmented, Space, Steps, Table, Tag, Timeline, Tooltip, Typography } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { listUsers } from '@/features/access/api';
import { hasModuleAccess } from '@/features/auth/permissions';
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
    BatchCost,
    ConsumptionVariance,
    MachineDowntimeLog,
    ProductionMetrics,
    ReadableStockShortfall,
    ShiftProductionEntry,
    ShiftProductionEntryStatus,
    TraceabilityReportRow,
    VoucherPreview,
} from '@/features/production/types';
import {
    batchCostSourceLabel,
    grossProducedPieces,
    isQualityChecked,
    netProducedPieces,
    readCorrection,
    readQuality,
    readQualityStageEnabled,
    readStockShortfalls,
} from '@/features/production/types';
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
 * The unit to print beside a consumption quantity: the ITEM'S OWN, never a
 * blanket "Kg". The column is stored as `quantity_issued_kg` for historical
 * reasons, but the completion drawer's "Other materials" repeater accepts any
 * item and files the figure in that item's unit — so 500 cartons sat under a
 * heading that read "Kg", and an approver signing off a shift was told half a
 * tonne of cardboard went into a bottle.
 *
 * Input normalization mirrors the backend's isMassUom(): lowercase, trailing
 * dot stripped, because Tally's masters spell it "Kgs." on 90+ live items.
 * Output vocabulary is the completion drawer's ("Kg" / "Nos" / the master's
 * own spelling for anything else, so a metre item still reads "m") — the two
 * screens describe the same lines and must not name their units differently.
 *
 * Blank or absent UOM reads "Kg", matching the server, which counts an
 * unlabelled line toward the kg sums rather than dropping a real resin line.
 */
const unitLabel = (uom: string | null | undefined): string => {
    const raw = (uom ?? '').trim();
    if (raw === '') return 'Kg';
    const norm = raw.toLowerCase().replace(/\.$/, '');
    if (['kg', 'kgs', 'kilogram', 'kilograms'].includes(norm)) return 'Kg';
    if (['no', 'nos', 'pcs', 'pieces', 'numbers'].includes(norm)) return 'Nos';
    return raw;
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

/**
 * The trays this entry packed that are NOT inside its cartons, and what they
 * came to in pieces — the part the "N/box × N boxes" sentence leaves out.
 * 3 cartons + 2 loose trays of a 600/carton, 120/tray product is 1800 + 240,
 * and printing only the 1800 beside "Produced 2040" reads as a 240-piece hole
 * in a count that is in fact correct.
 *
 * Derived, never stored: trays over = no_of_trays − no_of_box × trays/carton,
 * with trays/carton itself divided out of the entry's own pack sizes. Null —
 * and the sentence stays exactly as it was — unless every figure is present
 * AND the answer lands inside one carton's worth. That bound is the guard for
 * a multi-mode run, where no_of_box is the batch's cartons across every mode
 * while no_of_trays belongs to the tray line alone: their difference there is
 * not a tray remainder and must not be printed as one.
 */
const looseTraysOver = (row: ShiftProductionEntry): { trays: number; pieces: number } | null => {
    const perTray = row.nos_per_tray;
    const perBox = row.nos_per_box;
    const trays = row.no_of_trays;
    const boxes = row.no_of_box;
    if (!perTray || perTray < 1 || !perBox || perBox < 1) return null;
    if (trays === null || boxes === null || boxes < 0) return null;
    if (perBox % perTray !== 0) return null;
    const traysPerCarton = perBox / perTray;
    const over = trays - boxes * traysPerCarton;
    if (over <= 0 || over >= traysPerCarton) return null;
    return { trays: over, pieces: over * perTray };
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
 * A rupee figure from a bcmath decimal STRING — never parsed for arithmetic,
 * only for display, and only at the last moment.
 *
 * `dp` because the two kinds of figure on this block need different
 * precision honestly: a batch total in paise (2), and a cost per bottle that
 * is a fraction of a rupee and would round to "₹0.00" at two places (4).
 *
 * NULL IS NOT ZERO here and the caller must never let it become one — every
 * null on this block has a `reason` sentence behind it.
 */
const fmtMoney = (v: string | null | undefined, dp: 2 | 4 = 2): string => {
    if (v === null || v === undefined || v === '') return '—';
    const n = parseFloat(v);
    if (Number.isNaN(n)) return '—';
    return `₹${n.toLocaleString('en-IN', { minimumFractionDigits: dp, maximumFractionDigits: dp })}`;
};

/**
 * WHAT THIS BATCH COST — the block both approvers read before the batch
 * posts, and the only place in the ERP that answers it from the bags the
 * resin actually came out of.
 *
 * ============================ THE HONESTY RULES ============================
 *
 * A NULL FIGURE IS NEVER PRINTED AS A NUMBER. When the server cannot cost
 * the batch in full it sends nulls plus one sentence saying which of the two
 * ways it failed (resin from a bag with no purchase rate, or material issued
 * with no recorded cost). That sentence is shown in place of the figures. A
 * zero, or a bare em dash with nothing beside it, would tell the accountant
 * this batch was free — on the very screen asking them to approve it.
 *
 * EVERY FIGURE NAMES ITS SOURCE, from the server's own `sources` map rather
 * than a sentence written here, so a change in how a number is derived
 * cannot leave a stale explanation sitting under it.
 *
 * THE AS-OF STAMP IS A READ STAMP. The server stamps it when the row is
 * serialized, not when the batch was costed — so it is labelled as when the
 * figures were read, which is what it honestly is.
 *
 * ========================= THE FINANCE BOUNDARY =========================
 *
 * The totals and the per-piece figure are for everyone who can approve a
 * batch. The BREAKDOWN behind them — bag barcodes, supplier lot numbers and
 * the rates paid — is Owner and Accounts territory, and the server already
 * omits `layers`/`other_lines` entirely for anyone else.
 *
 * So the gate here is THE KEY BEING PRESENT, with the caller's finance
 * permission checked alongside it. Presence is the authority: it is the
 * server's own decision arriving with the data, and it cannot go stale
 * against a cached /auth/me. The permission check can only ever make this
 * stricter, never looser — the two disagreeing hides detail from a finance
 * user rather than showing rates to a supervisor.
 *
 * Nothing renders in the detail's place for a non-finance user: no locked
 * panel, no "ask for access" shell. A block that advertises a number it will
 * not show invites someone to go looking for it.
 */
function BatchCostSection({ cost, showsDetail }: { cost: BatchCost; showsDetail: boolean }) {
    const layers = cost.layers ?? [];
    const otherLines = cost.other_lines ?? [];
    // Present AND permitted. See the class note: presence is the server's
    // ruling, the permission is this client honouring the same rule locally.
    const detail = showsDetail && (cost.layers !== undefined || cost.other_lines !== undefined);

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>Batch cost</Typography.Title>

            {/* THE SENTENCE FIRST when there is one — before any figure, so
                nobody reads a partial split as a complete answer. */}
            {cost.reason !== null && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message="This batch is not fully costed"
                    description={cost.reason}
                />
            )}

            <Descriptions column={1} size="small" bordered>
                <Descriptions.Item label="Material cost">
                    <Space direction="vertical" size={0}>
                        <Typography.Text strong>{fmtMoney(cost.material_cost_total)}</Typography.Text>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            resin drawn from the bags, plus every other material this batch consumed
                        </Typography.Text>
                    </Space>
                </Descriptions.Item>
                <Descriptions.Item label="Resin">
                    <Space direction="vertical" size={0}>
                        <Typography.Text>{fmtMoney(cost.resin_cost)}</Typography.Text>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            from {batchCostSourceLabel(cost.sources?.resin_cost)}
                        </Typography.Text>
                    </Space>
                </Descriptions.Item>
                <Descriptions.Item label="Everything else">
                    <Space direction="vertical" size={0}>
                        <Typography.Text>{fmtMoney(cost.other_cost)}</Typography.Text>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            masterbatch, packaging and the rest — from{' '}
                            {batchCostSourceLabel(cost.sources?.other_cost)}
                        </Typography.Text>
                    </Space>
                </Descriptions.Item>
                <Descriptions.Item label="Cost per accepted piece">
                    <Space direction="vertical" size={0}>
                        <Typography.Text strong>{fmtMoney(cost.cost_per_accepted_unit, 4)}</Typography.Text>
                        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            material cost ÷ {batchCostSourceLabel(cost.sources?.cost_per_accepted_unit)}
                            {cost.accepted_quantity !== null
                                ? ` (${fmtKg(cost.accepted_quantity)} pcs)`
                                : ''}
                        </Typography.Text>
                    </Space>
                </Descriptions.Item>
            </Descriptions>

            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8, fontSize: 12 }}>
                Read {dayjs(cost.as_of).format('DD MMM YYYY HH:mm')}
                {/* An amended batch is re-costed from scratch as a new run.
                    Saying so is the difference between "this changed" and
                    "somebody changed something and did not say". */}
                {cost.allocation_run !== null && cost.allocation_run > 1
                    ? ` · recosted after amendment (allocation run ${cost.allocation_run})`
                    : ''}
                . These rates are for reading only: stock is still valued at moving average in the books, and no rate
                here reaches Tally.
            </Typography.Text>

            {detail && layers.length > 0 && (
                <>
                    <Typography.Text strong style={{ display: 'block', marginTop: 16 }}>
                        Which bags the resin came out of
                    </Typography.Text>
                    <Table
                        size="small"
                        rowKey={(row, index) => `${row.day_bin_movement_id ?? 'fallback'}-${index}`}
                        pagination={false}
                        style={{ marginTop: 8 }}
                        dataSource={layers}
                        columns={[
                            { title: 'Material', render: (_, row) => row.item_name ?? '—' },
                            { title: 'Bag', render: (_, row) => row.bag_barcode ?? '—' },
                            { title: 'Supplier lot', render: (_, row) => row.supplier_lot_no ?? '—' },
                            {
                                title: 'Kg drawn',
                                align: 'right',
                                render: (_, row) => fmtKg(row.quantity_kg),
                            },
                            {
                                title: 'Rate / kg',
                                align: 'right',
                                render: (_, row) => fmtMoney(row.rate_per_kg, 4),
                            },
                            { title: 'Amount', align: 'right', render: (_, row) => fmtMoney(row.amount) },
                            // Rendered verbatim: a source this build has never
                            // heard of still belongs on screen. It is exactly
                            // the row worth looking at — the fallback priced at
                            // stock average means a machine burnt more than was
                            // ever scanned into it.
                            { title: 'Rate from', render: (_, row) => row.rate_source ?? '—' },
                        ]}
                    />
                </>
            )}

            {detail && otherLines.length > 0 && (
                <>
                    <Typography.Text strong style={{ display: 'block', marginTop: 16 }}>
                        Everything else, at its issued cost
                    </Typography.Text>
                    <Table
                        size="small"
                        rowKey={(row, index) => `${row.item_id}-${row.warehouse_id}-${index}`}
                        pagination={false}
                        style={{ marginTop: 8 }}
                        dataSource={otherLines}
                        columns={[
                            { title: 'Material', render: (_, row) => row.item_name ?? '—' },
                            {
                                title: 'Qty issued',
                                align: 'right',
                                render: (_, row) => fmtKg(row.quantity_issued_kg),
                            },
                            { title: 'Unit cost', align: 'right', render: (_, row) => fmtMoney(row.unit_cost, 4) },
                            { title: 'Amount', align: 'right', render: (_, row) => fmtMoney(row.cost) },
                        ]}
                    />
                </>
            )}
        </>
    );
}

/**
 * Material use against the NORM for a completed batch — the block the Plant
 * Manager and Accountant scan to decide whether consumption needs questioning
 * before it posts to Tally.
 *
 * This survives the removal of the per-batch unaccounted figure because it
 * asks a different question. It compares consumption against the BOM or the
 * product's standard weight, so it moves when the bottle weight drifts or a
 * supervisor corrects the grams — a real signal. "Unaccounted" was
 * consumption compared against itself.
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
            {/* What the variance above is partly made of. The guard is on the
                two figures actually PRINTED — it used to key on
                unaccounted_kg, which is no longer shown, so the whole line
                would have appeared and vanished on a number off-screen. Both
                fields are always strings ("0" when none), so the test is
                "is there anything to say", not "is it present". */}
            {(parseFloat(variance.rejection_kg) !== 0 || parseFloat(variance.scrap_kg) !== 0) && (
                <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
                    of which rejection {fmtKg(variance.rejection_kg)} kg · scrap {fmtKg(variance.scrap_kg)} kg
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
                    description="This batch is at/over a configured blocking tolerance, so the accountant cannot post it until it is corrected or rejected back to the floor. Check the figures above against what the floor recorded; whether material is actually missing is a question for the day bin's daily count, not this batch."
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

/*
 * THE "Material Reconciliation" SECTION IS GONE, deliberately.
 *
 * It printed "Issued / Good / Lumps" and, beneath it, an "Unaccounted" figure
 * defined as issued − good − rejection − lumps. That figure was ~0 by
 * construction: nothing weighs a fixed quantity of resin out to a machine, so
 * a batch's issued kg IS good + rejection + lumps, and the subtraction could
 * only ever return its own inputs. An accountant reading it as a loss figure
 * was reading arithmetic, not material.
 *
 * With the unaccounted line removed the section held one row whose three
 * figures are all already on screen — good kg and lumps in Expected vs Actual
 * above, the issued total now captioned under the Material Consumption list
 * below — so the section itself went with it rather than leaving a heading
 * over a duplicate.
 *
 * The real question ("is any material missing?") is asked on the Day Bin page,
 * per machine: bags scanned into a machine minus what its batches calculated
 * out, and a NEGATIVE estimated remaining is the signal. It is deliberately
 * not a physical count — the owner ruled (31-Jul) that this factory takes no
 * bin weight, so the daily reconciliation that once lived there is gone.
 * `metrics.reconciliation_unaccounted_kg` and `unaccounted_band` are still
 * served and still read elsewhere (Reports); this is a display decision on the
 * approval desk, not a change to the engine.
 */

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
                    {/* WHAT THIS VOUCHER DELIBERATELY DOES NOT CARRY. The
                        backend has been building these since the tape and
                        scrap rulings and nothing rendered them, so the two
                        figures the owner explicitly asked to be "withheld with
                        its note" reached the accountant as silence — which
                        reads exactly like the ERP never counted them.

                        Deliberately NOT an error: held back on purpose is not
                        broken, and colouring it red would train the accountant
                        to click past the red that means Tally will refuse. */}
                    {(preview.withheld ?? []).length > 0 && (
                        <Alert
                            type="info"
                            showIcon
                            style={{ marginTop: 8 }}
                            message="Counted on this batch, deliberately not posted to Tally"
                            description={
                                <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                                    {(preview.withheld ?? []).map((line) => (
                                        <li key={`${line.kind}-${line.item ?? ''}`}>
                                            <Typography.Text strong>
                                                {line.item ?? line.kind}: {line.quantity} {line.unit}
                                            </Typography.Text>{' '}
                                            — {line.reason}
                                        </li>
                                    ))}
                                </ul>
                            }
                        />
                    )}
                    {/* The quiet sentences. MINUS anything the withheld list
                        above already said in full: VoucherPreviewService::notes()
                        copies the scrap entry's whole `reason` into `notes` —
                        it predates anything rendering `withheld`, and
                        BatchVoucherShapeTest pins it there — so printing both
                        lists verbatim shows the accountant that entire
                        paragraph twice, back to back. Filtered here rather
                        than dropped on the server because the note is the
                        older, tested contract; the tape note is unaffected, it
                        is a short pointer AT the withheld line rather than a
                        copy of it. */}
                    {(() => {
                        const reasons = new Set((preview.withheld ?? []).map((line) => line.reason));
                        const notes = (preview.notes ?? []).filter((note) => !reasons.has(note));

                        return notes.length === 0 ? null : (
                            <ul style={{ margin: '8px 0 0', paddingLeft: 18 }}>
                                {notes.map((note) => (
                                    <li key={note}>
                                        <Typography.Text type="secondary">{note}</Typography.Text>
                                    </li>
                                ))}
                            </ul>
                        );
                    })()}
                </>
            )}
        </>
    );
}

/**
 * WHAT HAS BEEN DONE TO THIS BATCH SINCE IT WAS COMPLETED — quality's returns
 * and the floor's own corrections, in the order they happened.
 *
 * The approver signs figures that may be the SECOND or third set this batch
 * has carried, and until now nothing on this page said so: a batch quality
 * sent back at 22:10 and the supervisor re-entered at 22:40 arrived at the PM
 * looking exactly like one that was right the first time. The reason quality
 * gave is the single most useful sentence on the drawer for deciding whether
 * to sign, and it was being shown to nobody.
 *
 * A QUIET TIMELINE, deliberately. This is context for a decision, not an
 * incident report — most batches render nothing here at all (the section
 * returns null), and the ones that do get one line per event.
 *
 * NAMES ARE BEST-EFFORT. The snapshot stores users.id and the resource does
 * not resolve them; `userNames` is filled from /users, which a Plant Manager
 * login may not be allowed to read. When the name is missing the line simply
 * omits it — "user #7" is noise, and the WHEN and WHY are what is being read.
 */
function CorrectionHistorySection({
    row,
    userNames,
}: {
    row: ShiftProductionEntry;
    userNames: Map<number, string>;
}) {
    const correction = readCorrection(row);
    const returns = correction?.returns ?? [];
    const amendments = correction?.amendments ?? [];

    if (returns.length === 0 && amendments.length === 0) return null;

    const who = (id: number | null | undefined): string | null => {
        if (id === null || id === undefined) return null;
        return userNames.get(id) ?? null;
    };
    const when = (at: string | null | undefined): string | null =>
        at ? dayjs(at).format('DD MMM YYYY HH:mm') : null;

    type Event = {
        key: string;
        kind: 'return' | 'amendment';
        at: string | null;
        by: string | null;
        reason: string | null;
        note: string | null;
    };

    const events: Event[] = [
        ...returns.map((entry, index): Event => ({
            key: `return-${index}`,
            kind: 'return',
            at: entry.returned_at ?? null,
            by: who(entry.returned_by),
            reason: (entry.reason ?? '').trim() === '' ? null : entry.reason,
            note: entry.cleared_quality_check ? 'The quality check recorded before this was unwound with it.' : null,
        })),
        ...amendments.map((entry, index): Event => {
            // A decimal string off the snapshot ("2400.0000"). Shown as a
            // piece count, because that is what it is — the raw four decimal
            // places read as a weight and this figure is bottles.
            const previous = (entry.previous_quantity_produced ?? '').trim();
            const previousNumber = previous === '' ? NaN : Number(previous);
            const previousShown = Number.isFinite(previousNumber)
                ? previousNumber.toLocaleString('en-IN')
                : previous;
            return {
                key: `amendment-${index}`,
                kind: 'amendment',
                at: entry.amended_at ?? null,
                by: who(entry.amended_by),
                reason: (entry.reason ?? '').trim() === '' ? null : (entry.reason ?? null),
                // The movement, not just the final figure — "produced read
                // 2,400 before this" is what makes a correction checkable.
                note: previous === '' ? null : `Produced read ${previousShown} before this correction.`,
            };
        }),
    ]
        // Time order, with anything undated last: a snapshot written by an
        // older backend has no timestamp, and guessing one would put it in a
        // position it cannot be defended in.
        .sort((a, b) => {
            if (a.at === null && b.at === null) return 0;
            if (a.at === null) return 1;
            if (b.at === null) return -1;
            return dayjs(a.at).valueOf() - dayjs(b.at).valueOf();
        });

    return (
        <>
            <Typography.Title level={5} style={{ marginTop: 16 }}>
                What happened to this batch
            </Typography.Title>
            <Timeline
                style={{ marginTop: 8 }}
                items={events.map((event) => ({
                    key: event.key,
                    color: event.kind === 'return' ? 'red' : 'blue',
                    children: (
                        <Space direction="vertical" size={0}>
                            <Typography.Text strong style={{ fontSize: 13 }}>
                                {event.kind === 'return' ? 'Quality sent it back' : 'The floor corrected its figures'}
                            </Typography.Text>
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {[when(event.at), event.by].filter((part) => part !== null).join(' · ') || 'time not recorded'}
                            </Typography.Text>
                            {event.reason !== null && (
                                <Typography.Text style={{ fontSize: 12 }}>“{event.reason}”</Typography.Text>
                            )}
                            {event.note !== null && (
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {event.note}
                                </Typography.Text>
                            )}
                            {event.reason === null && event.kind === 'return' && (
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    No reason was recorded.
                                </Typography.Text>
                            )}
                        </Space>
                    ),
                }))}
            />
        </>
    );
}

/**
 * THE REAL FINAL PACKING OF THE APPROVED QUANTITY — full cartons, full trays,
 * and the one part-filled tray a quality rejection leaves behind.
 *
 * WHY IT IS NOT THE ENTERED PACKING. The counts the supervisor typed record
 * what physically left the floor, gross. Quality then rejects pieces out of
 * that, and the approved quantity no longer divides into whole trays: 2,154
 * approved at 120 per tray is 17 full trays and one tray of 114. Printing only
 * the entered "18 trays" beside an approved 2,154 asks the approver to do that
 * division in their head, and printing only the cartons reads short of the
 * approved count by a tray's worth.
 *
 * PRECONDITIONS COPIED FROM looseTraysOver, and for the same reason: cartons
 * must be a whole number of trays (`perBox % perTray`), or the figures belong
 * to a multi-mode run where the carton count spans modes the tray count does
 * not — and this decomposition would then be arithmetic on two different
 * things.
 *
 * TWO MODES, NEVER BOTH AT ONCE. A pouch-packed run decomposes into pouches
 * the same way; a run that recorded BOTH pouches and cartons is refused
 * outright, because nothing recorded says how many of the approved pieces went
 * down each route and every division would be against the wrong denominator.
 *
 * Returns null whenever it cannot be certain, and the drawer then shows
 * exactly what it always showed.
 */
type FinalPacking =
    | {
          mode: 'tray';
          approved: number;
          /**
           * The approved pieces counted STRAIGHT INTO TRAYS, ignoring the carton
           * grouping — 2,154 at 120 is 17. It is the figure a packer checks
           * against a bench: they fill trays, and the cartons are what the full
           * trays are then stacked into. Printing only the carton decomposition
           * ("3 cartons · 2 trays") made the approver multiply and add to get
           * back to the number they were actually approving.
           */
          fullTrays: number;
          cartons: number;
          trays: number;
          partialTray: number;
          perTray: number;
          perBox: number;
      }
    | { mode: 'pouch'; approved: number; pouches: number; loose: number; perPouch: number };

/**
 * "3 cartons + 2 trays + partial" — the same approved pieces, grouped the way
 * they physically leave the floor.
 *
 * Zero terms are DROPPED rather than printed: "0 cartons + 2 trays" invites the
 * reader to wonder which carton went missing, when the honest answer is that a
 * short run never filled one. And with NO full carton the grouping is dropped
 * whole: it would then be the tray sentence beside it, said a second time in
 * different words, which reads as a second fact.
 */
function cartonGrouping(packing: Extract<FinalPacking, { mode: 'tray' }>): string {
    if (packing.cartons <= 0) return '';
    const parts: string[] = [`${packing.cartons} ${packing.cartons === 1 ? 'carton' : 'cartons'}`];
    if (packing.trays > 0) parts.push(`${packing.trays} ${packing.trays === 1 ? 'tray' : 'trays'}`);
    if (packing.partialTray > 0) parts.push('partial');
    return parts.join(' + ');
}

/**
 * "17 full trays + 1 tray of 114 (3 cartons + 2 trays + partial)".
 *
 * The trays first, because that is the count a packer can walk up to a bench
 * and check; the cartons in brackets, because that is how the same pieces get
 * stacked to leave. Every term that came to nothing is omitted, so a run of 50
 * reads "1 tray of 50" rather than "0 full trays + 1 tray of 50 ()".
 */
function trayFinalPackingText(packing: Extract<FinalPacking, { mode: 'tray' }>): string {
    const parts: string[] = [];
    if (packing.fullTrays > 0) parts.push(`${packing.fullTrays} full ${packing.fullTrays === 1 ? 'tray' : 'trays'}`);
    if (packing.partialTray > 0) parts.push(`1 tray of ${packing.partialTray}`);
    const grouping = cartonGrouping(packing);
    return grouping ? `${parts.join(' + ')} (${grouping})` : parts.join(' + ');
}

function finalPackingAfterQuality(row: ShiftProductionEntry): FinalPacking | null {
    // The APPROVED figure, read off the server's own net — never
    // quantity_produced minus the rejected count, which subtracts twice.
    const approved = netProducedPieces(row);
    if (approved === null || approved <= 0) return null;

    const packedInCartons = (row.no_of_box ?? 0) > 0 || (row.no_of_trays ?? 0) > 0;
    const packedInPouches = (row.no_of_pouches ?? 0) > 0;

    // A MULTI-MODE RUN: some of the approved pieces went into pouches and
    // some into trays, and nothing recorded says how many of each. Every
    // decomposition below would be arithmetic on the wrong denominator, so
    // the drawer says nothing and shows exactly what it always showed.
    if (packedInCartons && packedInPouches) return null;

    // PIECES RECORDED OUTSIDE THE PACK HIERARCHY. `loose_pieces` exists
    // because a run genuinely can ship pieces in no tray and no pouch, and a
    // remainder printed as "1 pouch of 14" would then assert a pouch that was
    // never filled. Checked for BOTH modes, ahead of the split: the remainder
    // this function computes is only a part-pack when nothing was loose.
    if ((row.loose_pieces ?? 0) > 0) return null;

    if (packedInPouches) {
        const perPouch = row.item?.nos_per_pouch;
        if (!perPouch || perPouch < 1) return null;
        const pouches = Math.floor(approved / perPouch);
        return { mode: 'pouch', approved, pouches, loose: approved - pouches * perPouch, perPouch };
    }

    if (!packedInCartons) return null;

    const perTray = row.nos_per_tray;
    const perBox = row.nos_per_box;
    if (!perTray || perTray < 1 || !perBox || perBox < 1) return null;
    if (perBox % perTray !== 0) return null;

    const cartons = Math.floor(approved / perBox);
    const afterCartons = approved - cartons * perBox;
    const trays = Math.floor(afterCartons / perTray);

    return {
        mode: 'tray',
        approved,
        // Deliberately its own division rather than cartons × trays-per-carton
        // + trays: identical arithmetic (perBox % perTray === 0 is checked
        // above), but stated against the approved figure itself, which is the
        // number this whole read-out exists to explain.
        fullTrays: Math.floor(approved / perTray),
        cartons,
        trays,
        partialTray: afterCartons - trays * perTray,
        perTray,
        perBox,
    };
}

/**
 * The chain: Supervisor submits → Plant Manager verifies → Accountant
 * reconciles and posts → Tally. The accountant is FINAL; there is no MD stage.
 * Each row's available action depends on its stage AND the viewer's role — the
 * stage config drives both the button and the visibility.
 */
/** One sentence, used by both the table button and the drawer button. */
const AWAITING_QUALITY_REASON = 'Quality has not checked this batch yet. It appears in Quality → Production QC.';

/**
 * What quality found, compact enough for a table cell: the three counts and
 * the net production figure this approval actually signs off.
 *
 * Counts are PIECES. The batch's other rejection figures on this page are
 * kilograms; nothing here mixes the two.
 */
function QualityCell({ row, awaiting }: { row: ShiftProductionEntry; awaiting: boolean }): ReactElement {
    const quality = readQuality(row);
    const num = (n: number | null) => (n === null ? '—' : n.toLocaleString('en-IN'));

    if (!isQualityChecked(row)) {
        return awaiting ? (
            <Tag>Awaiting quality</Tag>
        ) : (
            // Not awaiting and not checked: the batch is already past the PM
            // gate, so it went through before the gate was switched on. Say
            // nothing rather than imply a check was skipped.
            <Typography.Text type="secondary">—</Typography.Text>
        );
    }

    const rejected = quality?.rejected_nos ?? null;
    const net = netProducedPieces(row);
    const gross = grossProducedPieces(row);
    const reduced = rejected !== null && rejected > 0;

    return (
        <Space direction="vertical" size={0}>
            <Typography.Text style={{ fontSize: 12 }}>
                {num(quality?.reviewed_nos ?? null)} reviewed · {num(quality?.ok_nos ?? null)} OK ·{' '}
                <Typography.Text style={{ fontSize: 12 }} type={reduced ? 'danger' : undefined}>
                    {num(rejected)} rejected
                </Typography.Text>
            </Typography.Text>
            {/* The figure the PM is actually approving. When quality reduced
                it, the supervisor's original is shown struck through beside
                it — the subtraction the owner asked for, shown not implied. */}
            <Typography.Text strong style={{ fontSize: 12 }}>
                Net {num(net)} pcs
                {reduced && gross !== null && (
                    <Typography.Text type="secondary" delete style={{ fontSize: 12, fontWeight: 400, marginLeft: 6 }}>
                        {num(gross)}
                    </Typography.Text>
                )}
            </Typography.Text>
            {/* The rejected pieces left finished goods, but their mass could
                not be received as scrap — this ERP has no scrap item yet. The
                approver is told, rather than it vanishing into a log. */}
            {quality?.scrap_note && (
                <Typography.Text type="warning" style={{ fontSize: 11 }}>
                    {quality.scrap_note}
                </Typography.Text>
            )}
        </Space>
    );
}

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
    // The tab set is the backend statuses plus one client-side view: batches
    // still with quality are status=pending on the server but not yet the
    // PM's to act on.
    type QueueTab = ShiftProductionEntryStatus | 'awaiting_quality';
    const [status, setStatus] = useState<QueueTab>('pending');
    const [detailRow, setDetailRow] = useState<ShiftProductionEntry | null>(null);
    const [rejectingRow, setRejectingRow] = useState<ShiftProductionEntry | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const queryClient = useQueryClient();
    const settings = useProductionSettings();
    const user = useAuthStore((s) => s.user);
    const myRoles = user?.roles?.map((r) => r.name) ?? [];
    /**
     * MAY THIS LOGIN SEE SUPPLIER RATES — finance.view or finance.manage,
     * read through the same helper the sidebar uses rather than a new one.
     *
     * The server has already made this call (it omits the breakdown keys
     * entirely for anyone else); this is that same rule honoured locally, and
     * it can only ever hide more, never reveal. See BatchCostSection.
     */
    const showsFinanceDetail = hasModuleAccess(user, 'finance');

    const stageFor = (s: ShiftProductionEntryStatus) => STAGES.find((st) => st.status === s);
    const canActOn = (row: ShiftProductionEntry) => {
        const stage = stageFor(row.status);
        return stage !== undefined && stage.roles.some((r) => myRoles.includes(r));
    };

    // ------------------------------------------------------------------
    // The quality gate (owner, 2026-07-30): every completed batch is checked
    // by quality before the Plant Manager approves it.
    //
    // The flag rides on each ENTRY (`quality.stage_enabled`), not on
    // /production/settings, so the switch and the check it governs always
    // arrive together. Read per row for that reason — a row that predates the
    // gate carries no block and is treated as ungated, which is what it is.
    //
    // Only the PM's gate. Once a batch is pm_approved the check has already
    // happened (or the gate was off when it passed), and re-blocking the
    // accountant would strand it halfway with no way forward.
    // ------------------------------------------------------------------
    const awaitingQuality = (row: ShiftProductionEntry) =>
        readQualityStageEnabled(row) && row.status === 'pending' && !isQualityChecked(row);

    // "Awaiting Quality" is a VIEW of the pending queue, not a backend status
    // — the quality gate is a precondition on the PM's approval, so both tabs
    // read status=pending and split client-side on whether the check exists.
    // One fetch serves both, and the two tabs can never disagree with the
    // server about what pending means. The owner saw this page still claiming
    // the chain was Supervisor → PM → Accountant and asked, reasonably,
    // "there is no quality here?" — the queue quality feeds was invisible
    // from the very page that waits on it.
    const fetchStatus: ShiftProductionEntryStatus = status === 'awaiting_quality' ? 'pending' : status;

    const { data, isLoading } = useQuery({
        queryKey: ['production', 'shift-production-entries', fetchStatus],
        queryFn: () => listShiftProductionEntries(fetchStatus),
    });

    // Does any row in this queue carry the gate? Drives whether the Quality
    // column is drawn at all — with the gate off the table is column-for-column
    // what it has always been.
    const qualityStageEnabled = (data?.data ?? []).some((row) => readQualityStageEnabled(row));

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'shift-production-entries'] });

    // The open batch's trays over its cartons, worked out once for the Packing
    // line below (null on any row where the figures don't reconcile).
    const detailLooseTrays = detailRow === null ? null : looseTraysOver(detailRow);
    // Quality reduced the produced figure but not the packing counts.
    const detailQualityRejected = (readQuality(detailRow)?.rejected_nos ?? 0) > 0;
    // What the approved quantity actually packs into once quality has taken
    // its rejects out. Null unless every figure needed is present and
    // unambiguous — see finalPackingAfterQuality.
    const detailFinalPacking = detailRow === null ? null : finalPackingAfterQuality(detailRow);

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

    // Names for the correction trail. The snapshot stores users.id only, and
    // this list is what turns those into people. `retry: false` and a graceful
    // empty map because an approver login may have no user-admin rights: a
    // 403 here must cost the timeline its names, never the drawer.
    const { data: users } = useQuery({
        queryKey: ['access', 'users', 'approval-trail'],
        queryFn: listUsers,
        retry: false,
        enabled: detailRow !== null,
        staleTime: 5 * 60 * 1000,
    });
    const userNames = useMemo(
        () => new Map((users?.data ?? []).map((u) => [u.id, u.name] as const)),
        [users],
    );

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
                Every completed batch passes the chain — Supervisor → Quality → Plant Manager → Accountant —
                and posts to Tally the moment the Accountant approves. Quality works its own queue
                (Quality → Production QC); rejection at any stage sends the batch back to the supervisor.
            </Typography.Paragraph>

            <Segmented
                value={status}
                onChange={(v) => setStatus(v as QueueTab)}
                options={[
                    // First because it is first in the chain. Read-only here —
                    // the check itself happens on the Production QC page; this
                    // tab exists so an approver can see what has not reached
                    // them yet without asking quality.
                    { label: 'Awaiting Quality', value: 'awaiting_quality' },
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
                dataSource={
                    // Both tabs read the same pending fetch and split on the
                    // check's existence, so they can never disagree with the
                    // server about what pending means. Other tabs pass through.
                    status === 'awaiting_quality'
                        ? (data?.data ?? []).filter(awaitingQuality)
                        : status === 'pending'
                          ? (data?.data ?? []).filter((row) => !awaitingQuality(row))
                          : data?.data
                }
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
                    // Only rendered when the gate is switched on — with it off
                    // the table is column-for-column what it has always been.
                    ...(qualityStageEnabled
                        ? [
                              {
                                  title: 'Quality',
                                  render: (_: unknown, row: ShiftProductionEntry) => <QualityCell row={row} awaiting={awaitingQuality(row)} />,
                              },
                          ]
                        : []),
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
                                        <Tooltip title={awaitingQuality(row) ? AWAITING_QUALITY_REASON : ''}>
                                            {/* span: antd tooltips need a live
                                                element to hang off, and a
                                                disabled button fires no events. */}
                                            <span>
                                                <Button
                                                    size="small"
                                                    type="primary"
                                                    disabled={awaitingQuality(row)}
                                                    loading={approveMutation.isPending}
                                                    onClick={() => approveMutation.mutate(row)}
                                                >
                                                    {stageFor(row.status)!.action}
                                                </Button>
                                            </span>
                                        </Tooltip>
                                        {/* Reject stays live. Sending an
                                            unchecked batch back to the
                                            supervisor is a valid thing to do,
                                            and blocking it would leave a bad
                                            batch with nowhere to go. */}
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
                                {/* The cartons alone are not the count. Trays over
                                    them are pieces too, and without them this line
                                    reads short of Produced by exactly their worth. */}
                                {detailLooseTrays &&
                                    ` + ${detailLooseTrays.trays} loose trays (${detailLooseTrays.pieces} pcs)`}
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
                                {/* Quality reduced Produced but not the packing counts —
                                    those record what physically left the floor. Two
                                    figures that legitimately disagree, said once, here,
                                    where both are on screen together. */}
                                {detailQualityRejected && (
                                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                        Packing counts show what was packed (gross); produced is net of quality rejection.
                                    </Typography.Text>
                                )}
                                {/* WHAT THE APPROVED QUANTITY ACTUALLY PACKS INTO.
                                    Only once quality has taken pieces out — before
                                    that it is the entered packing, said twice. The
                                    part-filled tray is the whole point: it is where
                                    the rejected pieces came from, and it is the
                                    figure nobody can work out from the line above
                                    without dividing in their head. */}
                                {detailQualityRejected && detailFinalPacking && (
                                    <div style={{ marginTop: 6 }}>
                                        <Typography.Text strong style={{ display: 'block', fontSize: 12 }}>
                                            Final packing of the {detailFinalPacking.approved.toLocaleString('en-IN')}{' '}
                                            approved:{' '}
                                            {detailFinalPacking.mode === 'tray' ? (
                                                trayFinalPackingText(detailFinalPacking)
                                            ) : (
                                                <>
                                                    {detailFinalPacking.pouches} full{' '}
                                                    {detailFinalPacking.pouches === 1 ? 'pouch' : 'pouches'}
                                                    {detailFinalPacking.loose > 0
                                                        ? ` · 1 pouch of ${detailFinalPacking.loose}`
                                                        : ''}
                                                </>
                                            )}
                                        </Typography.Text>
                                        {/* The one caption that keeps the two sets of
                                            figures from looking like a contradiction:
                                            a rejection does not put a carton back in
                                            the store, so the consumption lines below
                                            are unchanged and correct. */}
                                        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                            Worked out at{' '}
                                            {detailFinalPacking.mode === 'tray'
                                                ? `${detailFinalPacking.perBox}/carton and ${detailFinalPacking.perTray}/tray`
                                                : `${detailFinalPacking.perPouch}/pouch`}
                                            . The material consumption lines below still show the packaging actually used
                                            on the floor — a quality rejection does not put a carton back in the store.
                                        </Typography.Text>
                                    </div>
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

                        {/* WHY THESE FIGURES MAY NOT BE THE FIRST SET. Directly
                            under the figures themselves, so quality's reason and
                            the correction that answered it are read with the
                            numbers still on screen. Renders nothing at all on the
                            ordinary batch that was right first time. */}
                        <CorrectionHistorySection row={detailRow} userNames={userNames} />

                        {/* Expected vs actual first: it is the question both
                            approvers are actually answering. The sections
                            below explain any gap it shows. */}
                        <ExpectedVsActualSection row={detailRow} metrics={detailRow.metrics} />

                        <DowntimeSection row={detailRow} logs={downtimeLogs?.data} loading={downtimeLoading} />

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
                                        // Header names no unit — every line
                                        // carries its own beside the figure,
                                        // because the lines genuinely differ
                                        // (resin in kg, cartons in nos).
                                        {
                                            title: 'Qty',
                                            align: 'right',
                                            render: (_, row) => `${row.quantity_issued_kg} ${unitLabel(row.item?.uom)}`,
                                        },
                                    ]}
                                />
                                {/* The kg total the deleted "Material
                                    Reconciliation" section used to carry,
                                    folded in under the lines it is the sum of.
                                    The server's own kg-only figure: lines
                                    counted in Nos are listed above but never
                                    added to a kilogram total. */}
                                {detailRow.metrics && (
                                    <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8, fontSize: 12 }}>
                                        Consumed on these lines: {fmtKg(detailRow.metrics.issued_kg)} kg of material counted in
                                        kg — resin consumption is calculated as production + rejection + lumps, not weighed out
                                        per machine. Whether a machine has burnt material nobody scanned in shows up on the{' '}
                                        <Link to="/production/day-bin">Day Bin</Link> page, as an estimated remaining that has
                                        gone negative.
                                    </Typography.Text>
                                )}
                            </>
                        )}

                        {/* WHAT IT COST, directly under WHAT IT CONSUMED — the
                            same lines, priced. Rendered whenever the backend
                            serves the block at all (it always does on a build
                            that has the costing layer, carrying a sentence
                            instead of figures before completion); absent
                            entirely on one that predates it, rather than
                            showing an empty shell nobody can fill. */}
                        {detailRow.batch_cost && (
                            <BatchCostSection cost={detailRow.batch_cost} showsDetail={showsFinanceDetail} />
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
                                        // Lumps are weighed, never counted — the entry
                                        // screen does not even offer a Nos box for them.
                                        // A dash here would read as "a count exists and
                                        // is missing"; blank says the column does not
                                        // apply to this line. Rejected FG still shows
                                        // its count, dash included.
                                        {
                                            title: 'Nos',
                                            render: (_, row) => (row.type === 'lumps' ? '' : (row.quantity_nos ?? '—')),
                                        },
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

                        {/* The same gate as the table row. Without it the
                            drawer would be a way round the block for anyone
                            who opened the batch instead of approving from
                            the list. */}
                        {/* awaitingQuality already carries the per-row stage
                            check, so this needs no second flag. */}
                        {awaitingQuality(detailRow) && (
                            <Alert
                                style={{ marginTop: 24 }}
                                type="warning"
                                showIcon
                                message="Awaiting quality"
                                description={AWAITING_QUALITY_REASON}
                            />
                        )}

                        {canActOn(detailRow) && (
                            <Space style={{ marginTop: 24 }}>
                                <Tooltip title={awaitingQuality(detailRow) ? AWAITING_QUALITY_REASON : ''}>
                                    <span>
                                        <Button
                                            type="primary"
                                            disabled={awaitingQuality(detailRow)}
                                            loading={approveMutation.isPending}
                                            onClick={() => approveMutation.mutate(detailRow)}
                                        >
                                            {stageFor(detailRow.status)!.action}
                                        </Button>
                                    </span>
                                </Tooltip>
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
