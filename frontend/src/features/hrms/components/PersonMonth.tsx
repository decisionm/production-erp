import { Alert, Empty, Space, Table, Tag, Typography } from 'antd';
import dayjs from 'dayjs';
import { dayLabel } from '@/features/hrms/attendanceReview';
import type { AttendancePersonRange, AttendanceStatus, AttendanceTally } from '@/features/hrms/types';
import AttendanceChart from './AttendanceChart';

const STATUS_LABELS: Record<AttendanceStatus | 'week_off', string> = {
    present: 'Present',
    absent: 'Absent',
    half_day: 'Half Day',
    on_leave: 'On Leave',
    week_off: 'Week Off',
};

const STATUS_COLORS: Record<AttendanceStatus | 'week_off', string> = {
    present: 'green',
    absent: 'red',
    half_day: 'orange',
    on_leave: 'blue',
    week_off: 'default',
};

const clock = (value: string | null): string => (value ? dayjs(value).format('HH:mm') : '—');

/**
 * Says the numbers are read from an upload nobody has applied, so nobody
 * treats them as the final word on somebody's month.
 */
export function provisionalLine(summary: AttendanceTally): string {
    const days = `${summary.from_import} ${summary.from_import === 1 ? 'day' : 'days'}`;
    const review = summary.needs_review > 0 ? ` ${summary.needs_review} still need an answer.` : '';

    return `${days} read from an attendance upload that has not been applied yet.${review}`;
}

/** The counts as tiles rather than a sentence to parse. */
export function Tally({ summary }: { summary: AttendanceTally }) {
    const tiles: { label: string; value: number; color?: string }[] = [
        { label: 'Present', value: summary.present, color: '#2e7d32' },
        { label: 'Half Day', value: summary.half_day, color: '#ef6c00' },
        { label: 'Absent', value: summary.absent, color: '#c62828' },
        { label: 'On Leave', value: summary.on_leave, color: '#1565c0' },
        { label: 'Week Off', value: summary.week_off },
        { label: 'Days recorded', value: summary.recorded },
    ];

    if (summary.needs_review > 0) {
        tiles.splice(4, 0, { label: 'Needs review', value: summary.needs_review, color: '#b45309' });
    }

    // A MISMATCH IS NOT A NEEDS-REVIEW. It is what the punch report could
    // not make sense of, answered or not, so the month's figure keeps
    // counting a day somebody has already settled — otherwise this card and
    // the import review page would disagree about the same month.
    if (summary.mismatches > 0) {
        tiles.push({ label: 'Mismatches', value: summary.mismatches, color: '#b45309' });
    }

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
 * ONE PERSON'S MONTH: who they are, the totals, the shape of it, the days.
 *
 * Shared by the card HR uses to look somebody up and the card a person sees
 * of their own month, so the two can never come to disagree about what a
 * month looks like — which is the disagreement that would matter most,
 * since one of them is the person being paid.
 */
export default function PersonMonth({
    data,
    loading,
    emptyText,
}: {
    data: AttendancePersonRange | null;
    loading: boolean;
    emptyText: string;
}) {
    return (
        <Space orientation="vertical" size="middle" style={{ width: '100%' }}>
            <Space wrap>
                <Typography.Text strong>{data?.employee.name}</Typography.Text>
                <Typography.Text type="secondary">
                    {[data?.employee.department, data?.employee.designation].filter(Boolean).join(' · ')}
                </Typography.Text>
            </Space>

            {data && data.summary.from_import > 0 ? (
                <Alert type="info" showIcon message={provisionalLine(data.summary)} />
            ) : null}

            {data ? <Tally summary={data.summary} /> : null}
            {data ? <AttendanceChart summary={data.summary} title={`${data.employee.name}: the month in days`} /> : null}

            <Table
                size="small"
                rowKey="date"
                loading={loading}
                dataSource={data?.days ?? []}
                pagination={false}
                scroll={{ x: 'max-content', y: 320 }}
                locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} /> }}
                columns={[
                    { title: 'Day', dataIndex: 'date', render: (date: string) => dayLabel(date) },
                    {
                        title: 'Counts as',
                        render: (_, row) =>
                            row.status === null ? (
                                // Not present, not absent — nobody has answered
                                // it yet, and the screen does not get to pick
                                // either.
                                <Tag color="gold">Needs review</Tag>
                            ) : (
                                <Tag color={STATUS_COLORS[row.status]}>{STATUS_LABELS[row.status]}</Tag>
                            ),
                    },
                    { title: 'In', render: (_, row) => clock(row.check_in) },
                    { title: 'Out', render: (_, row) => clock(row.check_out) },
                    { title: 'Note', dataIndex: 'notes' },
                ]}
            />
        </Space>
    );
}
