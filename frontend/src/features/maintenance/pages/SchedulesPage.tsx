import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
import { createMaintenanceSchedule, generateDueWorkOrders, listAllAssets, listMaintenanceSchedules } from '@/features/maintenance/api';
import {
    SCHEDULE_DEFAULT_SORT,
    SCHEDULE_LIST_SPEC,
    SCHEDULE_SORT_FIELDS,
    type ScheduleListParams,
    scheduleServerFilters,
    schedulesQueryKey,
} from '@/features/maintenance/scheduleList';
import type { MaintenanceSchedule } from '@/features/maintenance/types';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const scheduleSchema = z.object({
    asset_id: z.number({ error: 'Asset is required' }),
    name: z.string().min(1, 'Name is required').max(255),
    frequency_days: z.number().min(1, 'Frequency must be at least 1 day'),
    next_due_date: z.string({ error: 'Next due date is required' }),
});
type ScheduleFormValues = z.infer<typeof scheduleSchema>;

export default function SchedulesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    // THE LIST'S VIEW IS ITS URL (useListParams): sort, page, page size and
    // the asset deep link, all answered by the SERVER over the whole list.
    const { params, setParams, setPage } = useListParams<ScheduleListParams>(SCHEDULE_LIST_SPEC);
    const filters = useMemo(() => scheduleServerFilters(params), [params]);
    const { data, isLoading } = useQuery({
        queryKey: schedulesQueryKey(filters),
        queryFn: () => listMaintenanceSchedules(filters),
        placeholderData: (previous) => previous,
    });
    const { data: assets } = useQuery({ queryKey: ['maintenance', 'assets', 'all'], queryFn: listAllAssets });
    // WS-B: a RETIRED asset takes no new maintenance work. `under_maintenance`
    // deliberately stays selectable — an asset being worked on is exactly the
    // asset maintenance is planned for, and the server says the same.
    const assetOptions = activePickerOptions(assets?.data, {
        isActive: (a) => a.status !== 'retired',
        option: (a) => ({ value: a.id, label: `${a.code} — ${a.name}` }),
    });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ScheduleFormValues>({
        resolver: zodResolver(scheduleSchema),
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
        queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
    };

    const createMutation = useMutation({
        mutationFn: createMaintenanceSchedule,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create schedule', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const generateMutation = useMutation({
        mutationFn: generateDueWorkOrders,
        onSuccess: (created) => {
            invalidate();
            Modal.success({
                title: 'Preventive maintenance work orders generated',
                content: created.length === 0
                    ? 'No schedules are due right now.'
                    : `Created ${created.length} work order(s) for: ${created.map((wo) => wo.asset.name).join(', ')}.`,
            });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not generate work orders', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Maintenance Schedules</Typography.Title>
                <Space>
                    <Button onClick={() => generateMutation.mutate()} loading={generateMutation.isPending}>
                        Generate Due Work Orders
                    </Button>
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Schedule</Button>
                </Space>
            </Space>

            <Table<MaintenanceSchedule>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: every sorter is sortOrder-controlled
                // and re-queries; the list is paginated, so sorting the
                // loaded page would misorder the whole result set.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, SCHEDULE_SORT_FIELDS, SCHEDULE_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'schedules')}
                columns={[
                    // The asset column shows the relation's code and name, not
                    // the foreign key — no server column to sort it on.
                    { title: 'Asset', render: (_, row) => `${row.asset.code} — ${row.asset.name}` },
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        key: 'name',
                        sorter: true,
                        sortOrder: columnSortOrder('name', params.sort, SCHEDULE_DEFAULT_SORT),
                    },
                    {
                        title: 'Frequency (days)',
                        dataIndex: 'frequency_days',
                        key: 'frequency_days',
                        sorter: true,
                        sortOrder: columnSortOrder('frequency_days', params.sort, SCHEDULE_DEFAULT_SORT),
                    },
                    {
                        title: 'Next Due',
                        dataIndex: 'next_due_date',
                        key: 'next_due_date',
                        sorter: true,
                        sortOrder: columnSortOrder('next_due_date', params.sort, SCHEDULE_DEFAULT_SORT),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Maintenance Schedule"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Asset" validateStatus={errors.asset_id ? 'error' : ''} help={errors.asset_id?.message}>
                        <Controller
                            name="asset_id"
                            control={control}
                            render={({ field }) => <Select {...field} options={assetOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item
                        label="Frequency (days)"
                        validateStatus={errors.frequency_days ? 'error' : ''}
                        help={errors.frequency_days?.message}
                    >
                        <Controller
                            name="frequency_days"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={1} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Next Due Date"
                        validateStatus={errors.next_due_date ? 'error' : ''}
                        help={errors.next_due_date?.message}
                    >
                        <Controller
                            name="next_due_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
