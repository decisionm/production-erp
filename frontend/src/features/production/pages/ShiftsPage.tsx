import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, Modal, Space, Switch, Table, Tag, TimePicker, Typography } from 'antd';
import dayjs from 'dayjs';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createShift, listShifts } from '@/features/production/api';
import type { Shift } from '@/features/production/types';

const shiftSchema = z.object({
    name: z.string().min(1, 'Name is required').max(64),
    start_time: z.string().min(1, 'Start time is required'),
    end_time: z.string().min(1, 'End time is required'),
});
type ShiftFormValues = z.infer<typeof shiftSchema>;

export default function ShiftsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'shifts'], queryFn: () => listShifts() });

    // Running shifts first, then retired, each still in start-time order as the
    // API returns them.
    const rows = [
        ...(data?.data.filter((shift) => shift.is_active) ?? []),
        ...(data?.data.filter((shift) => !shift.is_active) ?? []),
    ];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<ShiftFormValues>({
        resolver: zodResolver(shiftSchema),
    });

    const mutation = useMutation({
        mutationFn: createShift,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['production', 'shifts'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create shift', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Shifts</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Shift</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                Which shift a production entry was made in. Retired shifts stay listed because
                past entries point at them, but only the running ones are offered on the floor.
            </Typography.Paragraph>

            <Table<Shift>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                // Running shifts first. A retired row sorted in between two live
                // ones reads as a fourth shift — which is exactly the complaint
                // that got the duplicates merged: "THERE IS NOT NIGHT, SHIFT A TO C".
                dataSource={rows}
                pagination={false}
                columns={[
                    {
                        title: 'Name',
                        dataIndex: 'name',
                        render: (name: string, shift) =>
                            shift.is_active ? name : <Typography.Text type="secondary" delete>{name}</Typography.Text>,
                    },
                    { title: 'Start Time', dataIndex: 'start_time' },
                    { title: 'End Time', dataIndex: 'end_time' },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        // The tag says the word. A switch that is merely off does not
                        // tell a supervisor whether the shift is gone or just paused.
                        render: (active: boolean) =>
                            active
                                ? <Switch checked disabled size="small" />
                                : <Tag>Retired</Tag>,
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Shift"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller
                            name="name"
                            control={control}
                            render={({ field }) => <Input {...field} placeholder="e.g. Morning" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Start Time"
                        validateStatus={errors.start_time ? 'error' : ''}
                        help={errors.start_time?.message}
                    >
                        <Controller
                            name="start_time"
                            control={control}
                            render={({ field }) => (
                                <TimePicker
                                    style={{ width: '100%' }}
                                    format="HH:mm"
                                    value={field.value ? dayjs(field.value, 'HH:mm') : undefined}
                                    onChange={(value) => field.onChange(value ? value.format('HH:mm') : undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="End Time"
                        validateStatus={errors.end_time ? 'error' : ''}
                        help={errors.end_time?.message}
                    >
                        <Controller
                            name="end_time"
                            control={control}
                            render={({ field }) => (
                                <TimePicker
                                    style={{ width: '100%' }}
                                    format="HH:mm"
                                    value={field.value ? dayjs(field.value, 'HH:mm') : undefined}
                                    onChange={(value) => field.onChange(value ? value.format('HH:mm') : undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
