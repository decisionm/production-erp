import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Descriptions, Drawer, Form, Input, InputNumber, message, Modal, Select, Space, Table, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import { listWarehouses } from '@/features/inventory/api';
import { createGoodsReceipt, listGoodsReceipts, listPurchaseOrders } from '@/features/procurement/api';
import type { GoodsReceiptNote } from '@/features/procurement/types';

const receiptSchema = z.object({
    purchase_order_id: z.number({ error: 'Purchase order is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    reference: z.string().optional(),
    lines: z
        .array(
            z.object({
                purchase_order_line_id: z.number(),
                item_label: z.string(),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                unit_cost: z.number().min(0),
            }),
        )
        .min(1, 'Selected purchase order has nothing left to receive'),
});
type ReceiptFormValues = z.infer<typeof receiptSchema>;

export default function GoodsReceiptsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailReceipt, setDetailReceipt] = useState<GoodsReceiptNote | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['procurement', 'goods-receipts'], queryFn: listGoodsReceipts });
    const { data: orders } = useQuery({ queryKey: ['procurement', 'purchase-orders'], queryFn: listPurchaseOrders });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });

    const receivableOrders = useMemo(
        () => orders?.data.filter((o) => o.status === 'sent' || o.status === 'partially_received') ?? [],
        [orders],
    );
    const orderOptions = receivableOrders.map((o) => ({ value: o.id, label: `PO #${o.id} — ${o.vendor.name}` }));
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<ReceiptFormValues>({
        resolver: zodResolver(receiptSchema),
        defaultValues: { lines: [] },
    });
    const { fields, replace } = useFieldArray({ control, name: 'lines' });
    const selectedOrderId = watch('purchase_order_id');
    const selectedOrder = receivableOrders.find((o) => o.id === selectedOrderId);

    const handleLineScan = (code: string) => {
        const trimmed = code.trim().toLowerCase();
        const matchedLine = selectedOrder?.lines.find((l) => l.item.sku.toLowerCase() === trimmed);
        if (!matchedLine) {
            message.warning(`No line on this purchase order matches "${code}"`);
            return;
        }
        const index = fields.findIndex((f) => f.purchase_order_line_id === matchedLine.id);
        if (index === -1) {
            message.info(`${matchedLine.item.sku} has nothing left to receive on this order.`);
            return;
        }
        message.success(`Found line ${index + 1}: ${matchedLine.item.sku} — ${matchedLine.item.name}`);
    };

    useEffect(() => {
        const order = receivableOrders.find((o) => o.id === selectedOrderId);
        if (!order) {
            replace([]);
            return;
        }

        const remainingLines = order.lines
            .filter((line) => Number(line.quantity) - Number(line.quantity_received) > 0)
            .map((line) => ({
                purchase_order_line_id: line.id,
                item_label: `${line.item.sku} — ${line.item.name}`,
                quantity: Number(line.quantity) - Number(line.quantity_received),
                unit_cost: Number(line.unit_price),
            }));

        replace(remainingLines);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedOrderId]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['procurement', 'goods-receipts'] });
        queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-orders'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
    };

    const mutation = useMutation({
        mutationFn: createGoodsReceipt,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ lines: [] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not post receipt', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Goods Receipts</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Goods Receipt</Button>
            </Space>

            <Table<GoodsReceiptNote>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    { title: 'PO', render: (_, row) => `PO #${row.purchase_order_id}` },
                    { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                    { title: 'Received', render: (_, row) => row.received_date.slice(0, 10) },
                    { title: 'Reference', dataIndex: 'reference' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => setDetailReceipt(row)}>
                                View
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Goods Receipt"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) =>
                    mutation.mutate({
                        purchase_order_id: values.purchase_order_id,
                        warehouse_id: values.warehouse_id,
                        reference: values.reference,
                        lines: values.lines.map((l) => ({
                            purchase_order_line_id: l.purchase_order_line_id,
                            quantity: l.quantity,
                            unit_cost: l.unit_cost,
                        })),
                    }),
                )}
                confirmLoading={mutation.isPending}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Purchase Order"
                        validateStatus={errors.purchase_order_id ? 'error' : ''}
                        help={errors.purchase_order_id?.message}
                    >
                        <Controller
                            name="purchase_order_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={orderOptions} showSearch optionFilterProp="label" />
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
                    <Form.Item label="Reference">
                        <Controller name="reference" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>

                    <Typography.Text strong>Lines to Receive</Typography.Text>
                    {fields.length > 0 && (
                        <Form.Item style={{ marginTop: 8, marginBottom: 8 }}>
                            <BarcodeScanInput
                                autoFocus={false}
                                placeholder="Scan an item barcode to find its line…"
                                onScan={handleLineScan}
                            />
                        </Form.Item>
                    )}
                    {errors.lines?.root && (
                        <div style={{ color: '#ff4d4f', marginBottom: 8 }}>{errors.lines.root.message}</div>
                    )}
                    {fields.length === 0 && (
                        <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>
                            Select a purchase order with remaining quantity to receive.
                        </Typography.Paragraph>
                    )}
                    {fields.map((field, index) => (
                        <Space key={field.id} align="baseline" style={{ display: 'flex', marginTop: 8 }}>
                            <span style={{ width: 220, display: 'inline-block' }}>{field.item_label}</span>
                            <Controller
                                name={`lines.${index}.quantity`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Quantity" />}
                            />
                            <Controller
                                name={`lines.${index}.unit_cost`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Unit Cost" />}
                            />
                        </Space>
                    ))}
                </Form>
            </Modal>

            <Drawer
                title={`Goods Receipt #${detailReceipt?.id}`}
                open={detailReceipt !== null}
                onClose={() => setDetailReceipt(null)}
                width={560}
                destroyOnHidden
            >
                {detailReceipt && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Purchase Order">PO #{detailReceipt.purchase_order_id}</Descriptions.Item>
                            <Descriptions.Item label="Warehouse">
                                {detailReceipt.warehouse.code} — {detailReceipt.warehouse.name}
                            </Descriptions.Item>
                            <Descriptions.Item label="Received Date">
                                {detailReceipt.received_date.slice(0, 10)}
                            </Descriptions.Item>
                            <Descriptions.Item label="Reference">{detailReceipt.reference ?? '—'}</Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailReceipt.notes ?? '—'}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Lines
                        </Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailReceipt.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                { title: 'Item', render: (_, line) => `${line.item.sku} — ${line.item.name}` },
                                { title: 'Quantity', dataIndex: 'quantity' },
                                { title: 'Unit Cost', dataIndex: 'unit_cost' },
                            ]}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
