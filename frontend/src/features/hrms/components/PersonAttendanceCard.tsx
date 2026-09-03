import { PrinterOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { Button, Card, Empty, Select, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { downloadAttendanceSheet, getAttendancePerson, listAllEmployees } from '@/features/hrms/api';
import type { DateRange } from '@/features/hrms/attendanceRange';
import { dayLabel } from '@/features/hrms/attendanceReview';
import type { AttendanceStatus, AttendanceTally } from '@/features/hrms/types';
import { downloadBlob } from '@/lib/csv';
import { showApiError } from '@/lib/showApiError';

const STATUS_LABELS: Record<AttendanceStatus, string> = {
    present: 'Present',
    absent: 'Absent',
    half_day: 'Half Day',
    on_leave: 'On Leave',
};

const STATUS_COLORS: Record<AttendanceStatus, string> = {
    present: 'green',
    absent: 'red',
    half_day: 'orange',
    on_leave: 'blue',
};

const clock = (value: string | null): string => (value ? dayjs(value).format('HH:mm') : '—');

/** The four counts and the total, as tiles rather than a sentence to parse. */
function Tally({ summary }: { summary: AttendanceTally }) {
    const tiles: { label: string; value: number; color?: string }[] = [
        { label: 'Present', value: summary.present, color: '#2e7d32' },
        { label: 'Half Day', value: summary.half_day, color: '#ef6c00' },
        { label: 'Absent', value: summary.absent, color: '#c62828' },
        { label: 'On Leave', value: summary.on_leave, color: '#1565c0' },
        { label: 'Days recorded', value: summary.recorded },
    ];

    return (
        <Space wrap size="middle">
            {tiles.map((tile) => (
                <div key={tile.label} style={{ minWidth: 96 }}>
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
 * ONE PERSON'S ATTENDANCE — pick somebody, see their period.
 *
 * A DROPDOWN rather than the logged-in user: the 65 factory staff have no
 * logins, so "my own attendance" would be blank for almost everyone who
 * matters. HR and the supervisors are who open this, and they are always
 * looking somebody ELSE up.
 *
 * It opens on the first name in the list rather than on an empty card, so a
 * page somebody opened to read attendance has attendance in it.
 */
export default function PersonAttendanceCard({ range }: { range: DateRange }) {
    const [chosen, setChosen] = useState<number | null>(null);

    const employees = useQuery({ queryKey: ['hrms', 'employees', 'all'], queryFn: listAllEmployees });

    const options = useMemo(
        () =>
            (employees.data?.data ?? [])
                .filter((employee) => employee.status === 'active')
                .map((employee) => ({ value: employee.id, label: `${employee.employee_code} — ${employee.name}` })),
        [employees.data],
    );

    const employeeId = chosen ?? options[0]?.value ?? null;

    const person = useQuery({
        queryKey: ['hrms', 'attendance', 'person', employeeId, range.from, range.to],
        queryFn: () => getAttendancePerson(employeeId as number, range.from, range.to),
        enabled: employeeId !== null,
    });
    const data = person.data;

    // The sheet is what the floor actually corrects on, so printing is a
    // button beside the person rather than something buried in Downloads.
    const [printing, setPrinting] = useState(false);
    const print = async () => {
        if (employeeId === null) return;
        setPrinting(true);
        try {
            const file = await downloadAttendanceSheet(employeeId, range.from, range.to);
            const code = data?.employee.employee_code ?? employeeId;
            downloadBlob(`attendance-${code}-${range.from}-to-${range.to}.pdf`, file.blob);
        } catch (error) {
            showApiError(error, 'Could not print the sheet');
        } finally {
            setPrinting(false);
        }
    };

    return (
        <Card
            title="One person"
            extra={
                <Space wrap>
                    <Button
                        icon={<PrinterOutlined />}
                        disabled={employeeId === null}
                        loading={printing}
                        onClick={print}
                    >
                        Print sheet
                    </Button>
                    <Select
                        showSearch
                        loading={employees.isFetching}
                        style={{ minWidth: 280 }}
                        placeholder="Pick an employee"
                        optionFilterProp="label"
                        value={employeeId ?? undefined}
                        options={options}
                        onChange={setChosen}
                    />
                </Space>
            }
        >
            {employeeId === null ? (
                <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description={employees.isFetching ? 'Loading employees…' : 'No active employees.'}
                />
            ) : (
                <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
                    <Space wrap>
                        <Typography.Text strong>{data?.employee.name}</Typography.Text>
                        <Typography.Text type="secondary">
                            {[data?.employee.department, data?.employee.designation].filter(Boolean).join(' · ')}
                        </Typography.Text>
                    </Space>

                    {data ? <Tally summary={data.summary} /> : null}

                    <Table
                        size="small"
                        rowKey="id"
                        loading={person.isFetching}
                        dataSource={data?.days ?? []}
                        pagination={false}
                        scroll={{ x: 'max-content', y: 320 }}
                        locale={{
                            emptyText: (
                                <Empty
                                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                                    description="Nothing recorded for this person in this period."
                                />
                            ),
                        }}
                        columns={[
                            { title: 'Day', dataIndex: 'date', render: (date: string) => dayLabel(date) },
                            {
                                title: 'Counts as',
                                dataIndex: 'status',
                                render: (status: AttendanceStatus) => (
                                    <Tag color={STATUS_COLORS[status]}>{STATUS_LABELS[status]}</Tag>
                                ),
                            },
                            { title: 'In', render: (_, row) => clock(row.check_in) },
                            { title: 'Out', render: (_, row) => clock(row.check_out) },
                            { title: 'Note', dataIndex: 'notes' },
                        ]}
                    />
                </Space>
            )}
        </Card>
    );
}
