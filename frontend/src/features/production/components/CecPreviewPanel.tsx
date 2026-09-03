import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Descriptions, Empty, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { Link } from 'react-router-dom';
import { getCecReport } from '@/features/production/api';
import {
    CEC_PREVIEW_CAPTION,
    cecShiftSections,
    cecSummaryItems,
    cecSumsItems,
    type CecPreviewBatchRow,
    type CecPreviewItem,
} from '@/features/production/cecPreview';
import type { CecSums, ShiftKpiReport } from '@/features/production/types';
import { columnSorter, filterOptions, onFilterBy } from '@/lib/clientSort';

/**
 * CEC preview (Phase 5.7, WS-C) — the DATA `GET production/cec` returns for
 * the chosen date (one shift, or every shift that ran it), laid out shift →
 * machine → batches. THIS IS NOT THE CEC: no CEC sample or format authority
 * exists in the repo, the export kind stays blocked, and the server states
 * that in `format`, shown here verbatim. So there is deliberately NO
 * download, NO layout claim, and NO figure computed on this side — every
 * cell is the server's own figure as sent (see cecPreview.ts), and the sums
 * shown are the server's labelled plain sums. The day the owner's sample
 * lands, the format becomes a golden test on the server and this panel's
 * columns become its columns.
 */
export interface CecPreviewPanelProps {
    productionDate: string;
    /** Omitted — the day-wide read, every shift that ran the date. */
    shiftId?: number;
    /** false while the page is still resolving its shift — the day-wide read is the heaviest and must not fire first. */
    enabled?: boolean;
}

const tabular = { fontVariantNumeric: 'tabular-nums' } as const;
const right = { align: 'right' as const, onCell: () => ({ style: tabular }) };

/** A text column that sorts on what it shows. */
const text = (title: string, key: keyof CecPreviewBatchRow) => ({
    title,
    dataIndex: key,
    key,
    sorter: columnSorter((row: CecPreviewBatchRow) => row[key], 'text'),
});

/** A figure column: right-aligned, tabular, sorted as a number ("—" last). */
const figure = (title: string, key: keyof CecPreviewBatchRow) => ({
    title,
    dataIndex: key,
    key,
    ...right,
    sorter: columnSorter((row: CecPreviewBatchRow) => row[key], 'number'),
});

/** "46.2%" → 46.2; "—" → null, so it sorts last. */
const efficiencyValue = (row: CecPreviewBatchRow) => (row.efficiency === '—' ? null : parseFloat(row.efficiency));

/**
 * One machine's batch table — every batch of the machine is on screen, so
 * the columns sort and filter here, on the server's figures as shown.
 */
const columnsFor = (rows: CecPreviewBatchRow[]) => [
    text('Batch', 'batch'),
    text('SKU', 'sku'),
    text('Product', 'product'),
    figure('Expected (pcs)', 'expectedPieces'),
    figure('Actual (pcs)', 'actualPieces'),
    figure('Good (kg)', 'goodKg'),
    figure('Reject (kg)', 'rejectionKg'),
    figure('QC reject (kg)', 'rejectionKgQc'),
    { title: 'Efficiency', dataIndex: 'efficiency', key: 'efficiency', ...right, sorter: columnSorter(efficiencyValue, 'number') },
    figure('Packs', 'packs'),
    figure('Downtime (min)', 'downtimeMinutes'),
    {
        title: 'Approval',
        dataIndex: 'approval',
        key: 'approval',
        filters: filterOptions(rows, (row) => row.approval),
        onFilter: onFilterBy((row: CecPreviewBatchRow) => row.approval),
    },
    {
        title: 'Tally',
        key: 'tally',
        sorter: columnSorter((row: CecPreviewBatchRow) => row.tallyStatus, 'text'),
        render: (_: unknown, row: CecPreviewBatchRow) =>
            row.tallyLink ? (
                <Link to={row.tallyLink}>
                    {row.tallyStatus} · {row.tallyVoucher}
                </Link>
            ) : (
                <span>{row.tallyStatus}</span>
            ),
    },
];

/**
 * A 404 is a backend that predates the endpoint, not a broken page; anything
 * else is worded as the server worded it. Null when there is no error.
 */
function describeLoadError(error: unknown): string | null {
    if (!error) return null;
    const response = (error as { response?: { status?: number; data?: { message?: string } } }).response;
    if (response?.status === 404) return 'The CEC data endpoint is not on this server yet.';
    return response?.data?.message ?? 'Could not load the CEC data.';
}

function ItemsLine({ items, title }: { items: CecPreviewItem[]; title: string }) {
    if (items.length === 0) return null;
    return (
        <Descriptions size="small" column={{ xs: 1, sm: 2, md: 3, lg: 4 }} title={title} style={{ marginTop: 8 }}>
            {items.map((item) => (
                <Descriptions.Item key={item.key} label={item.label}>
                    <span style={tabular}>{item.value}</span>
                </Descriptions.Item>
            ))}
        </Descriptions>
    );
}

/** The server's plain sums for a block — with its own basis sentence in a tooltip, verbatim. */
function SumsLine({ sums, title }: { sums: CecSums | null | undefined; title: string }) {
    if (!sums) return null;
    return (
        <Tooltip title={sums.basis}>
            <div>
                <ItemsLine items={cecSumsItems(sums)} title={title} />
            </div>
        </Tooltip>
    );
}

function SummaryLine({ summary, title }: { summary: ShiftKpiReport | null | undefined; title: string }) {
    return <ItemsLine items={cecSummaryItems(summary)} title={title} />;
}

export default function CecPreviewPanel({ productionDate, shiftId, enabled = true }: CecPreviewPanelProps) {
    const { data: report, isLoading, error } = useQuery({
        queryKey: ['production', 'cec', shiftId ?? 'day', productionDate],
        queryFn: () => getCecReport(productionDate, shiftId),
        retry: false,
        enabled,
    });

    const sections = cecShiftSections(report);
    const errorMessage = describeLoadError(error);

    return (
        <Card title="CEC preview" size="small" loading={isLoading}>
            <Space direction="vertical" size={12} style={{ width: '100%' }}>
                <Alert
                    type="warning"
                    showIcon
                    message={CEC_PREVIEW_CAPTION}
                    description={
                        <Space direction="vertical" size={4}>
                            <Typography.Text>
                                Data only — every figure below is the Shift Summary&apos;s and the completed entries&apos; as the
                                server sends them; nothing is recomputed here and there is no download until the format is known.
                            </Typography.Text>
                            {report?.format && (
                                <Typography.Text>
                                    Server: <Typography.Text code>{report.format}</Typography.Text>
                                    {report.figures_from?.length ? (
                                        <Typography.Text type="secondary"> · figures from {report.figures_from.join(', ')}</Typography.Text>
                                    ) : null}
                                </Typography.Text>
                            )}
                        </Space>
                    }
                />

                {errorMessage && <Alert type="info" showIcon message={errorMessage} />}

                {!errorMessage && !isLoading && sections.length === 0 && (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing recorded on this date." />
                )}

                {sections.map((section) => (
                    <Card
                        key={section.key}
                        type="inner"
                        size="small"
                        title={
                            <Space>
                                <span>Shift · {section.shift}</span>
                                <Tag>{section.sums.batches} completed</Tag>
                            </Space>
                        }
                    >
                        <SummaryLine summary={section.summary} title="Shift Summary (server)" />
                        {section.machines.length === 0 && (
                            <Typography.Text type="secondary">No completed batches on this shift.</Typography.Text>
                        )}
                        {section.machines.map((machine) => (
                            <div key={machine.key} style={{ marginTop: 12 }}>
                                <Typography.Text strong>Machine · {machine.machine}</Typography.Text>
                                <Table<CecPreviewBatchRow>
                                    size="small"
                                    scroll={{ x: 'max-content' }}
                                    rowKey="key"
                                    pagination={false}
                                    dataSource={machine.rows}
                                    columns={columnsFor(machine.rows)}
                                    locale={{ emptyText: 'No batches.' }}
                                    style={{ marginTop: 4 }}
                                />
                                <SumsLine sums={machine.sums} title="Machine sums (server)" />
                            </div>
                        ))}
                        {section.machines.length > 0 && <SumsLine sums={section.sums} title="Shift sums (server)" />}
                    </Card>
                ))}

                {report?.day && (
                    <Card type="inner" size="small" title="Whole day (server)">
                        <SummaryLine summary={report.day.summary} title="Day Shift Summary" />
                        <SumsLine sums={report.day.sums} title="Day sums" />
                    </Card>
                )}
            </Space>
        </Card>
    );
}
