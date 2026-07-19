import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems, listWarehouses } from '@/features/inventory/api';
import { completeWorkOrder, createWorkOrder, listWorkOrders, releaseWorkOrder } from '@/features/production/api';
import type { WorkOrder, WorkOrderStatus } from '@/features/production/types';

const createSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    scheduled_date: z.string().optional(),
    quantity_planned: z.number().gt(0, 'Quantity must be greater than 0'),
});
type CreateFormValues = z.infer<typeof createSchema>;

const completeSchema = z.object({
    quantity_completed: z.number().gt(0, 'Quantity must be greater than 0'),
});
type CompleteFormValues = z.infer<typeof completeSchema>;

const statusColor: Record<WorkOrderStatus, string> = {
    draft: 'default',
    released: 'blue',
    completed: 'green',
};

export default function WorkOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [completingId, setCompletingId] = useState<number | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'work-orders'], queryFn: listWorkOrders });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });

    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'work-orders'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CreateFormValues>({
        resolver: zodResolver(createSchema),
    });

    const createMutation = useMutation({
        mutationFn: createWorkOrder,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create work order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const releaseMutation = useMutation({
        mutationFn: releaseWorkOrder,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not release work order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: completeControl,
        handleSubmit: handleCompleteSubmit,
        reset: resetComplete,
        formState: { errors: completeErrors },
    } = useForm<CompleteFormValues>({ resolver: zodResolver(completeSchema) });

    const completeMutation = useMutation({
        mutationFn: ({ id, quantity }: { id: number; quantity: number }) => completeWorkOrder(id, quantity),
        onSuccess: () => {
            invalidate();
            setCompletingId(null);
            resetComplete();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not complete work order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Work Orders</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Work Order</Button>
            </Space>

            <Table<WorkOrder>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Warehouse', render: (_, row) => row.warehouse.code },
                    { title: 'Scheduled', dataIndex: 'scheduled_date' },
                    { title: 'Planned', dataIndex: 'quantity_planned' },
                    { title: 'Completed', dataIndex: 'quantity_completed' },
                    { title: 'Material Cost', dataIndex: 'material_cost' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: WorkOrderStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                {row.status === 'draft' && (
                                    <Button
                                        size="small"
                                        onClick={() => releaseMutation.mutate(row.id)}
                                        loading={releaseMutation.isPending}
                                    >
                                        Release
                                    </Button>
                                )}
                                {row.status === 'released' && (
                                    <Button size="small" onClick={() => setCompletingId(row.id)}>
                                        Complete
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                title="New Work Order"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Item" validateStatus={errors.item_id ? 'error' : ''} help={errors.item_id?.message}>
                        <Controller
                            name="item_id"
                            control={control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Warehouse"
                        validateStatus={errors.warehouse_id ? 'error' : ''}
                        help={errors.warehouse_id?.message}
                    >
                        <Controller
                            name="warehouse_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={warehouseOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Quantity Planned"
                        validateStatus={errors.quantity_planned ? 'error' : ''}
                        help={errors.quantity_planned?.message}
                    >
                        <Controller
                            name="quantity_planned"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Scheduled Date (optional, for capacity planning)">
                        <Controller
                            name="scheduled_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Typography.Text type="secondary">
                        The item&apos;s active BOM is used automatically to determine required materials.
                    </Typography.Text>
                </Form>
            </Modal>

            <Modal
                title="Complete Work Order"
                open={completingId !== null}
                onCancel={() => setCompletingId(null)}
                onOk={handleCompleteSubmit((values) => {
                    if (completingId !== null) {
                        completeMutation.mutate({ id: completingId, quantity: values.quantity_completed });
                    }
                })}
                confirmLoading={completeMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Quantity Completed"
                        validateStatus={completeErrors.quantity_completed ? 'error' : ''}
                        help={completeErrors.quantity_completed?.message}
                    >
                        <Controller
                            name="quantity_completed"
                            control={completeControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
