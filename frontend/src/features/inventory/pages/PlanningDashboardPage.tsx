import { useQuery } from '@tanstack/react-query';
import { Card, Space, Table, Tag, Typography } from 'antd';
import { getFulfilmentPlanning } from '@/features/inventory/api';
import { planningBasisLine, planningEtaCell } from '@/features/inventory/planning';
import type { FulfilmentPlanningRow, FulfilmentPlanningTarget } from '@/features/inventory/types';
import { formatQuantity } from '@/features/material-flow/words';
import { itemLabel } from '@/lib/itemLabel';

/**
 * WHEN THE FACTORY COULD HAVE IT — the ETA behind every open production
 * request, and today's targets above it.
 *
 * NO DATE ON THIS PAGE IS STORED ANYWHERE (S11). Every one is computed on read
 * by FulfilmentPlanningService and gone again, because a saved date is already
 * wrong the moment somebody reorders the floor's queue.
 *
 * A REFUSAL IS NOT A BLANK. Where the walk cannot estimate, the row says so
 * and names the reason — and the reason CASCADES (S12): once something ahead
 * cannot be dated, nothing behind it can be either, and the server refuses to
 * quote a caveat-date rather than admit that. A caveat-date is the one output
 * this screen must never invent, and it has no arithmetic with which to.
 *
 * THE BASIS IS FIGURES, NOT PROSE. Nobody on a floor reads a paragraph
 * explaining a date; a row of numbers can be checked against the shift master
 * in ten seconds, and a paragraph is where an unverified claim would hide.
 */
const numeric = { fontVariantNumeric: 'tabular-nums' } as const;
const caption = { fontSize: 12, display: 'block' } as const;

export default function PlanningDashboardPage() {
    const { data, isLoading, isError } = useQuery({
        queryKey: ['inventory', 'fulfilment', 'planning'],
        queryFn: getFulfilmentPlanning,
    });

    const targets = data?.today_targets ?? [];

    return (
        <>
            <Typography.Title level={3} style={{ marginTop: 0 }}>Production Planning</Typography.Title>

            {/* TODAY'S WORK is a priority read, not a capacity claim: a job
                whose first shift falls inside today's shifts is on the list
                whether or not its own finish date is knowable. */}
            <Card size="small" title="Today" style={{ marginBottom: 16 }}>
                {targets.length === 0 ? (
                    <Typography.Text type="secondary">Nothing scheduled for today.</Typography.Text>
                ) : (
                    <Space size={8} wrap>
                        {targets.map((target: FulfilmentPlanningTarget) => (
                            <Tag key={target.request_id} color="blue" style={{ padding: '4px 8px' }}>
                                <span style={numeric}>#{target.priority}</span> {itemLabel(target.item)} ·{' '}
                                <span style={numeric}>{formatQuantity(target.quantity)}</span>
                            </Tag>
                        ))}
                    </Space>
                )}
            </Card>

            <Table<FulfilmentPlanningRow>
                scroll={{ x: 'max-content' }}
                // Keyed by the ORDER LINE: the planning walk answers "when is
                // this line's shortfall ready", and `request_id` appears only
                // on today's targets.
                rowKey="line_id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                locale={{ emptyText: isError ? 'The plan could not be read.' : 'Nothing is queued for production.' }}
                columns={[
                    {
                        title: 'Item',
                        render: (_, row) => (
                            <Space direction="vertical" size={0}>
                                <span>{itemLabel(row.item)}</span>
                                <Typography.Text type="secondary" style={caption}>
                                    {row.customer?.name ?? '—'} · SO line {row.line_id}
                                </Typography.Text>
                            </Space>
                        ),
                    },
                    { title: 'Needed', align: 'right', render: (_, row) => <span style={numeric}>{formatQuantity(row.needed)}</span> },
                    { title: 'Free', align: 'right', render: (_, row) => <span style={numeric}>{formatQuantity(row.free)}</span> },
                    {
                        // The honest "why is my order not first" figure, and
                        // one that stays knowable even when nothing behind an
                        // unestimable product can be dated.
                        title: 'Queued ahead',
                        align: 'right',
                        render: (_, row) => <span style={numeric}>{row.queued_ahead}</span>,
                    },
                    {
                        title: 'Per shift',
                        align: 'right',
                        render: (_, row) =>
                            row.capacity_per_shift === null ? (
                                <Typography.Text type="secondary">—</Typography.Text>
                            ) : (
                                <span style={numeric}>{row.capacity_per_shift}</span>
                            ),
                    },
                    {
                        title: 'Ready',
                        render: (_, row) => {
                            const cell = planningEtaCell(row);

                            return cell.dated ? (
                                <Space direction="vertical" size={0}>
                                    <span style={numeric}>{cell.date}</span>
                                    {cell.shifts !== null && (
                                        <Typography.Text type="secondary" style={caption}>
                                            {cell.shifts}
                                        </Typography.Text>
                                    )}
                                </Space>
                            ) : (
                                <Typography.Text type="secondary">{cell.refusal}</Typography.Text>
                            );
                        },
                    },
                ]}
            />

            {/* The numbers those dates stand on. Small, under the table,
                figures only. */}
            {data && (
                <Typography.Text type="secondary" style={{ fontSize: 12, display: 'block', marginTop: 8 }}>
                    {planningBasisLine(data.basis)}
                </Typography.Text>
            )}
        </>
    );
}
