import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems, listWarehouses } from '@/features/inventory/api';
import {
    completeReworkOrder,
    createReworkOrder,
    listBoms,
    listReworkOrders,
    listWorkOrders,
    releaseReworkOrder,
} from '@/features/production/api';
import type { ReworkOrder, ReworkOrderStatus } from '@/features/production/types';

const createSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    source_work_order_id: z.number().optional(),
    bom_id: z.number().optional(),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    quantity_input: z.number().gt(0, 'Quantity must be greater than 0'),
});
type CreateFormValues = z.infer<typeof createSchema>;

const completeSchema = z.object({
    quantity_recovered: z.number().gt(0, 'Quantity must be greater than 0'),
    labor_cost: z.number().min(0),
});
type CompleteFormValues = z.infer<typeof completeSchema>;

const statusColor: Record<ReworkOrderStatus, string> = {
    draft: 'default',
    released: 'blue',
    completed: 'green',
};

export default function ReworkOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [completingId, setCompletingId] = useState<number | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'rework-orders'], queryFn: listReworkOrders });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });
    const { data: workOrders } = useQuery({ queryKey: ['production', 'work-orders'], queryFn: listWorkOrders });
    const { data: boms } = useQuery({ queryKey: ['production', 'boms'], queryFn: () => listBoms() });

    const itemOptions = items?.data.map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];
    const workOrderOptions = workOrders?.data.map((wo) => ({ value: wo.id, label: `WO #${wo.id} — ${wo.item.sku}` })) ?? [];
    const bomOptions = boms?.data.map((b) => ({ value: b.id, label: `${b.item.sku} — ${b.name}` })) ?? [];

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'rework-orders'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CreateFormValues>({
        resolver: zodResolver(createSchema),
    });

    const createMutation = useMutation({
        mutationFn: createReworkOrder,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create rework order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const releaseMutation = useMutation({
        mutationFn: releaseReworkOrder,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not release rework order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: completeControl,
        handleSubmit: handleCompleteSubmit,
        reset: resetComplete,
        formState: { errors: completeErrors },
    } = useForm<CompleteFormValues>({ resolver: zodResolver(completeSchema) });

    const completeMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & CompleteFormValues) => completeReworkOrder(id, payload),
        onSuccess: () => {
            invalidate();
            setCompletingId(null);
            resetComplete();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not complete rework order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Rework Orders</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Rework Order</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                Recovers defective output back to good stock instead of discarding it. The bill of materials is
                optional — a pure re-inspection/relabeling rework needs no extra materials at all.
            </Typography.Paragraph>

            <Table<ReworkOrder>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Source WO', render: (_, row) => row.source_work_order_id ?? '—' },
                    { title: 'Input Qty', dataIndex: 'quantity_input' },
                    { title: 'Recovered', dataIndex: 'quantity_recovered' },
                    { title: 'Material Cost', dataIndex: 'material_cost' },
                    { title: 'Labor Cost', dataIndex: 'labor_cost' },
                    { title: 'Total Cost', dataIndex: 'total_cost' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: ReworkOrderStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
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
                                    <Button size="small" onClick={() => setCompletingId(row.id)}>Complete</Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                title="New Rework Order"
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
                    <Form.Item label="Source Work Order (optional, for traceability)">
                        <Controller
                            name="source_work_order_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={workOrderOptions} showSearch optionFilterProp="label" allowClear />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Rework BOM (optional — leave blank for pure labor rework)">
                        <Controller
                            name="bom_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={bomOptions} showSearch optionFilterProp="label" allowClear />
                            )}
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
                        label="Quantity Input (defective units being reworked)"
                        validateStatus={errors.quantity_input ? 'error' : ''}
                        help={errors.quantity_input?.message}
                    >
                        <Controller
                            name="quantity_input"
                            control={control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title="Complete Rework Order"
                open={completingId !== null}
                onCancel={() => setCompletingId(null)}
                onOk={handleCompleteSubmit((values) => {
                    if (completingId !== null) completeMutation.mutate({ id: completingId, ...values });
                })}
                confirmLoading={completeMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Quantity Recovered (good units)"
                        validateStatus={completeErrors.quantity_recovered ? 'error' : ''}
                        help={completeErrors.quantity_recovered?.message}
                    >
                        <Controller
                            name="quantity_recovered"
                            control={completeControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Labor Cost"
                        validateStatus={completeErrors.labor_cost ? 'error' : ''}
                        help={completeErrors.labor_cost?.message}
                    >
                        <Controller
                            name="labor_cost"
                            control={completeControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
