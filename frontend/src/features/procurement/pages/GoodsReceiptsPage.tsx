import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Form, Input, InputNumber, message, Modal, Select, Space, Table, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm, useWatch, type Control, type FieldPath } from 'react-hook-form';
import { z } from 'zod';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import MaterialBagLabels from '@/features/inventory/components/MaterialBagLabels';
import { listWarehouses } from '@/features/inventory/api';
import { createGoodsReceipt, listGoodsReceipts, listPurchaseOrders } from '@/features/procurement/api';
import type { GoodsReceiptNote } from '@/features/procurement/types';
import { useProductionSettings } from '@/features/production/packing';

const receiptSchema = z.object({
    purchase_order_id: z.number({ error: 'Purchase order is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    reference: z.string().optional(),
    lines: z
        .array(
            z.object({
                purchase_order_line_id: z.number(),
                item_label: z.string(),
                item_uom: z.string(),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                unit_cost: z.number().min(0),
                // Phase 6 traceability intake — optional, and only ever
                // populated when production.traceability_enabled is on (the
                // sub-form doesn't render otherwise).
                lots: z
                    .array(
                        z.object({
                            supplier_lot_no: z.string().min(1, 'Lot no required'),
                            bag_count: z.number({ error: 'Bags required' }).int().min(1, 'At least 1 bag'),
                            bag_weight_kg: z.number({ error: 'Kg/bag required' }).gt(0, 'Must be > 0'),
                        }),
                    )
                    .optional(),
            }),
        )
        .min(1, 'Selected purchase order has nothing left to receive'),
});
type ReceiptFormValues = z.infer<typeof receiptSchema>;

function newReceiptKey(): string {
    return typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : `grn-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function isMassUom(uom: string): boolean {
    return ['kg', 'kgs', 'kilogram', 'kilograms'].includes(uom.trim().toLowerCase().replace(/\.$/, ''));
}

/**
 * Phase 6 traceability: supplier lots & bags received on one GRN line — the
 * top of the GRN → lot → bag → day-bin chain. One row per supplier lot; the
 * backend mints a barcoded material_bags row per bag. Rendered only when the
 * traceability flag is on.
 */
function LineLotsSubForm({ control, lineIndex }: { control: Control<ReceiptFormValues>; lineIndex: number }) {
    const lots = useFieldArray({ control, name: `lines.${lineIndex}.lots` });
    const line = useWatch({ control, name: `lines.${lineIndex}` });
    const receiptKg = Number(line?.quantity ?? 0);
    const registeredKg = (line?.lots ?? []).reduce(
        (total, lot) => total + Number(lot?.bag_count ?? 0) * Number(lot?.bag_weight_kg ?? 0),
        0,
    );
    const differenceKg = Number((registeredKg - receiptKg).toFixed(4));
    return (
        <div style={{ margin: '4px 0 8px 16px' }}>
            {lots.fields.map((lotField, lotIndex) => (
                <Space key={lotField.id} align="baseline" style={{ display: 'flex', marginTop: 4 }}>
                    <Controller
                        name={`lines.${lineIndex}.lots.${lotIndex}.supplier_lot_no`}
                        control={control}
                        render={({ field, fieldState }) => (
                            <Space direction="vertical" size={0}>
                                <Input {...field} size="small" placeholder="Supplier lot no" status={fieldState.error ? 'error' : undefined} style={{ width: 160 }} />
                                {fieldState.error && <Typography.Text type="danger" style={{ fontSize: 11 }}>{fieldState.error.message}</Typography.Text>}
                            </Space>
                        )}
                    />
                    <Controller
                        name={`lines.${lineIndex}.lots.${lotIndex}.bag_count`}
                        control={control}
                        render={({ field, fieldState }) => (
                            <Space direction="vertical" size={0}>
                                <InputNumber {...field} size="small" min={1} placeholder="Bags" status={fieldState.error ? 'error' : undefined} style={{ width: 90 }} />
                                {fieldState.error && <Typography.Text type="danger" style={{ fontSize: 11 }}>{fieldState.error.message}</Typography.Text>}
                            </Space>
                        )}
                    />
                    <Controller
                        name={`lines.${lineIndex}.lots.${lotIndex}.bag_weight_kg`}
                        control={control}
                        render={({ field, fieldState }) => (
                            <Space direction="vertical" size={0}>
                                <InputNumber {...field} size="small" min={0} step={0.5} placeholder="Kg/bag" status={fieldState.error ? 'error' : undefined} style={{ width: 100 }} />
                                {fieldState.error && <Typography.Text type="danger" style={{ fontSize: 11 }}>{fieldState.error.message}</Typography.Text>}
                            </Space>
                        )}
                    />
                    <Button size="small" danger onClick={() => lots.remove(lotIndex)}>
                        Remove
                    </Button>
                </Space>
            ))}
            <Button
                size="small"
                type="dashed"
                style={{ marginTop: 4 }}
                onClick={() =>
                    lots.append({
                        supplier_lot_no: '',
                        bag_count: undefined as unknown as number,
                        bag_weight_kg: undefined as unknown as number,
                    })
                }
            >
                + Lot &amp; bags
            </Button>
            {lots.fields.length > 0 && (
                <Typography.Paragraph
                    type={Math.abs(differenceKg) < 0.0001 ? 'success' : 'danger'}
                    style={{ margin: '6px 0 0', fontSize: 12 }}
                >
                    Bag total {parseFloat(registeredKg.toFixed(4))} kg · receipt line {parseFloat(receiptKg.toFixed(4))} kg.{' '}
                    {Math.abs(differenceKg) < 0.0001
                        ? 'The quantities match.'
                        : differenceKg > 0
                          ? `Reduce the bag count or weight by ${differenceKg} kg.`
                          : `Add bags or increase the weight by ${Math.abs(differenceKg)} kg.`}
                </Typography.Paragraph>
            )}
        </div>
    );
}

export default function GoodsReceiptsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailReceipt, setDetailReceipt] = useState<GoodsReceiptNote | null>(null);
    const [createdReceipt, setCreatedReceipt] = useState<GoodsReceiptNote | null>(null);
    const [receiptKey, setReceiptKey] = useState(newReceiptKey);
    const [serverErrors, setServerErrors] = useState<string[]>([]);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['procurement', 'goods-receipts'], queryFn: listGoodsReceipts });
    const { data: orders } = useQuery({ queryKey: ['procurement', 'purchase-orders'], queryFn: listPurchaseOrders });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });
    // Phase 6 lot/bag intake renders only when the backend flag is on — with
    // it off (or an older backend) this page is exactly the pre-traceability UI.
    const settings = useProductionSettings();
    const traceabilityEnabled = settings?.traceability_enabled === true;

    const receivableOrders = useMemo(
        () => orders?.data.filter((o) => o.status === 'sent' || o.status === 'partially_received') ?? [],
        [orders],
    );
    const orderOptions = receivableOrders.map((o) => ({ value: o.id, label: `PO #${o.id} — ${o.vendor.name}` }));
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const { control, handleSubmit, reset, setError, watch, formState: { errors } } = useForm<ReceiptFormValues>({
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
                item_uom: line.item.uom,
                quantity: Number(line.quantity) - Number(line.quantity_received),
                unit_cost: Number(line.unit_price),
                // In the new traceability UI, kg receipts cannot silently
                // bypass their physical bags. Pre-opening one required row
                // makes the normal path obvious; the backend remains
                // backward-compatible with already-open legacy clients.
                lots:
                    traceabilityEnabled && isMassUom(line.item.uom)
                        ? [{
                              supplier_lot_no: '',
                              bag_count: undefined as unknown as number,
                              bag_weight_kg: undefined as unknown as number,
                          }]
                        : [],
            }));

        replace(remainingLines);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedOrderId, traceabilityEnabled]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['procurement', 'goods-receipts'] });
        queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-orders'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'material-lots'] });
        queryClient.invalidateQueries({ queryKey: ['production', 'material-bags'] });
    };

    /**
     * One atomic receipt: stock, supplier lots and physical bag barcodes
     * either all save, or none do. `receipt_key` stays unchanged across a
     * retry, so a lost response cannot post the same GRN/stock movement twice.
     */
    const mutation = useMutation({
        mutationFn: (values: ReceiptFormValues) =>
            createGoodsReceipt({
                receipt_key: receiptKey,
                purchase_order_id: values.purchase_order_id,
                warehouse_id: values.warehouse_id,
                reference: values.reference,
                lines: values.lines.map((l) => ({
                    purchase_order_line_id: l.purchase_order_line_id,
                    quantity: l.quantity,
                    unit_cost: l.unit_cost,
                    lots:
                        traceabilityEnabled && (l.lots?.length ?? 0) > 0
                            ? l.lots!.map((lot) => ({
                                  supplier_lot_no: lot.supplier_lot_no,
                                  bag_count: lot.bag_count,
                                  bag_weight_kg: lot.bag_weight_kg,
                                  total_received_kg: Number((lot.bag_count * lot.bag_weight_kg).toFixed(4)),
                              }))
                            : undefined,
                })),
            }),
        onSuccess: (grn) => {
            invalidate();
            setModalOpen(false);
            reset({ lines: [] });
            setServerErrors([]);
            setReceiptKey(newReceiptKey());
            const materialLots =
                grn.material_lots ?? grn.lines.flatMap((line) => line.material_lots ?? []);
            if (materialLots.length > 0) {
                setCreatedReceipt({ ...grn, material_lots: materialLots });
            } else {
                message.success(`Goods receipt #${grn.id} posted.`);
            }
        },
        onError: (error: any) => {
            const body = error?.response?.data;
            const validationErrors = (body?.errors ?? {}) as Record<string, string[] | string>;
            const messages = Object.entries(validationErrors).flatMap(([path, value]) => {
                const fieldMessages = Array.isArray(value) ? value : [value];
                if (path.startsWith('lines.')) {
                    setError(path as FieldPath<ReceiptFormValues>, {
                        type: 'server',
                        message: fieldMessages[0],
                    });
                }
                return fieldMessages;
            });
            setServerErrors(
                messages.length > 0
                    ? [...new Set(messages)]
                    : [body?.message ?? 'The receipt was not saved. Check the entered values and try again.'],
            );
        },
    });

    const openNewReceipt = () => {
        reset({ lines: [] });
        setServerErrors([]);
        setReceiptKey(newReceiptKey());
        setModalOpen(true);
    };

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Goods Receipts</Typography.Title>
                <Button type="primary" onClick={openNewReceipt}>New Goods Receipt</Button>
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
                onOk={handleSubmit((values) => mutation.mutate(values))}
                confirmLoading={mutation.isPending}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    {serverErrors.length > 0 && (
                        <Alert
                            type="error"
                            showIcon
                            style={{ marginBottom: 16 }}
                            message="Receipt not saved — your entries are still here"
                            description={
                                <ul style={{ margin: 0, paddingLeft: 18 }}>
                                    {serverErrors.map((error) => <li key={error}>{error}</li>)}
                                </ul>
                            }
                        />
                    )}
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
                        <div key={field.id}>
                            <Space align="baseline" style={{ display: 'flex', marginTop: 8 }}>
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
                            {/* Phase 6: per-line supplier lots & bags — the GRN
                                end of the lot → bag → day-bin chain. Optional:
                                a GRN without lots posts exactly as before. */}
                            {traceabilityEnabled && isMassUom(field.item_uom) && (
                                <LineLotsSubForm control={control} lineIndex={index} />
                            )}
                        </div>
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

            <Drawer
                title={`Goods receipt #${createdReceipt?.id} — bag labels ready`}
                open={createdReceipt !== null}
                onClose={() => setCreatedReceipt(null)}
                width={980}
                destroyOnHidden
            >
                {createdReceipt && (
                    <Space direction="vertical" size={16} style={{ width: '100%' }}>
                        <Alert
                            type="success"
                            showIcon
                            message="Receipt, lots and bags saved together"
                            description="Print these labels now, or reopen them later from Inventory → Material Receipts & Bag Labels."
                        />
                        <MaterialBagLabels lots={createdReceipt.material_lots ?? []} />
                    </Space>
                )}
            </Drawer>
        </>
    );
}
