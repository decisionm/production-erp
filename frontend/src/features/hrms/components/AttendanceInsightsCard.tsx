import { useQuery } from '@tanstack/react-query';
import { Card, Empty, Space, Table, Tag, Typography } from 'antd';
import { getAttendanceInsights } from '@/features/hrms/api';
import { hoursLabel } from '@/features/hrms/attendanceChart';
import type { DateRange } from '@/features/hrms/attendanceRange';
import type { AttendanceInsights } from '@/features/hrms/types';
import TurnoutChart from './TurnoutChart';

/** The hours, as tiles. Every one of them is read off the clock. */
function Hours({ hours }: { hours: AttendanceInsights['hours'] }) {
    const tiles: { label: string; value: string | number; color?: string }[] = [
        { label: 'Average day', value: hoursLabel(hours.average_minutes) },
        { label: 'Days worked', value: hours.days },
        { label: 'Over 10h', value: hours.long_days, color: '#ef6c00' },
        { label: 'Over 12h', value: hours.very_long_days, color: '#c62828' },
        { label: 'Under 4h', value: hours.short_days },
    ];

    // A day longer than sixteen hours is the punch app pairing an in-punch
    // with the wrong day's out-punch. It is a data fault, so it is shown
    // apart and never counted into somebody's hours.
    if (hours.implausible_days > 0) {
        tiles.push({ label: 'Impossible days', value: hours.implausible_days, color: '#b45309' });
    }

    return (
        <Space wrap size="middle">
            {tiles.map((tile) => (
                <div key={tile.label} style={{ minWidth: 110 }}>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {tile.label}
                    </Typography.Text>
                    <div style={{ fontSize: 24, lineHeight: 1.2, color: tile.color }}>{tile.value}</div>
                </div>
            ))}
        </Space>
    );
}

/**
 * THE THREE QUESTIONS A TALLY CANNOT ANSWER.
 *
 * Which days the factory ran short, how long the days actually ran, and who
 * the punch report keeps failing on. Everything here is `hrms.manage`, and
 * the endpoint refuses a view-only login as well as the page hiding it.
 *
 * The hours are the CLOCK's, not the punch app's overtime column: that
 * column is the app's arithmetic against the shift window it was set up
 * with, and that window is the one which called 232 full shifts half days
 * in July.
 */
export default function AttendanceInsightsCard({ range }: { range: DateRange }) {
    const insights = useQuery({
        queryKey: ['hrms', 'attendance', 'insights', range.from, range.to],
        queryFn: () => getAttendanceInsights(range.from, range.to),
    });
    const data = insights.data;

    return (
        <Card title="Insights" loading={insights.isFetching && !data}>
            {data && data.turnout.length === 0 ? (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nothing recorded in this period." />
            ) : (
                <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
                    <Typography.Text type="secondary">On the floor, day by day</Typography.Text>
                    {data ? <TurnoutChart days={data.turnout} /> : null}

                    {data ? <Hours hours={data.hours} /> : null}

                    <Space align="start" wrap size="large" style={{ width: '100%' }}>
                        <div style={{ minWidth: 320, flex: 1 }}>
                            <Typography.Text strong>Longest days</Typography.Text>
                            <Table
                                size="small"
                                rowKey="employee_id"
                                style={{ marginTop: 8 }}
                                dataSource={data?.longest_days ?? []}
                                pagination={false}
                                scroll={{ x: 'max-content' }}
                                locale={{
                                    emptyText: (
                                        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No day ran past ten hours." />
                                    ),
                                }}
                                columns={[
                                    { title: 'Code', dataIndex: 'employee_code', width: 100 },
                                    { title: 'Name', dataIndex: 'name' },
                                    { title: 'Department', dataIndex: 'department' },
                                    {
                                        title: 'Days over 10h',
                                        dataIndex: 'long_days',
                                        width: 130,
                                        render: (days: number) => <Tag color="orange">{days}</Tag>,
                                    },
                                    {
                                        title: 'On the clock',
                                        dataIndex: 'minutes',
                                        width: 130,
                                        render: (minutes: number) => hoursLabel(minutes),
                                    },
                                ]}
                            />
                        </div>

                        <div style={{ minWidth: 320, flex: 1 }}>
                            <Typography.Text strong>The report keeps failing on</Typography.Text>
                            <Table
                                size="small"
                                rowKey="employee_id"
                                style={{ marginTop: 8 }}
                                dataSource={data?.most_mismatched ?? []}
                                pagination={false}
                                scroll={{ x: 'max-content' }}
                                locale={{
                                    emptyText: (
                                        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="The report read cleanly." />
                                    ),
                                }}
                                columns={[
                                    { title: 'Code', dataIndex: 'employee_code', width: 100 },
                                    { title: 'Name', dataIndex: 'name' },
                                    { title: 'Department', dataIndex: 'department' },
                                    {
                                        title: 'Mismatches',
                                        dataIndex: 'mismatches',
                                        width: 120,
                                        render: (days: number) => <Tag color="volcano">{days}</Tag>,
                                    },
                                    {
                                        title: 'Still open',
                                        dataIndex: 'unanswered',
                                        width: 110,
                                        render: (days: number) => (days > 0 ? <Tag color="gold">{days}</Tag> : '—'),
                                    },
                                ]}
                            />
                        </div>
                    </Space>
                </Space>
            )}
        </Card>
    );
}
