import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems } from '@/features/inventory/api';
import { confirmSalesOrder, createSalesOrder, listCustomers, listSalesOrders } from '@/features/sales/api';
import type { SalesOrder, SalesOrderStatus } from '@/features/sales/types';

const orderSchema = z.object({
    customer_id: z.number({ error: 'Customer is required' }),
    order_date: z.string({ error: 'Order date is required' }),
    expected_date: z.string().optional(),
    notes: z.string().optional(),
    lines: z
        .array(
            z.object({
                item_id: z.number({ error: 'Item is required' }),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                unit_price: z.number().min(0),
            }),
        )
        .min(1, 'Add at least one line'),
});
type OrderFormValues = z.infer<typeof orderSchema>;

const statusColor: Record<SalesOrderStatus, string> = {
    draft: 'default',
    confirmed: 'blue',
    partially_delivered: 'gold',
    completed: 'green',
    cancelled: 'red',
};

export default function SalesOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailOrder, setDetailOrder] = useState<SalesOrder | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['sales', 'sales-orders'], queryFn: listSalesOrders });
    const { data: customers } = useQuery({ queryKey: ['sales', 'customers'], queryFn: listCustomers });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });

    const customerOptions = customers?.data.map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` })) ?? [];
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<OrderFormValues>({
        resolver: zodResolver(orderSchema),
        defaultValues: { lines: [{ item_id: undefined, quantity: undefined, unit_price: undefined }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });

    const createMutation = useMutation({
        mutationFn: createSalesOrder,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });
    const confirmMutation = useMutation({ mutationFn: confirmSalesOrder, onSuccess: invalidate });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Sales Orders</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Sales Order</Button>
            </Space>

            <Table<SalesOrder>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: SalesOrderStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Customer', render: (_, row) => row.customer.name },
                    { title: 'Order Date', dataIndex: 'order_date' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailOrder(row)}>
                                    View
                                </Button>
                                {row.status === 'draft' && (
                                    <Button
                                        size="small"
                                        onClick={() => confirmMutation.mutate(row.id)}
                                        loading={confirmMutation.isPending}
                                    >
                                        Confirm
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Sales Order"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={760}
            >
                <Form layout="vertical">
                    <Form.Item label="Customer" validateStatus={errors.customer_id ? 'error' : ''} help={errors.customer_id?.message}>
                        <Controller
                            name="customer_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={customerOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Order Date" validateStatus={errors.order_date ? 'error' : ''} help={errors.order_date?.message}>
                        <Controller
                            name="order_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Expected Date">
                        <Controller
                            name="expected_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Lines</Typography.Text>
                    {errors.lines?.root && (
                        <div style={{ color: '#ff4d4f', marginBottom: 8 }}>{errors.lines.root.message}</div>
                    )}
                    {fields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <Controller
                                name={`lines.${index}.item_id`}
                                control={control}
                                render={({ field }) => (
                                    <Select
                                        {...field}
                                        options={itemOptions}
                                        showSearch
                                        optionFilterProp="label"
                                        style={{ width: 220 }}
                                        placeholder="Item"
                                    />
                                )}
                            />
                            <Controller
                                name={`lines.${index}.quantity`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Quantity" />}
                            />
                            <Controller
                                name={`lines.${index}.unit_price`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Unit Price" />}
                            />
                            <Button danger onClick={() => remove(index)}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() =>
                            append({
                                item_id: undefined as unknown as number,
                                quantity: undefined as unknown as number,
                                unit_price: undefined as unknown as number,
                            })
                        }
                    >
                        Add Line
                    </Button>
                </Form>
            </Modal>

            <Drawer
                title={`Sales Order #${detailOrder?.id}`}
                open={detailOrder !== null}
                onClose={() => setDetailOrder(null)}
                width={620}
                destroyOnHidden
            >
                {detailOrder && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailOrder.status]}>{detailOrder.status}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Customer">{detailOrder.customer.name}</Descriptions.Item>
                            <Descriptions.Item label="Order Date">{detailOrder.order_date}</Descriptions.Item>
                            <Descriptions.Item label="Expected Date">{detailOrder.expected_date ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailOrder.notes ?? '—'}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Lines
                        </Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailOrder.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                { title: 'Item', render: (_, line) => `${line.item.sku} — ${line.item.name}` },
                                { title: 'Quantity', dataIndex: 'quantity' },
                                { title: 'Delivered', dataIndex: 'quantity_delivered' },
                                { title: 'Unit Price', dataIndex: 'unit_price' },
                                {
                                    title: 'Amount',
                                    render: (_, line) => (Number(line.quantity) * Number(line.unit_price)).toFixed(2),
                                },
                            ]}
                            summary={(lines) => {
                                const total = lines.reduce(
                                    (sum, line) => sum + Number(line.quantity) * Number(line.unit_price),
                                    0,
                                );
                                return (
                                    <Table.Summary.Row>
                                        <Table.Summary.Cell index={0} colSpan={4}>
                                            <strong>Total</strong>
                                        </Table.Summary.Cell>
                                        <Table.Summary.Cell index={1}>
                                            <strong>{total.toFixed(2)}</strong>
                                        </Table.Summary.Cell>
                                    </Table.Summary.Row>
                                );
                            }}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
