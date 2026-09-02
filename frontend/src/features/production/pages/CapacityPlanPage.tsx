import { useQuery } from '@tanstack/react-query';
import { DatePicker, Space, Table, Tag, Typography } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { getCapacityLoadReport } from '@/features/production/api';
import type { CapacityDayLoad, CapacityWorkCenterLoad } from '@/features/production/types';
import { columnSorter } from '@/lib/clientSort';
import { TABLE_STICKY } from '@/lib/tableProps';

const { RangePicker } = DatePicker;

export default function CapacityPlanPage() {
    const [range, setRange] = useState<[Dayjs, Dayjs]>([dayjs(), dayjs().add(6, 'day')]);
    const startDate = range[0].format('YYYY-MM-DD');
    const endDate = range[1].format('YYYY-MM-DD');

    const { data, isLoading } = useQuery({
        queryKey: ['production', 'capacity-load-report', startDate, endDate],
        queryFn: () => getCapacityLoadReport(startDate, endDate),
    });

    const dates = data?.[0]?.days.map((d) => d.date) ?? [];

    return (
        <>
            <Typography.Title level={3}>Capacity Planning — Work Center Load</Typography.Title>
            <Typography.Paragraph type="secondary">
                Each draft or released work order with both a routing and a scheduled date charges its full
                routing time to that single day — this is a load-vs-capacity check, not a finite scheduler, so it
                won&apos;t spread a long job across multiple days or tell you how to sequence work.
            </Typography.Paragraph>

            <Space style={{ marginBottom: 16 }}>
                <RangePicker
                    value={range}
                    onChange={(values) => {
                        if (values && values[0] && values[1]) {
                            setRange([values[0], values[1]]);
                        }
                    }}
                />
            </Space>

            <Table<CapacityWorkCenterLoad>
                rowKey="work_center_id"
                sticky={TABLE_STICKY}
                loading={isLoading}
                dataSource={data}
                pagination={false}
                scroll={{ x: 'max-content' }}
                columns={[
                    {
                        title: 'Work Center',
                        fixed: 'left',
                        // The whole report is in the browser; sorted on the label the cell shows.
                        sorter: columnSorter((row: CapacityWorkCenterLoad) => `${row.work_center_code} — ${row.work_center_name}`, 'text'),
                        render: (_, row) => `${row.work_center_code} — ${row.work_center_name}`,
                    },
                    {
                        title: 'Capacity (hrs/day)',
                        sorter: columnSorter((row: CapacityWorkCenterLoad) => row.capacity_hours_per_day, 'number'),
                        render: (_, row) => row.capacity_hours_per_day ?? 'Not set',
                    },
                    ...dates.map((date, index) => ({
                        title: date,
                        key: date,
                        render: (_: unknown, row: CapacityWorkCenterLoad) => {
                            const day: CapacityDayLoad | undefined = row.days[index];
                            if (!day) return null;

                            return (
                                <Tag color={day.overloaded ? 'red' : day.utilization_percent !== null ? 'green' : 'default'}>
                                    {day.load_hours}h
                                    {day.utilization_percent !== null ? ` (${day.utilization_percent}%)` : ''}
                                </Tag>
                            );
                        },
                    })),
                ]}
            />
        </>
    );
}
