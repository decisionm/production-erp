import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listAllItems, listAllWarehouses } from '@/features/inventory/api';
import { completeWorkOrder, createWorkOrder, listAllScrapReasons, listWorkOrders, releaseWorkOrder } from '@/features/production/api';
import type { WorkOrder, WorkOrderStatus } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

const createSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    scheduled_date: z.string().optional(),
    quantity_planned: z.number().gt(0, 'Quantity must be greater than 0'),
});
type CreateFormValues = z.infer<typeof createSchema>;

const scrapEntrySchema = z.object({
    scrap_reason_id: z.number({ error: 'Reason is required' }),
    quantity: z.number().gt(0, 'Quantity must be greater than 0'),
    notes: z.string().optional(),
});

const completeSchema = z.object({
    quantity_completed: z.number().gt(0, 'Quantity must be greater than 0'),
    batch_number: z.string().optional(),
    scrap: z.array(scrapEntrySchema).optional(),
});
type CompleteFormValues = z.infer<typeof completeSchema>;

const statusColor: Record<WorkOrderStatus, string> = {
    draft: 'default',
    released: 'blue',
    completed: 'green',
};

export default function WorkOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [completingRow, setCompletingRow] = useState<WorkOrder | null>(null);
    const [detailRow, setDetailRow] = useState<WorkOrder | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'work-orders'], queryFn: listWorkOrders });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });
    const { data: scrapReasons } = useQuery({ queryKey: ['production', 'scrap-reasons', 'all'], queryFn: listAllScrapReasons });

    const itemOptions = items?.data.map((i) => ({ value: i.id, label: itemLabel(i) })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];
    const scrapReasonOptions = scrapReasons?.data.map((r) => ({ value: r.id, label: `${r.code} — ${r.name}` })) ?? [];

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
    } = useForm<CompleteFormValues>({ resolver: zodResolver(completeSchema), defaultValues: { scrap: [] } });
    const { fields: scrapFields, append: appendScrap, remove: removeScrap } = useFieldArray({ control: completeControl, name: 'scrap' });

    const completeMutation = useMutation({
        mutationFn: ({ id, quantity, batchNumber, scrap }: {
            id: number;
            quantity: number;
            batchNumber?: string;
            scrap?: CompleteFormValues['scrap'];
        }) => completeWorkOrder(id, quantity, batchNumber, scrap),
        onSuccess: () => {
            invalidate();
            setCompletingRow(null);
            resetComplete({ scrap: [] });
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
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
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
                                <Button size="small" onClick={() => setDetailRow(row)}>
                                    View
                                </Button>
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
                                    <Button size="small" onClick={() => setCompletingRow(row)}>
                                        Complete
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
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
                maskClosable={false}
                title="Complete Work Order"
                open={completingRow !== null}
                onCancel={() => setCompletingRow(null)}
                onOk={handleCompleteSubmit((values) => {
                    if (completingRow !== null) {
                        completeMutation.mutate({
                            id: completingRow.id,
                            quantity: values.quantity_completed,
                            batchNumber: values.batch_number,
                            scrap: values.scrap,
                        });
                    }
                })}
                width={600}
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
                    {completingRow?.item.tracking_type === 'batch' && (
                        <Form.Item label="Batch Number (creates a new batch for this production run)">
                            <Controller
                                name="batch_number"
                                control={completeControl}
                                render={({ field }) => <Input {...field} placeholder="e.g. LOT-2026-07-20" />}
                            />
                        </Form.Item>
                    )}

                    <Typography.Text strong>Scrap (optional — makes yield loss explicit)</Typography.Text>
                    {scrapFields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <Controller
                                name={`scrap.${index}.scrap_reason_id`}
                                control={completeControl}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        options={scrapReasonOptions}
                                        showSearch
                                        optionFilterProp="label"
                                        style={{ width: 200 }}
                                        placeholder="Reason"
                                    />
                                )}
                            />
                            <Controller
                                name={`scrap.${index}.quantity`}
                                control={completeControl}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Quantity" />}
                            />
                            <Controller
                                name={`scrap.${index}.notes`}
                                control={completeControl}
                                render={({ field }) => <Input {...field} placeholder="Notes (optional)" style={{ width: 160 }} />}
                            />
                            <Button danger onClick={() => removeScrap(index)}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() => appendScrap({ scrap_reason_id: undefined as unknown as number, quantity: undefined as unknown as number })}
                    >
                        Add Scrap Entry
                    </Button>
                </Form>
            </Modal>

            <Drawer
                title={`Work Order #${detailRow?.id}`}
                open={detailRow !== null}
                onClose={() => setDetailRow(null)}
                width="min(100vw, 600px)"
                destroyOnHidden
            >
                {detailRow && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailRow.status]}>{detailRow.status}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Item">
                                {detailRow.item.sku} — {detailRow.item.name}
                            </Descriptions.Item>
                            <Descriptions.Item label="Warehouse">
                                {detailRow.warehouse.code} — {detailRow.warehouse.name}
                            </Descriptions.Item>
                            <Descriptions.Item label="Scheduled Date">{detailRow.scheduled_date ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Quantity Planned">{detailRow.quantity_planned}</Descriptions.Item>
                            <Descriptions.Item label="Quantity Completed">{detailRow.quantity_completed}</Descriptions.Item>
                            <Descriptions.Item label="Material Cost">{detailRow.material_cost}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Materials
                        </Typography.Title>
                        {detailRow.materials.length > 0 ? (
                            <Table
                                rowKey="id"
                                size="small"
                                pagination={false}
                                dataSource={detailRow.materials}
                                scroll={{ x: 'max-content' }}
                                columns={[
                                    {
                                        title: 'Component',
                                        render: (_, m) => itemLabel(m.component),
                                    },
                                    { title: 'Required', dataIndex: 'quantity_required' },
                                    { title: 'Issued', dataIndex: 'quantity_issued' },
                                ]}
                            />
                        ) : (
                            <Typography.Text type="secondary">No materials recorded.</Typography.Text>
                        )}

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Scrap
                        </Typography.Title>
                        {detailRow.scraps.length > 0 ? (
                            <Table
                                rowKey="id"
                                size="small"
                                pagination={false}
                                dataSource={detailRow.scraps}
                                scroll={{ x: 'max-content' }}
                                columns={[
                                    {
                                        title: 'Reason',
                                        render: (_, s) => `${s.reason.code} — ${s.reason.name}`,
                                    },
                                    { title: 'Quantity', dataIndex: 'quantity' },
                                    { title: 'Cost Impact', dataIndex: 'cost_impact' },
                                    { title: 'Notes', dataIndex: 'notes', render: (n: string | null) => n ?? '—' },
                                ]}
                            />
                        ) : (
                            <Typography.Text type="secondary">No scrap recorded.</Typography.Text>
                        )}
                    </>
                )}
            </Drawer>
        </>
    );
}
