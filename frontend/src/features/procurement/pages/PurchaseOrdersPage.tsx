import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { type Control, Controller, useFieldArray, useForm } from 'react-hook-form';
import { Link, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { listAllItems } from '@/features/inventory/api';
import { createPurchaseOrder, listPurchaseOrders, listAllVendors, sendPurchaseOrder } from '@/features/procurement/api';
import type { PurchaseOrder, PurchaseOrderLine, PurchaseOrderStatus } from '@/features/procurement/types';
import { itemLabel } from '@/lib/itemLabel';

const orderSchema = z.object({
    vendor_id: z.number({ error: 'Vendor is required' }),
    order_date: z.string({ error: 'Order date is required' }),
    expected_date: z.string().optional(),
    notes: z.string().optional(),
    // A Tally mirror: the real order lives in Tally (the PO/schedule source
    // of truth); this entry records its exact identities read-only.
    is_tally_mirror: z.boolean().optional(),
    tally_order_no: z.string().trim().max(64).optional(),
    lines: z
        .array(
            z.object({
                item_id: z.number({ error: 'Item is required' }),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                unit_price: z.number().min(0),
                // Item/due-date delivery windows (their sum may not exceed
                // the line — the server enforces it; the arrival screen
                // consumes them oldest-due-first).
                schedules: z
                    .array(
                        z.object({
                            due_date: z.string({ error: 'Due date is required' }),
                            quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                            tally_reference: z.string().trim().max(64).optional(),
                        }),
                    )
                    .optional(),
            }),
        )
        .min(1, 'Add at least one line'),
}).refine(
    (values) => !values.is_tally_mirror || (values.tally_order_no ?? '') !== '',
    { message: 'A Tally mirror needs the exact Tally order number', path: ['tally_order_no'] },
);
type OrderFormValues = z.infer<typeof orderSchema>;

/**
 * Item/due-date delivery windows for one order line — the mirror of Tally's
 * order allocations. Optional on an ERP-native order; the arrival screen
 * consumes them oldest-due-first with an editable preview.
 */
function LineSchedulesEditor({ control, lineIndex }: { control: Control<OrderFormValues>; lineIndex: number }) {
    const { fields, append, remove } = useFieldArray({ control, name: `lines.${lineIndex}.schedules` });

    return (
        <div style={{ marginLeft: 24, marginTop: 4 }}>
            {fields.map((field, scheduleIndex) => (
                <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 4 }}>
                    <Controller
                        name={`lines.${lineIndex}.schedules.${scheduleIndex}.due_date`}
                        control={control}
                        render={({ field }) => (
                            <DatePicker
                                placeholder="Due date"
                                onChange={(_, dateString) => field.onChange(dateString || undefined)}
                            />
                        )}
                    />
                    <Controller
                        name={`lines.${lineIndex}.schedules.${scheduleIndex}.quantity`}
                        control={control}
                        render={({ field }) => <InputNumber {...field} min={0} placeholder="Qty" />}
                    />
                    <Controller
                        name={`lines.${lineIndex}.schedules.${scheduleIndex}.tally_reference`}
                        control={control}
                        render={({ field }) => <Input {...field} placeholder="Tally ref (optional)" style={{ width: 160 }} />}
                    />
                    <Button size="small" danger onClick={() => remove(scheduleIndex)}>×</Button>
                </Space>
            ))}
            <Button
                size="small"
                type="link"
                style={{ paddingLeft: 0 }}
                onClick={() => append({ due_date: undefined as unknown as string, quantity: undefined as unknown as number })}
            >
                + delivery schedule
            </Button>
        </div>
    );
}

const statusColor: Record<PurchaseOrderStatus, string> = {
    draft: 'default',
    sent: 'blue',
    partially_received: 'gold',
    closed: 'green',
    cancelled: 'red',
};

/** quantity × unit_price, or '—' when the rate is not on the line at all. */
function lineAmount(line: PurchaseOrderLine): string {
    if (line.unit_price === undefined) return '—';
    return (Number(line.quantity) * Number(line.unit_price)).toFixed(2);
}

export default function PurchaseOrdersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailOrder, setDetailOrder] = useState<PurchaseOrder | null>(null);
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);

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
    const { data: vendors } = useQuery({ queryKey: ['procurement', 'vendors', 'all'], queryFn: listAllVendors });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });

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

    /**
     * DOES THIS ORDER CARRY RATES AT ALL — the server's answer, honoured
     * locally (the MaterialLotsPage precedent). unit_price is OMITTED by
     * PurchaseOrderLineResource for anyone without finance access, so its
     * presence is the ruling that arrived with the data; the permission
     * check alongside can only make it stricter. When false, the Unit Price
     * and Amount columns and the total row do not exist — no '—' column
     * advertising a number it will not show, and no NaN total.
     */
    const showsRates =
        hasModuleAccess(user, 'finance') &&
        (detailOrder?.lines.some((line) => line.unit_price !== undefined) ?? false);

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
                    {
                        title: 'Source',
                        render: (_, row) =>
                            row.source === 'tally' ? (
                                // The real order lives in Tally — this row is
                                // its read-only mirror, corrected there.
                                <Tag color="geekblue">Tally · {row.tally_order_no ?? 'mirror'}</Tag>
                            ) : (
                                <Typography.Text type="secondary">ERP</Typography.Text>
                            ),
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
                onOk={handleSubmit(({ is_tally_mirror, tally_order_no, ...values }) =>
                    createMutation.mutate({
                        ...values,
                        ...(is_tally_mirror ? { source: 'tally' as const, tally_order_no } : {}),
                    }),
                )}
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

                    {/* Tally is the PO and delivery-schedule source of truth.
                        A mirror records the real order's exact identities;
                        it arrives already sent and is corrected in Tally,
                        never edited here. */}
                    <Form.Item label="Mirrored from Tally">
                        <Space>
                            <Controller
                                name="is_tally_mirror"
                                control={control}
                                render={({ field }) => (
                                    <Switch checked={field.value ?? false} onChange={field.onChange} />
                                )}
                            />
                            <Controller
                                name="tally_order_no"
                                control={control}
                                render={({ field }) => (
                                    <Input {...field} placeholder="Exact Tally order no. (e.g. PO/2026/041)" style={{ width: 280 }} />
                                )}
                            />
                        </Space>
                        {errors.tally_order_no && (
                            <div style={{ color: '#ff4d4f' }}>{errors.tally_order_no.message}</div>
                        )}
                    </Form.Item>

                    <Typography.Text strong>Lines</Typography.Text>
                    {errors.lines?.root && (
                        <div style={{ color: '#ff4d4f', marginBottom: 8 }}>{errors.lines.root.message}</div>
                    )}
                    {fields.map((field, index) => (
                        <div key={field.id} style={{ marginTop: 8 }}>
                            <Space align="baseline" style={{ display: 'flex' }}>
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
                            <LineSchedulesEditor control={control} lineIndex={index} />
                        </div>
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
                width="min(100vw, 620px)"
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
                                ...(showsRates
                                    ? [
                                          { title: 'Unit Price', render: (_: unknown, line: PurchaseOrderLine) => line.unit_price ?? '—' },
                                          { title: 'Amount', render: (_: unknown, line: PurchaseOrderLine) => lineAmount(line) },
                                      ]
                                    : []),
                            ]}
                            summary={
                                showsRates
                                    ? (lines) => {
                                          // A line without a rate contributes
                                          // nothing rather than poisoning the
                                          // total with NaN.
                                          const total = lines.reduce(
                                              (sum, line) =>
                                                  line.unit_price === undefined
                                                      ? sum
                                                      : sum + Number(line.quantity) * Number(line.unit_price),
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
                                      }
                                    : undefined
                            }
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
