import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Empty, Progress, Space, Table, Tag, Typography } from 'antd';
import { getAttendanceSummary } from '@/features/hrms/api';
import type { DateRange } from '@/features/hrms/attendanceRange';
import type { AttendanceDepartmentRow, AttendanceSummary } from '@/features/hrms/types';

/**
 * Says which uploads these numbers are partly read from, and that nobody
 * has applied them — a management screen that quietly totals provisional
 * days is worse than one that shows nothing.
 */
function provisionalLine(data: AttendanceSummary): string {
    const unapplied = data.imports.filter((source) => source.status === 'review');
    const named = unapplied.map((source) => source.file_name ?? `${source.period_from} to ${source.period_to}`).join(', ');
    const days = `${data.totals.from_import} ${data.totals.from_import === 1 ? 'day' : 'days'}`;
    const review = data.totals.needs_review > 0 ? ` ${data.totals.needs_review} still need an answer.` : '';

    return named === ''
        ? `${days} read from an attendance upload that has not been applied yet.${review}`
        : `${days} read from ${named}, not applied yet.${review}`;
}

/** The factory's own line, so nobody adds up a column to get it. */
function FactoryLine({ totals }: { totals: AttendanceSummary['totals'] }) {
    const tiles: { label: string; value: string | number }[] = [
        { label: 'People', value: totals.employees },
        { label: 'Days recorded', value: totals.recorded },
        { label: 'Present', value: totals.present },
        { label: 'Half Day', value: totals.half_day },
        { label: 'Absent', value: totals.absent },
        { label: 'On Leave', value: totals.on_leave },
        { label: 'Week Off', value: totals.week_off },
        { label: 'Needs review', value: totals.needs_review },
    ];

    return (
        <Space wrap size="middle">
            {tiles.map((tile) => (
                <div key={tile.label} style={{ minWidth: 96 }}>
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {tile.label}
                    </Typography.Text>
                    <div style={{ fontSize: 24, lineHeight: 1.2 }}>{tile.value}</div>
                </div>
            ))}
        </Space>
    );
}

/**
 * THE FACTORY BY DEPARTMENT — the management read.
 *
 * Rendered only for a login the server would answer: `hrms.manage`. The
 * page does not merely hide it — the endpoint refuses a `.view` login as
 * well, so what a supervisor cannot see they also cannot fetch.
 *
 * The percentage is present days over recorded days with a half day
 * counting as half, which is the server's definition and is stated on the
 * column so nobody has to guess at it.
 */
export default function DepartmentAttendanceCard({ range }: { range: DateRange }) {
    const summary = useQuery({
        queryKey: ['hrms', 'attendance', 'summary', range.from, range.to],
        queryFn: () => getAttendanceSummary(range.from, range.to),
    });
    const data = summary.data;

    return (
        <Card title="By department">
            <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
                {data && data.totals.from_import > 0 ? (
                    <Alert type="info" showIcon message={provisionalLine(data)} />
                ) : null}

                {data ? <FactoryLine totals={data.totals} /> : null}

                <Table<AttendanceDepartmentRow>
                    size="small"
                    rowKey="department"
                    loading={summary.isFetching}
                    dataSource={data?.departments ?? []}
                    pagination={false}
                    scroll={{ x: 'max-content' }}
                    locale={{
                        emptyText: (
                            <Empty
                                image={Empty.PRESENTED_IMAGE_SIMPLE}
                                description="No attendance recorded in this period."
                            />
                        ),
                    }}
                    columns={[
                        { title: 'Department', dataIndex: 'department' },
                        { title: 'People', dataIndex: 'employees', width: 90 },
                        { title: 'Present', dataIndex: 'present', width: 90 },
                        { title: 'Half Day', dataIndex: 'half_day', width: 90 },
                        { title: 'Absent', dataIndex: 'absent', width: 90 },
                        { title: 'On Leave', dataIndex: 'on_leave', width: 90 },
                        { title: 'Week Off', dataIndex: 'week_off', width: 90 },
                        {
                            title: 'Needs review',
                            dataIndex: 'needs_review',
                            width: 120,
                            render: (days: number) => (days > 0 ? <Tag color="gold">{days}</Tag> : '—'),
                        },
                        { title: 'Days', dataIndex: 'recorded', width: 80 },
                        {
                            title: 'Present %',
                            dataIndex: 'present_percent',
                            width: 160,
                            render: (percent: number) => (
                                <Progress
                                    percent={percent}
                                    size="small"
                                    format={(value) => `${value}%`}
                                    status={percent >= 90 ? 'success' : percent >= 75 ? 'normal' : 'exception'}
                                />
                            ),
                        },
                    ]}
                />

                {data && data.most_absent.length > 0 ? (
                    <div>
                        <Typography.Text strong>Most absent</Typography.Text>
                        <Table
                            size="small"
                            rowKey="employee_id"
                            style={{ marginTop: 8 }}
                            dataSource={data.most_absent}
                            pagination={false}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                { title: 'Code', dataIndex: 'employee_code', width: 110 },
                                { title: 'Name', dataIndex: 'name' },
                                { title: 'Department', dataIndex: 'department' },
                                {
                                    title: 'Absent',
                                    dataIndex: 'absent',
                                    width: 110,
                                    render: (days: number) => <Tag color="red">{`${days} ${days === 1 ? 'day' : 'days'}`}</Tag>,
                                },
                            ]}
                        />
                    </div>
                ) : null}
            </Space>
        </Card>
    );
}
