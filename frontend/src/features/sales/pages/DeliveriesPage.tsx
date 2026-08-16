import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Form, Input, InputNumber, message, Modal, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import { listAllWarehouses } from '@/features/inventory/api';
import { lookupCarton } from '@/features/production/api';
import type { FinishedCarton } from '@/features/production/types';
import { createDelivery, listDeliveries, listSalesOrders } from '@/features/sales/api';
import { hasActiveFilters } from '@/features/sales/filters';
import SalesDocumentDrawer, { TallyLinkCell } from '@/features/sales/SalesDocumentDrawer';
import SalesFilterBar from '@/features/sales/SalesFilterBar';
import type { Delivery } from '@/features/sales/types';
import { useSalesListParams } from '@/features/sales/useSalesListParams';

const deliverySchema = z.object({
    sales_order_id: z.number({ error: 'Sales order is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    reference: z.string().optional(),
    lines: z
        .array(
            z.object({
                sales_order_line_id: z.number(),
                item_label: z.string(),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
            }),
        )
        .min(1, 'Selected sales order has nothing left to deliver'),
});
type DeliveryFormValues = z.infer<typeof deliverySchema>;

export default function DeliveriesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    // DISPATCH BY SCAN: the cartons scanned for this delivery. When any are
    // present the server derives the lines from these physical boxes and the
    // typed quantities above are not sent at all.
    const [scannedCartons, setScannedCartons] = useState<FinishedCarton[]>([]);
    const queryClient = useQueryClient();

    // The filters, the page and the open drawer all live in the URL — a
    // pasted link is the same view. The server does the narrowing.
    const { filters, setFilters, setPage, target, openTarget, closeTarget } = useSalesListParams('delivery');
    const filtersActive = hasActiveFilters('delivery', filters);

    const { data, isLoading } = useQuery({
        queryKey: ['sales', 'deliveries', 'list', filters],
        queryFn: () => listDeliveries(filters),
        placeholderData: (previous) => previous,
    });
    const { data: orders } = useQuery({ queryKey: ['sales', 'sales-orders'], queryFn: listSalesOrders });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });

    const deliverableOrders = useMemo(
        () => orders?.data.filter((o) => o.status === 'confirmed' || o.status === 'partially_delivered') ?? [],
        [orders],
    );
    const orderOptions = deliverableOrders.map((o) => ({ value: o.id, label: `SO #${o.id} — ${o.customer.name}` }));
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<DeliveryFormValues>({
        resolver: zodResolver(deliverySchema),
        defaultValues: { lines: [] },
    });
    const { fields, replace } = useFieldArray({ control, name: 'lines' });
    const selectedOrderId = watch('sales_order_id');
    const selectedOrder = deliverableOrders.find((o) => o.id === selectedOrderId);

    // Resolve one scanned carton code and queue it. The server re-checks
    // everything at submit (locked, so two scanners cannot race a box out
    // twice) — these checks exist so the person scanning hears about a wrong
    // box now, holding it, not after twenty more scans.
    const handleCartonScan = async (code: string) => {
        const trimmed = code.trim();
        if (trimmed === '') return;
        if (scannedCartons.some((c) => c.carton_no === trimmed)) {
            message.info(`Carton ${trimmed} is already on this delivery's list.`);
            return;
        }
        try {
            const carton = await lookupCarton(trimmed);
            if (carton.status === 'dispatched') {
                message.error(`Carton ${trimmed} was already dispatched — it cannot leave twice.`);
                return;
            }
            // QUALITY REJECTED boxes never queue (DEC-20260807-013) — the
            // server refuses them at submit too; this tells the person
            // holding the box, loudly, now.
            if (carton.quality?.verdict === 'quality_rejected') {
                message.error(`Carton ${trimmed} is QUALITY REJECTED — do not ship.`, 6);
                return;
            }
            if (selectedOrder && !selectedOrder.lines.some((l) => l.item.id === carton.item?.id)) {
                message.error(`Carton ${trimmed} holds ${carton.item?.sku ?? 'an item'} — this order does not carry it.`);
                return;
            }
            setScannedCartons((prev) => [...prev, carton]);
            message.success(
                `${trimmed}: ${Number(carton.pieces).toLocaleString('en-IN')} pcs · batch ${carton.batch?.batch_number ?? '—'}${carton.quality?.verdict === 'pending' ? ' · awaiting QC/approval' : ''}`,
            );
        } catch (error: any) {
            message.error(error?.response?.data?.message ?? `No carton carries the code ${trimmed}.`);
        }
    };

    const handleLineScan = (code: string) => {
        const trimmed = code.trim().toLowerCase();
        const matchedLine = selectedOrder?.lines.find((l) => l.item.sku.toLowerCase() === trimmed);
        if (!matchedLine) {
            message.warning(`No line on this sales order matches "${code}"`);
            return;
        }
        const index = fields.findIndex((f) => f.sales_order_line_id === matchedLine.id);
        if (index === -1) {
            message.info(`${matchedLine.item.sku} has nothing left to deliver on this order.`);
            return;
        }
        message.success(`Found line ${index + 1}: ${matchedLine.item.sku} — ${matchedLine.item.name}`);
    };

    useEffect(() => {
        const order = deliverableOrders.find((o) => o.id === selectedOrderId);
        if (!order) {
            replace([]);
            return;
        }

        const remainingLines = order.lines
            .filter((line) => Number(line.quantity) - Number(line.quantity_delivered) > 0)
            .map((line) => ({
                sales_order_line_id: line.id,
                item_label: `${line.item.sku} — ${line.item.name}`,
                quantity: Number(line.quantity) - Number(line.quantity_delivered),
            }));

        replace(remainingLines);
        // A carton queued against one order may be off the next one entirely.
        setScannedCartons([]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedOrderId]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['sales', 'deliveries'] });
        queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
    };

    const mutation = useMutation({
        mutationFn: createDelivery,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ lines: [] });
            setScannedCartons([]);
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not post delivery', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Deliveries</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Delivery</Button>
            </Space>

            <SalesFilterBar kind="delivery" filters={filters} onChange={setFilters} />

            <Table<Delivery>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                locale={{
                    emptyText: filtersActive ? 'No deliveries match these filters.' : 'No ERP-originated deliveries yet.',
                }}
                pagination={
                    data?.meta
                        ? {
                              current: data.meta.current_page,
                              pageSize: data.meta.per_page,
                              total: data.meta.total,
                              showSizeChanger: true,
                              pageSizeOptions: [20, 50, 100],
                              showTotal: (total) => `${total} deliver${total === 1 ? 'y' : 'ies'}`,
                              onChange: (page, pageSize) => setPage(page, pageSize),
                          }
                        : false
                }
                columns={[
                    { title: 'Number', render: (_, row) => <strong>{row.document_number ?? `DN-${row.id}`}</strong> },
                    {
                        title: 'SO',
                        render: (_, row) => (
                            <Button
                                type="link"
                                size="small"
                                style={{ padding: 0 }}
                                onClick={() => openTarget({ kind: 'sales_order', id: row.sales_order?.id ?? row.sales_order_id })}
                            >
                                {row.sales_order?.document_number ?? `SO-${row.sales_order_id}`}
                            </Button>
                        ),
                    },
                    { title: 'Customer', render: (_, row) => row.customer?.name ?? '—' },
                    { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                    { title: 'Delivered', dataIndex: 'delivered_date' },
                    { title: 'Reference', dataIndex: 'reference' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        // Boxes scanned out on this delivery; 0 means the
                        // quantities were typed, not that nothing left.
                        title: 'Cartons',
                        align: 'right',
                        render: (_, row) => row.carton_count ?? '—',
                    },
                    {
                        title: (
                            <Tooltip title="Where this delivery's Delivery Note stands in the Tally sync queue. A dash means no voucher was queued for it.">
                                <span>Tally</span>
                            </Tooltip>
                        ),
                        render: (_, row) => <TallyLinkCell link={row.tally} compact />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => openTarget({ kind: 'delivery', id: row.id })}>
                                View
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Delivery"
                open={modalOpen}
                onCancel={() => {
                    setModalOpen(false);
                    setScannedCartons([]);
                }}
                onOk={handleSubmit((values) =>
                    mutation.mutate({
                        sales_order_id: values.sales_order_id,
                        warehouse_id: values.warehouse_id,
                        reference: values.reference,
                        // Scanned cartons win: the delivery is then built from
                        // the physical boxes, not the typed quantities.
                        ...(scannedCartons.length > 0
                            ? { carton_codes: scannedCartons.map((c) => c.carton_no) }
                            : {
                                  lines: values.lines.map((l) => ({
                                      sales_order_line_id: l.sales_order_line_id,
                                      quantity: l.quantity,
                                  })),
                              }),
                    }),
                )}
                confirmLoading={mutation.isPending}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Sales Order"
                        validateStatus={errors.sales_order_id ? 'error' : ''}
                        help={errors.sales_order_id?.message}
                    >
                        <Controller
                            name="sales_order_id"
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

                    <Typography.Text strong>Lines to Deliver</Typography.Text>
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
                            Select a sales order with remaining quantity to deliver.
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
                        </Space>
                    ))}

                    {fields.length > 0 && (
                        <div style={{ marginTop: 16 }}>
                            <Typography.Text strong>Or dispatch by scanning cartons</Typography.Text>
                            <Form.Item style={{ marginTop: 8, marginBottom: 8 }}>
                                <BarcodeScanInput
                                    autoFocus={false}
                                    placeholder="Scan a carton barcode to add its box…"
                                    onScan={handleCartonScan}
                                />
                            </Form.Item>
                            {scannedCartons.length > 0 && (
                                <>
                                    <Alert
                                        type="info"
                                        showIcon
                                        style={{ marginBottom: 8 }}
                                        message={`The delivery will be built from these ${scannedCartons.length} scanned carton${scannedCartons.length === 1 ? '' : 's'} — ${scannedCartons
                                            .reduce((sum, c) => sum + Number(c.pieces), 0)
                                            .toLocaleString('en-IN')} pcs. The typed quantities above are ignored.`}
                                    />
                                    {scannedCartons.map((carton) => (
                                        <Space key={carton.carton_no} style={{ display: 'flex', marginTop: 4 }}>
                                            <Typography.Text code>{carton.carton_no}</Typography.Text>
                                            <span>
                                                {carton.item?.sku ?? '—'} · {Number(carton.pieces).toLocaleString('en-IN')} pcs
                                                {carton.is_partial ? ' ' : ''}
                                            </span>
                                            {carton.is_partial && <Tag color="warning">Partial</Tag>}
                                            {carton.quality?.verdict === 'pending' && <Tag>Awaiting QC/approval</Tag>}
                                            {carton.quality?.verdict === 'approved' && <Tag color="success">QC approved</Tag>}
                                            <Button
                                                size="small"
                                                type="link"
                                                danger
                                                onClick={() =>
                                                    setScannedCartons((prev) =>
                                                        prev.filter((c) => c.carton_no !== carton.carton_no),
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </Space>
                                    ))}
                                </>
                            )}
                        </div>
                    )}
                </Form>
            </Modal>

            {/* The trace drawer: the order it fulfils, lines, the cartons that
                physically left, and where its Delivery Note stands with Tally. */}
            <SalesDocumentDrawer target={target} onClose={closeTarget} onOpen={openTarget} />
        </>
    );
}
