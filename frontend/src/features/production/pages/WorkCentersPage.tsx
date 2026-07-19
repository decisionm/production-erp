import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Space, Switch, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createWorkCenter, listWorkCenters, updateWorkCenter } from '@/features/production/api';
import type { WorkCenter } from '@/features/production/types';

const workCenterSchema = z.object({
    code: z.string().min(1, 'Code is required').max(32),
    name: z.string().min(1, 'Name is required').max(255),
    capacity_hours_per_day: z.number().min(0).optional(),
});
type WorkCenterFormValues = z.infer<typeof workCenterSchema>;

export default function WorkCentersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'work-centers'], queryFn: listWorkCenters });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<WorkCenterFormValues>({
        resolver: zodResolver(workCenterSchema),
        defaultValues: { code: '', name: '' },
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'work-centers'] });

    const mutation = useMutation({
        mutationFn: createWorkCenter,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create work center', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const capacityMutation = useMutation({
        mutationFn: ({ id, capacity }: { id: number; capacity: number | null }) =>
            updateWorkCenter(id, { capacity_hours_per_day: capacity ?? undefined }),
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not update capacity', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Work Centers</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Work Center</Button>
            </Space>

            <Table<WorkCenter>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Code', dataIndex: 'code' },
                    { title: 'Name', dataIndex: 'name' },
                    {
                        title: 'Capacity (hrs/day)',
                        dataIndex: 'capacity_hours_per_day',
                        render: (capacity: string | null, row) => (
                            <InputNumber
                                min={0}
                                defaultValue={capacity !== null ? Number(capacity) : undefined}
                                placeholder="Not set"
                                size="small"
                                onPressEnter={(e) => {
                                    const value = (e.target as HTMLInputElement).value;
                                    capacityMutation.mutate({ id: row.id, capacity: value === '' ? null : Number(value) });
                                }}
                                onBlur={(e) => {
                                    const value = e.target.value;
                                    capacityMutation.mutate({ id: row.id, capacity: value === '' ? null : Number(value) });
                                }}
                            />
                        ),
                    },
                    {
                        title: 'Active',
                        dataIndex: 'is_active',
                        render: (active: boolean) => <Switch checked={active} disabled size="small" />,
                    },
                ]}
            />

            <Modal
                title="New Work Center"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Code" validateStatus={errors.code ? 'error' : ''} help={errors.code?.message}>
                        <Controller name="code" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Name" validateStatus={errors.name ? 'error' : ''} help={errors.name?.message}>
                        <Controller name="name" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Capacity (hours/day, optional)">
                        <Controller
                            name="capacity_hours_per_day"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
