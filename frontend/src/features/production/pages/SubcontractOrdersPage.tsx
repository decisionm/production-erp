import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Descriptions, Drawer, Form, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems, listWarehouses } from '@/features/inventory/api';
import { listVendors } from '@/features/procurement/api';
import {
    createSubcontractOrder,
    listSubcontractOrders,
    receiveSubcontractOrder,
    sendSubcontractOrderMaterials,
} from '@/features/production/api';
import type { SubcontractOrder, SubcontractOrderStatus } from '@/features/production/types';
import { itemLabel } from '@/lib/itemLabel';

const createSchema = z.object({
    vendor_id: z.number({ error: 'Vendor is required' }),
    item_id: z.number({ error: 'Item is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    quantity_planned: z.number().gt(0, 'Quantity must be greater than 0'),
});
type CreateFormValues = z.infer<typeof createSchema>;

const receiveSchema = z.object({
    quantity_received: z.number().gt(0, 'Quantity must be greater than 0'),
    service_cost: z.number().min(0),
});
type ReceiveFormValues = z.infer<typeof receiveSchema>;

const statusColor: Record<SubcontractOrderStatus, string> = {
    draft: 'default',
    materials_sent: 'blue',
    completed: 'green',
};

export default function SubcontractOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [receivingId, setReceivingId] = useState<number | null>(null);
    const [detailOrder, setDetailOrder] = useState<SubcontractOrder | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['production', 'subcontract-orders'], queryFn: listSubcontractOrders });
    const { data: vendors } = useQuery({ queryKey: ['procurement', 'vendors'], queryFn: listVendors });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });

    const vendorOptions = vendors?.data.map((v) => ({ value: v.id, label: `${v.code} — ${v.name}` })) ?? [];
    const itemOptions = items?.data.map((i) => ({ value: i.id, label: itemLabel(i) })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['production', 'subcontract-orders'] });

    const { control, handleSubmit, reset, formState: { errors } } = useForm<CreateFormValues>({
        resolver: zodResolver(createSchema),
    });

    const createMutation = useMutation({
        mutationFn: createSubcontractOrder,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create subcontract order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const sendMutation = useMutation({
        mutationFn: sendSubcontractOrderMaterials,
        onSuccess: invalidate,
        onError: (error: any) => {
            Modal.error({ title: 'Could not send materials', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const {
        control: receiveControl,
        handleSubmit: handleReceiveSubmit,
        reset: resetReceive,
        formState: { errors: receiveErrors },
    } = useForm<ReceiveFormValues>({ resolver: zodResolver(receiveSchema) });

    const receiveMutation = useMutation({
        mutationFn: ({ id, ...payload }: { id: number } & ReceiveFormValues) => receiveSubcontractOrder(id, payload),
        onSuccess: () => {
            invalidate();
            setReceivingId(null);
            resetReceive();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not receive order', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Subcontract Orders</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Subcontract Order</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                Job work: materials are issued out to a vendor for outside processing, then the finished item is
                received back at a cost that includes both the materials sent and the vendor&apos;s service fee.
            </Typography.Paragraph>

            <Table<SubcontractOrder>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Vendor', render: (_, row) => row.vendor.name },
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
                    { title: 'Planned', dataIndex: 'quantity_planned' },
                    { title: 'Received', dataIndex: 'quantity_received' },
                    { title: 'Materials Cost', dataIndex: 'materials_cost' },
                    { title: 'Service Cost', dataIndex: 'service_cost' },
                    { title: 'Total Cost', dataIndex: 'total_cost' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: SubcontractOrderStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailOrder(row)}>
                                    View
                                </Button>
                                {row.status === 'draft' && (
                                    <Button size="small" onClick={() => sendMutation.mutate(row.id)} loading={sendMutation.isPending}>
                                        Send Materials
                                    </Button>
                                )}
                                {row.status === 'materials_sent' && (
                                    <Button size="small" onClick={() => setReceivingId(row.id)}>Receive</Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Subcontract Order"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Vendor" validateStatus={errors.vendor_id ? 'error' : ''} help={errors.vendor_id?.message}>
                        <Controller
                            name="vendor_id"
                            control={control}
                            render={({ field }) => <Select {...field} options={vendorOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
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
                    <Typography.Text type="secondary">
                        The item&apos;s active BOM determines what materials get sent out to the vendor.
                    </Typography.Text>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title="Receive from Subcontractor"
                open={receivingId !== null}
                onCancel={() => setReceivingId(null)}
                onOk={handleReceiveSubmit((values) => {
                    if (receivingId !== null) receiveMutation.mutate({ id: receivingId, ...values });
                })}
                confirmLoading={receiveMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Quantity Received"
                        validateStatus={receiveErrors.quantity_received ? 'error' : ''}
                        help={receiveErrors.quantity_received?.message}
                    >
                        <Controller
                            name="quantity_received"
                            control={receiveControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Service Cost (subcontractor's fee)"
                        validateStatus={receiveErrors.service_cost ? 'error' : ''}
                        help={receiveErrors.service_cost?.message}
                    >
                        <Controller
                            name="service_cost"
                            control={receiveControl}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Drawer
                title={`Subcontract Order #${detailOrder?.id}`}
                open={detailOrder !== null}
                onClose={() => setDetailOrder(null)}
                width="min(100vw, 560px)"
                destroyOnHidden
            >
                {detailOrder && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailOrder.status]}>{detailOrder.status}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Vendor">{detailOrder.vendor.name}</Descriptions.Item>
                            <Descriptions.Item label="Item">
                                {detailOrder.item.sku} — {detailOrder.item.name}
                            </Descriptions.Item>
                            <Descriptions.Item label="Warehouse">
                                {detailOrder.warehouse.code} — {detailOrder.warehouse.name}
                            </Descriptions.Item>
                            <Descriptions.Item label="Quantity Planned">{detailOrder.quantity_planned}</Descriptions.Item>
                            <Descriptions.Item label="Quantity Received">{detailOrder.quantity_received}</Descriptions.Item>
                            <Descriptions.Item label="Materials Cost">{detailOrder.materials_cost}</Descriptions.Item>
                            <Descriptions.Item label="Service Cost">{detailOrder.service_cost}</Descriptions.Item>
                            <Descriptions.Item label="Total Cost">{detailOrder.total_cost}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Materials Sent
                        </Typography.Title>
                        {detailOrder.materials.length > 0 ? (
                            <Table
                                rowKey="id"
                                size="small"
                                pagination={false}
                                dataSource={detailOrder.materials}
                                scroll={{ x: 'max-content' }}
                                columns={[
                                    {
                                        title: 'Component',
                                        render: (_, m) => itemLabel(m.component),
                                    },
                                    { title: 'Required', dataIndex: 'quantity_required' },
                                    { title: 'Sent', dataIndex: 'quantity_sent' },
                                ]}
                            />
                        ) : (
                            <Typography.Text type="secondary">Materials not sent yet.</Typography.Text>
                        )}
                    </>
                )}
            </Drawer>
        </>
    );
}
