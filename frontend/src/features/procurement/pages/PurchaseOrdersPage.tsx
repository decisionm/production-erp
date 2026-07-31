import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { Link, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { listItems } from '@/features/inventory/api';
import { createPurchaseOrder, listPurchaseOrders, listVendors, sendPurchaseOrder } from '@/features/procurement/api';
import type { PurchaseOrder, PurchaseOrderStatus } from '@/features/procurement/types';
import { itemLabel } from '@/lib/itemLabel';

const orderSchema = z.object({
    vendor_id: z.number({ error: 'Vendor is required' }),
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

const statusColor: Record<PurchaseOrderStatus, string> = {
    draft: 'default',
    sent: 'blue',
    partially_received: 'gold',
    closed: 'green',
    cancelled: 'red',
};

export default function PurchaseOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailOrder, setDetailOrder] = useState<PurchaseOrder | null>(null);
    const queryClient = useQueryClient();

    // ?po=7 — a goods receipt (or a receipt's stock movement) linking back to
    // the order it was received against. Without it the page is unchanged.
    const [searchParams, setSearchParams] = useSearchParams();
    const focusOrderId = Number(searchParams.get('po')) || null;

    // A linked order can be older than the newest 20, so a linked view asks
    // for the whole list instead of the default first page.
    const { data, isLoading } = useQuery({
        queryKey: ['procurement', 'purchase-orders', focusOrderId !== null ? 'all' : 'first-page'],
        queryFn: () => listPurchaseOrders(focusOrderId !== null ? { per_page: 1000 } : undefined),
    });
    const { data: vendors } = useQuery({ queryKey: ['procurement', 'vendors'], queryFn: listVendors });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });

    const vendorOptions = vendors?.data.map((v) => ({ value: v.id, label: `${v.code} — ${v.name}` })) ?? [];
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<OrderFormValues>({
        resolver: zodResolver(orderSchema),
        defaultValues: { lines: [{ item_id: undefined, quantity: undefined, unit_price: undefined }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const orders = data?.data ?? [];
    const visibleOrders = useMemo(
        () => (focusOrderId === null ? orders : orders.filter((order) => order.id === focusOrderId)),
        [orders, focusOrderId],
    );

    // Following a ?po= link opens that order straight away — once, so closing
    // the drawer doesn't reopen it.
    const openedOrderRef = useRef<number | null>(null);
    useEffect(() => {
        if (focusOrderId === null) {
            openedOrderRef.current = null;
            return;
        }
        if (openedOrderRef.current === focusOrderId) return;
        const match = orders.find((order) => order.id === focusOrderId);
        if (match) {
            openedOrderRef.current = focusOrderId;
            setDetailOrder(match);
        }
    }, [focusOrderId, orders]);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-orders'] });

    const createMutation = useMutation({
        mutationFn: createPurchaseOrder,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });
    const sendMutation = useMutation({ mutationFn: sendPurchaseOrder, onSuccess: invalidate });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Purchase Orders</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Purchase Order</Button>
            </Space>

            {focusOrderId !== null && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={`Showing purchase order #${focusOrderId} only`}
                    action={
                        <Button size="small" onClick={() => setSearchParams({})}>
                            Show all orders
                        </Button>
                    }
                />
            )}

            <Table<PurchaseOrder>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={visibleOrders}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: PurchaseOrderStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Vendor', render: (_, row) => row.vendor.name },
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
                                        onClick={() => sendMutation.mutate(row.id)}
                                        loading={sendMutation.isPending}
                                    >
                                        Send
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Purchase Order"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={760}
            >
                <Form layout="vertical">
                    <Form.Item label="Vendor" validateStatus={errors.vendor_id ? 'error' : ''} help={errors.vendor_id?.message}>
                        <Controller
                            name="vendor_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={vendorOptions} showSearch optionFilterProp="label" />
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
                title={`Purchase Order #${detailOrder?.id}`}
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
                            <Descriptions.Item label="Vendor">{detailOrder.vendor.name}</Descriptions.Item>
                            <Descriptions.Item label="Order Date">{detailOrder.order_date}</Descriptions.Item>
                            <Descriptions.Item label="Expected Date">{detailOrder.expected_date ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailOrder.notes ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Receipts">
                                {/* Forward down the chain: what has actually
                                    been received against this order. */}
                                <Link to={`/procurement/goods-receipts?po=${detailOrder.id}`}>
                                    Goods receipts for this order
                                </Link>
                            </Descriptions.Item>
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
                                { title: 'Item', render: (_, line) => itemLabel(line.item) },
                                { title: 'Quantity', dataIndex: 'quantity' },
                                { title: 'Received', dataIndex: 'quantity_received' },
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
