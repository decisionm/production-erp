import { useQuery } from '@tanstack/react-query';
import { Card, Empty, Progress, Space, Table, Tag, Typography } from 'antd';
import { getAttendanceSummary } from '@/features/hrms/api';
import type { DateRange } from '@/features/hrms/attendanceRange';
import type { AttendanceDepartmentRow, AttendanceSummary } from '@/features/hrms/types';

/** The factory's own line, so nobody adds up a column to get it. */
function FactoryLine({ totals }: { totals: AttendanceSummary['totals'] }) {
    const tiles: { label: string; value: string | number }[] = [
        { label: 'People', value: totals.employees },
        { label: 'Days recorded', value: totals.recorded },
        { label: 'Present', value: totals.present },
        { label: 'Half Day', value: totals.half_day },
        { label: 'Absent', value: totals.absent },
        { label: 'On Leave', value: totals.on_leave },
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
