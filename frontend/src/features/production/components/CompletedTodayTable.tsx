import { Card, Space, Table, Tag, Tooltip, Typography } from 'antd';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import {
    EFFICIENCY_CEILING_PCT,
    completedTodayRows,
    configMissingTooltip,
    type CompletedTodayRow,
    type EfficiencyBand,
} from '@/features/production/completedToday';
import { useProductionSettings } from '@/features/production/packing';
import type { ShiftProductionEntry, ShiftProductionEntryStatus } from '@/features/production/types';
import type { TallySyncStatus } from '@/features/tally-sync/types';

/**
 * Completed Today (Phase 5.5, WS-C) — the day's completed batches as the
 * SERVER answers them (production_date = today's factory day, batch_status =
 * completed, up to 100 rows), extracted from ShiftProductionEntryPage.tsx
 * without changing what a row is allowed to do.
 *
 * TWO SHAPES, ONE SET OF FACTS. The desktop table carries the columns a
 * supervisor reads across — machine · shift · SKU · expected · actual · good
 * · reject · efficiency · approval/Tally state — with the figures
 * right-aligned and tabular so they line up down the column. Below `md` it
 * becomes cards, because a nine-column grid on a 390px phone is either a
 * horizontal scroll nobody discovers or a column nobody can read — and this
 * list is read standing at a machine.
 *
 * EVERY FIGURE IS THE SERVER'S — see completedToday.ts. This component
 * formats and places; it computes nothing about the batch. The per-row
 * controls (carton label, correction) stay the page's: it owns the modals
 * they open, so it renders them through `controlsFor`.
 */
export interface CompletedTodayTableProps {
    entries: ShiftProductionEntry[] | undefined;
    loading: boolean;
    /** Below the page's `md` breakpoint — cards instead of the table. */
    narrow: boolean;
    /** The page's own per-row controls (carton label · correction), rendered as-is. */
    controlsFor: (entry: ShiftProductionEntry) => ReactNode;
    /** The empty state's sentence — the page's, unchanged. */
    emptyText?: string;
}

const approvalColor: Record<ShiftProductionEntryStatus, string> = {
    pending: 'processing',
    pm_approved: 'cyan',
    accountant_approved: 'geekblue',
    approved: 'success',
    rejected: 'error',
    synced: 'success',
    failed: 'error',
};

const tallyColor: Record<TallySyncStatus, string> = {
    pending: 'processing',
    synced: 'success',
    failed: 'error',
    dismissed: 'default',
};

const bandColor: Record<EfficiencyBand, string> = {
    ok: 'green',
    watch: 'orange',
    investigate: 'red',
    // Deliberately not a grade — the run beat a standard it cannot beat, so
    // either the entry or the standard is wrong. Red, like Investigate.
    over_standard: 'red',
};

const tabular = { fontVariantNumeric: 'tabular-nums' } as const;

/** Efficiency as the approvers see it: the figure, coloured by its band. */
function EfficiencyCell({ row }: { row: CompletedTodayRow }) {
    if (row.efficiencyPct === null) return <span style={tabular}>—</span>;
    const band = row.efficiencyBand;
    const title = band === 'over_standard' ? 'Over 100% — check entry or standard' : undefined;
    return (
        <Tooltip title={title}>
            <Tag color={band ? bandColor[band] : undefined} style={{ marginInlineEnd: 0, ...tabular }}>
                {row.efficiency}
            </Tag>
        </Tooltip>
    );
}

/**
 * Approval and Tally in ONE cell — the batch's stage in the chain, and, once
 * a voucher exists, where that voucher stands, named by number and linked
 * to the Tally Sync page. No voucher is a dash after the stage, never a
 * state; a run started with an incomplete configuration says so here too.
 */
function StateCell({ row }: { row: CompletedTodayRow }) {
    return (
        <Space size={4} wrap>
            <Tag color={approvalColor[row.approval]} style={{ marginInlineEnd: 0 }}>
                {row.approval}
            </Tag>
            {row.tally === null ? (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Tally —
                </Typography.Text>
            ) : (
                <Link to={row.tally.link} title="Open in Tally Sync">
                    <Tag color={tallyColor[row.tally.status]} style={{ marginInlineEnd: 0 }}>
                        Tally {row.tally.status}
                        {row.tally.voucherNumber ? ` · ${row.tally.voucherNumber}` : ''}
                    </Tag>
                </Link>
            )}
            {row.configIncomplete && (
                <Tooltip title={configMissingTooltip(row) ?? undefined}>
                    <Tag color="warning" style={{ marginInlineEnd: 0 }}>
                        config incomplete
                    </Tag>
                </Tooltip>
            )}
        </Space>
    );
}

export default function CompletedTodayTable({
    entries,
    loading,
    narrow,
    controlsFor,
    emptyText = 'Nothing completed yet today.',
}: CompletedTodayTableProps) {
    // The over-100% ceiling as the BACKEND rules it (tolerances.efficiency_over),
    // the same reading ApproveProductionPage makes, so this list and the
    // approvers' screen paint one figure one colour.
    const efficiencyCeiling = useProductionSettings()?.tolerances?.efficiency_over ?? EFFICIENCY_CEILING_PCT;
    const rows = useMemo(() => completedTodayRows(entries, efficiencyCeiling), [entries, efficiencyCeiling]);
    const byId = useMemo(() => new Map((entries ?? []).map((entry) => [entry.id, entry])), [entries]);
    const controls = (row: CompletedTodayRow): ReactNode => {
        const entry = byId.get(row.id);
        return entry ? controlsFor(entry) : null;
    };

    if (narrow) {
        return (
            <Space direction="vertical" size={8} style={{ width: '100%' }}>
                {loading && <Card size="small" loading />}
                {!loading && rows.length === 0 && <Typography.Text type="secondary">{emptyText}</Typography.Text>}
                {rows.map((row) => (
                    <Card key={row.key} size="small">
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                            <div style={{ minWidth: 0 }}>
                                <Typography.Text strong style={{ wordBreak: 'break-word' }}>
                                    {row.sku}
                                </Typography.Text>
                                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, wordBreak: 'break-word' }}>
                                    {row.product}
                                    {row.finishedItemName ? ` · posts as ${row.finishedItemName}` : ''}
                                </Typography.Text>
                                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                    {row.machine} · {row.shift} · {row.batchNumber}
                                </Typography.Text>
                            </div>
                            <EfficiencyCell row={row} />
                        </div>
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px 16px', marginTop: 8, ...tabular }}>
                            <Typography.Text>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Expected{' '}
                                </Typography.Text>
                                <strong>{row.expectedPieces}</strong>
                            </Typography.Text>
                            <Typography.Text>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Actual{' '}
                                </Typography.Text>
                                <strong>{row.actualPieces}</strong>
                            </Typography.Text>
                            <Typography.Text>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Good{' '}
                                </Typography.Text>
                                <strong>{row.goodPieces}</strong>
                            </Typography.Text>
                            <Typography.Text>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    Reject{' '}
                                </Typography.Text>
                                <strong>{row.rejectPieces}</strong>
                                {row.qcRejectedPieces !== null && row.qcRejectedPieces > 0 ? ` (+${row.qcRejectedPieces} QC)` : ''}
                            </Typography.Text>
                        </div>
                        <div style={{ marginTop: 8 }}>
                            <StateCell row={row} />
                        </div>
                        <Space size={4} wrap style={{ marginTop: 8 }}>
                            {controls(row)}
                        </Space>
                    </Card>
                ))}
            </Space>
        );
    }

    return (
        <Table<CompletedTodayRow>
            scroll={{ x: 'max-content' }}
            rowKey="key"
            size="small"
            loading={loading}
            pagination={false}
            dataSource={rows}
            locale={{ emptyText }}
            columns={[
                {
                    title: 'Machine',
                    render: (_, row) => (
                        <>
                            <Typography.Text strong>{row.machine}</Typography.Text>
                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                {row.batchNumber}
                            </Typography.Text>
                        </>
                    ),
                },
                { title: 'Shift', render: (_, row) => row.shift },
                {
                    title: 'SKU',
                    render: (_, row) => (
                        <>
                            <Typography.Text>{row.sku}</Typography.Text>
                            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
                                {row.product}
                                {row.finishedItemName ? ` · posts as ${row.finishedItemName}` : ''}
                            </Typography.Text>
                        </>
                    ),
                },
                { title: 'Expected', align: 'right', render: (_, row) => <span style={tabular}>{row.expectedPieces}</span> },
                { title: 'Actual', align: 'right', render: (_, row) => <span style={tabular}>{row.actualPieces}</span> },
                { title: 'Good', align: 'right', render: (_, row) => <span style={tabular}>{row.goodPieces}</span> },
                {
                    title: 'Reject',
                    align: 'right',
                    render: (_, row) => (
                        <>
                            <span style={tabular}>{row.rejectPieces}</span>
                            {row.qcRejectedPieces !== null && row.qcRejectedPieces > 0 && (
                                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, ...tabular }}>
                                    +{row.qcRejectedPieces} QC
                                </Typography.Text>
                            )}
                        </>
                    ),
                },
                { title: 'Efficiency', align: 'right', render: (_, row) => <EfficiencyCell row={row} /> },
                {
                    title: 'Approval · Tally',
                    render: (_, row) => (
                        <>
                            <StateCell row={row} />
                            <Space size={4} wrap style={{ marginTop: 4 }}>
                                {controls(row)}
                            </Space>
                        </>
                    ),
                },
            ]}
        />
    );
}
