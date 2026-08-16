import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, message, Modal, Select, Space, Table, Typography } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm, useWatch, type Control, type FieldPath } from 'react-hook-form';
import { Link, useSearchParams } from 'react-router-dom';
import { z } from 'zod';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import MaterialBagLabels from '@/features/inventory/components/MaterialBagLabels';
import { listAllWarehouses } from '@/features/inventory/api';
import { createGoodsReceipt, listGoodsReceipts, listPurchaseOrders } from '@/features/procurement/api';
import type { GoodsReceiptNote, GoodsReceiptNoteLine, PurchaseOrderSchedule } from '@/features/procurement/types';
import { useProductionSettings } from '@/features/production/packing';
import { formatDateTime } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';

/** Wall-clock text the API stores as-is — never a timezone-converted instant. */
const RECEIVED_AT_FORMAT = 'YYYY-MM-DD HH:mm';

function nowReceivedAt(): string {
    return dayjs().format(RECEIVED_AT_FORMAT);
}

/**
 * The owner-confirmed default: an arrival fills the oldest-due open window
 * first, then walks forward. Rendered as an editable preview — the server
 * enforces the same arithmetic on whatever the receiver submits.
 */
function proposeAllocations(schedules: PurchaseOrderSchedule[], quantity: number) {
    if (schedules.length === 0) return undefined;
    let left = quantity;
    return schedules.map((schedule) => {
        const open = Number(schedule.remaining);
        const take = Math.min(Math.max(left, 0), Math.max(open, 0));
        left -= take;
        return {
            purchase_order_schedule_id: schedule.id,
            due_date: schedule.due_date,
            remaining: schedule.remaining,
            quantity: Number(take.toFixed(4)),
        };
    });
}

const receiptSchema = z.object({
    purchase_order_id: z.number({ error: 'Purchase order is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    // The real unloading date+time, not the moment the form is submitted.
    received_date: z
        .string({ error: 'Received date & time is required' })
        .min(1, 'Received date & time is required'),
    reference: z.string().optional(),
    // Recorded at physical arrival (owner flow) — both default
    // deterministically server-side when left blank.
    receipt_note_reference: z.string().trim().max(64).optional(),
    tracking_number: z.string().trim().max(64).optional(),
    lines: z
        .array(
            z.object({
                purchase_order_line_id: z.number(),
                item_label: z.string(),
                item_uom: z.string(),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                // Absent (not sent) when the PO rate is not visible to this
                // user — the server then defaults it from the PO line and
                // records that provenance itself.
                unit_cost: z.number().min(0).optional(),
                // The editable allocation preview against the PO line's
                // delivery windows. Prefilled oldest-due-first from the
                // typed quantity; the server validates the sum exactly.
                schedule_allocations: z
                    .array(z.object({
                        purchase_order_schedule_id: z.number(),
                        due_date: z.string(),
                        remaining: z.string(),
                        quantity: z.number().min(0),
                    }))
                    .optional(),
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

/**
 * The price actually paid on this receipt. One figure when every line was
 * received at the same rate (the normal case), otherwise each distinct rate —
 * the per-line breakdown stays in the View drawer.
 */
function unitPriceSummary(receipt: GoodsReceiptNote): string {
    // unit_cost is absent (not null) on every line for a non-finance user.
    const prices = [...new Set(receipt.lines.map((line) => line.unit_cost))].filter(
        (price): price is string => price !== undefined,
    );

    return prices.length > 0 ? prices.join(', ') : '—';
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
/**
 * The editable allocation preview: which delivery window this arrival's
 * quantity is booked against. Prefilled oldest-due-first when the line is
 * seeded; the receiver may move quantity between windows; the server
 * validates that the sum equals the line exactly.
 */
function LineAllocationsEditor({ control, lineIndex }: { control: Control<ReceiptFormValues>; lineIndex: number }) {
    const { fields } = useFieldArray({ control, name: `lines.${lineIndex}.schedule_allocations` });

    if (fields.length === 0) return null;

    return (
        <div style={{ marginLeft: 24, marginTop: 4 }}>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Booked against delivery windows — oldest due first by default; adjust before submitting.
            </Typography.Text>
            {fields.map((row, allocationIndex) => (
                <Space key={row.id} align="baseline" style={{ display: 'flex', marginTop: 2 }}>
                    <span style={{ width: 150, display: 'inline-block', fontVariantNumeric: 'tabular-nums' }}>
                        due {row.due_date}
                    </span>
                    <Typography.Text type="secondary" style={{ fontSize: 12, width: 110, display: 'inline-block' }}>
                        open {row.remaining}
                    </Typography.Text>
                    <Controller
                        name={`lines.${lineIndex}.schedule_allocations.${allocationIndex}.quantity`}
                        control={control}
                        render={({ field }) => <InputNumber {...field} min={0} placeholder="Qty" size="small" />}
                    />
                </Space>
            ))}
        </div>
    );
}

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
    const user = useAuthStore((s) => s.user);
    const financeAccess = hasModuleAccess(user, 'finance');

    // Deep links into this page: ?grn=7 from the material-lot register (that
    // one receipt), ?po=3 from an item's movement history (every receipt on
    // that order). With neither param the page behaves exactly as before.
    const [searchParams, setSearchParams] = useSearchParams();
    const focusGrnId = Number(searchParams.get('grn')) || null;
    const focusPoId = Number(searchParams.get('po')) || null;
    const isDeepLinked = focusGrnId !== null || focusPoId !== null;

    // A link may point at a receipt older than the newest 20, so a linked view
    // asks for the whole register rather than the default first page.
    const { data, isLoading } = useQuery({
        queryKey: ['procurement', 'goods-receipts', isDeepLinked ? 'all' : 'first-page'],
        queryFn: () => listGoodsReceipts(isDeepLinked ? { per_page: 1000 } : undefined),
    });
    const { data: orders } = useQuery({ queryKey: ['procurement', 'purchase-orders'], queryFn: () => listPurchaseOrders() });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });
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

    const receipts = data?.data ?? [];
    /**
     * DOES THIS REGISTER CARRY RATES AT ALL — the server's answer, honoured
     * locally (the MaterialLotsPage precedent). unit_cost is OMITTED by
     * GoodsReceiptNoteLineResource for anyone without finance access, so its
     * presence is the ruling that arrived with the data; the permission check
     * alongside can only make it stricter. When false the rate columns do not
     * exist — no '—' column advertising a number it will not show.
     */
    const showsRates =
        financeAccess && receipts.some((receipt) => receipt.lines.some((line) => line.unit_cost !== undefined));
    const visibleReceipts = useMemo(
        () =>
            receipts.filter(
                (receipt) =>
                    (focusGrnId === null || receipt.id === focusGrnId) &&
                    (focusPoId === null || receipt.purchase_order_id === focusPoId),
            ),
        [receipts, focusGrnId, focusPoId],
    );

    // Following a ?grn= link opens that receipt straight away — once, so
    // closing the drawer doesn't reopen it.
    const openedGrnRef = useRef<number | null>(null);
    useEffect(() => {
        if (focusGrnId === null) {
            openedGrnRef.current = null;
            return;
        }
        if (openedGrnRef.current === focusGrnId) return;
        const match = receipts.find((receipt) => receipt.id === focusGrnId);
        if (match) {
            openedGrnRef.current = focusGrnId;
            setDetailReceipt(match);
        }
    }, [focusGrnId, receipts]);

    const { control, handleSubmit, reset, setError, watch, formState: { errors } } = useForm<ReceiptFormValues>({
        resolver: zodResolver(receiptSchema),
        defaultValues: { lines: [] },
    });
    const { fields, replace } = useFieldArray({ control, name: 'lines' });
    const selectedOrderId = watch('purchase_order_id');
    const selectedOrder = receivableOrders.find((o) => o.id === selectedOrderId);
    // Same rule for the form: the Unit Cost input exists only when the PO
    // rate it would override is on the order for this user. Without it there
    // is nothing to see or override — the server defaults the receipt line
    // from the PO price and stamps that provenance itself.
    const showsOrderRates =
        financeAccess && (selectedOrder?.lines.some((line) => line.unit_price !== undefined) ?? false);

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
                item_label: itemLabel(line.item),
                item_uom: line.item.uom,
                quantity: Number(line.quantity) - Number(line.quantity_received),
                // Prefilled from the PO price only when this user can see it;
                // otherwise left undefined and never sent (see the schema).
                unit_cost: financeAccess && line.unit_price !== undefined ? Number(line.unit_price) : undefined,
                // Oldest-due-first proposal over the line's open delivery
                // windows — shown editable; the receiver may move quantity
                // between windows before submitting.
                schedule_allocations: proposeAllocations(
                    line.schedules ?? [],
                    Number(line.quantity) - Number(line.quantity_received),
                ),
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
                received_date: values.received_date,
                reference: values.reference,
                receipt_note_reference: values.receipt_note_reference || undefined,
                tracking_number: values.tracking_number || undefined,
                lines: values.lines.map((l) => ({
                    purchase_order_line_id: l.purchase_order_line_id,
                    quantity: l.quantity,
                    // Not sent at all when the PO rate was not visible here —
                    // the server defaults it from the PO line, never the client.
                    ...(l.unit_cost !== undefined ? { unit_cost: l.unit_cost } : {}),
                    // Send the edited preview only when windows exist; rows
                    // moved to zero are dropped (a zero allocation is not an
                    // allocation). The server re-validates the exact sum.
                    schedule_allocations: (l.schedule_allocations ?? [])
                        .filter((row) => row.quantity > 0)
                        .map((row) => ({
                            purchase_order_schedule_id: row.purchase_order_schedule_id,
                            quantity: row.quantity,
                        })),
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
            reset({ lines: [], received_date: nowReceivedAt() });
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
        // Defaulted here rather than in defaultValues so it is the time the
        // form was opened, not the time the page was loaded.
        reset({ lines: [], received_date: nowReceivedAt() });
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

            {isDeepLinked && (
                <Alert
                    type="info"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={
                        focusGrnId !== null
                            ? `Showing goods receipt #${focusGrnId} only`
                            : `Showing the goods receipts for PO #${focusPoId} only`
                    }
                    action={
                        <Button size="small" onClick={() => setSearchParams({})}>
                            Show all receipts
                        </Button>
                    }
                />
            )}

            <Table<GoodsReceiptNote>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={visibleReceipts}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    {
                        title: 'PO',
                        render: (_, row) => (
                            // Straight back up the chain to the order this
                            // material was received against.
                            <Link to={`/procurement/purchase-orders?po=${row.purchase_order_id}`}>
                                PO #{row.purchase_order_id}
                            </Link>
                        ),
                    },
                    { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                    { title: 'Received', render: (_, row) => formatDateTime(row.received_date) },
                    ...(showsRates ? [{ title: 'Unit Price', render: (_: unknown, row: GoodsReceiptNote) => unitPriceSummary(row) }] : []),
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
                    <Form.Item
                        label="Received (date & time the material actually arrived)"
                        validateStatus={errors.received_date ? 'error' : ''}
                        help={errors.received_date?.message}
                    >
                        <Controller
                            name="received_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    showTime={{ format: 'HH:mm' }}
                                    format={RECEIVED_AT_FORMAT}
                                    allowClear={false}
                                    value={field.value ? dayjs(field.value) : null}
                                    onChange={(value) => field.onChange(value ? value.format(RECEIVED_AT_FORMAT) : '')}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Reference">
                        <Controller name="reference" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    {/* Recorded at physical arrival (owner flow): the Receipt
                        Note reference this arrival — and any later Rejections
                        Out — will carry against the Tally order. Left blank,
                        both default to deterministic internal ones. */}
                    <Form.Item label="Receipt Note reference">
                        <Space>
                            <Controller
                                name="receipt_note_reference"
                                control={control}
                                render={({ field }) => <Input {...field} placeholder="e.g. RN-00072 (blank = automatic)" style={{ width: 240 }} />}
                            />
                            <Controller
                                name="tracking_number"
                                control={control}
                                render={({ field }) => <Input {...field} placeholder="Tracking no. (blank = automatic)" style={{ width: 220 }} />}
                            />
                        </Space>
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
                                {showsOrderRates && (
                                    <Controller
                                        name={`lines.${index}.unit_cost`}
                                        control={control}
                                        render={({ field }) => <InputNumber {...field} min={0} placeholder="Unit Cost" />}
                                    />
                                )}
                            </Space>
                            <LineAllocationsEditor control={control} lineIndex={index} />
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
                width="min(100vw, 560px)"
                destroyOnHidden
            >
                {detailReceipt && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Purchase Order">
                                <Link to={`/procurement/purchase-orders?po=${detailReceipt.purchase_order_id}`}>
                                    PO #{detailReceipt.purchase_order_id}
                                </Link>
                            </Descriptions.Item>
                            <Descriptions.Item label="Warehouse">
                                {detailReceipt.warehouse.code} — {detailReceipt.warehouse.name}
                            </Descriptions.Item>
                            <Descriptions.Item label="Received (date &amp; time)">
                                {formatDateTime(detailReceipt.received_date)}
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
                                { title: 'Item', render: (_, line) => itemLabel(line.item) },
                                { title: 'Quantity', dataIndex: 'quantity' },
                                ...(showsRates
                                    ? [{ title: 'Unit Cost', render: (_: unknown, line: GoodsReceiptNoteLine) => line.unit_cost ?? '—' }]
                                    : []),
                            ]}
                        />
                    </>
                )}
            </Drawer>

            <Drawer
                title={`Goods receipt #${createdReceipt?.id} — bag labels ready`}
                open={createdReceipt !== null}
                onClose={() => setCreatedReceipt(null)}
                width="min(100vw, 980px)"
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
