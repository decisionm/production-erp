import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createInvoice, issueInvoice, listInvoices, listSalesOrders } from '@/features/sales/api';
import { hasActiveFilters } from '@/features/sales/filters';
import SalesDocumentDrawer, { TallyLinkCell } from '@/features/sales/SalesDocumentDrawer';
import SalesFilterBar from '@/features/sales/SalesFilterBar';
import TallyMirrorPanel from '@/features/sales/TallyMirrorPanel';
import type { Invoice, InvoiceStatus } from '@/features/sales/types';
import { useSalesListParams } from '@/features/sales/useSalesListParams';

const invoiceSchema = z.object({
    sales_order_id: z.number({ error: 'Sales order is required' }),
    invoice_date: z.string({ error: 'Invoice date is required' }),
    due_date: z.string().optional(),
    notes: z.string().optional(),
    lines: z
        .array(
            z.object({
                sales_order_line_id: z.number(),
                item_label: z.string(),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                unit_price: z.number().min(0),
            }),
        )
        .min(1, 'Selected sales order has no lines'),
});
type InvoiceFormValues = z.infer<typeof invoiceSchema>;

const statusColor: Record<InvoiceStatus, string> = {
    draft: 'default',
    issued: 'blue',
    paid: 'green',
};

export default function InvoicesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    // The filters, the page and the open drawer all live in the URL — a
    // pasted link is the same view. The server does the narrowing.
    const { filters, setFilters, setPage, target, openTarget, closeTarget } = useSalesListParams('invoice');
    const filtersActive = hasActiveFilters('invoice', filters);

    const { data, isLoading } = useQuery({
        queryKey: ['sales', 'invoices', 'list', filters],
        queryFn: () => listInvoices(filters),
        placeholderData: (previous) => previous,
    });
    const { data: orders } = useQuery({ queryKey: ['sales', 'sales-orders'], queryFn: listSalesOrders });

    // Only orders that have actually been committed to are invoiceable — not
    // draft (not yet confirmed) or cancelled. Mirrors DeliveriesPage's
    // equivalent filter, extended to include completed orders since most
    // invoicing happens after delivery.
    const invoiceableOrders = useMemo(
        () =>
            orders?.data.filter(
                (o) => o.status === 'confirmed' || o.status === 'partially_delivered' || o.status === 'completed',
            ) ?? [],
        [orders],
    );
    const orderOptions = invoiceableOrders.map((o) => ({ value: o.id, label: `SO #${o.id} — ${o.customer.name}` }));

    const { control, handleSubmit, reset, watch, formState: { errors } } = useForm<InvoiceFormValues>({
        resolver: zodResolver(invoiceSchema),
        defaultValues: { lines: [] },
    });
    const { fields, replace } = useFieldArray({ control, name: 'lines' });
    const selectedOrderId = watch('sales_order_id');

    useEffect(() => {
        const order = invoiceableOrders.find((o) => o.id === selectedOrderId);
        if (!order) {
            replace([]);
            return;
        }

        const lines = order.lines.map((line) => ({
            sales_order_line_id: line.id,
            item_label: `${line.item.sku} — ${line.item.name}`,
            quantity: Number(line.quantity),
            unit_price: Number(line.unit_price),
        }));

        replace(lines);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedOrderId]);

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['sales', 'invoices'] });

    const createMutation = useMutation({
        mutationFn: createInvoice,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset({ lines: [] });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create invoice', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });
    const issueMutation = useMutation({ mutationFn: issueInvoice, onSuccess: invalidate });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Invoices</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Invoice</Button>
            </Space>

            {/* What this list is NOT: real sales are invoiced in Tally and
                are not mirrored here (DEC-20260809-003) — the server's own
                words, so an empty table never reads as "no sales". */}
            <TallyMirrorPanel />

            <SalesFilterBar kind="invoice" filters={filters} onChange={setFilters} />

            <Table<Invoice>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                locale={{
                    emptyText: filtersActive ? 'No invoices match these filters.' : 'No ERP-originated invoices yet.',
                }}
                pagination={
                    data?.meta
                        ? {
                              current: data.meta.current_page,
                              pageSize: data.meta.per_page,
                              total: data.meta.total,
                              showSizeChanger: true,
                              pageSizeOptions: [20, 50, 100],
                              showTotal: (total) => `${total} invoice${total === 1 ? '' : 's'}`,
                              onChange: (page, pageSize) => setPage(page, pageSize),
                          }
                        : false
                }
                columns={[
                    { title: 'Number', render: (_, row) => <strong>{row.document_number ?? `INV-${row.id}`}</strong> },
                    {
                        title: (
                            // Paid is never set by this ERP: receipts are recorded
                            // in Tally (DEC-20260809-003). Said on the column so a
                            // long-issued invoice is not read as unpaid.
                            <Tooltip title="Paid: recorded in Tally, not here — this ERP never marks an invoice paid.">
                                <span>Status</span>
                            </Tooltip>
                        ),
                        dataIndex: 'status',
                        render: (status: InvoiceStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Customer', render: (_, row) => row.customer?.name ?? '—' },
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
                    { title: 'Invoice Date', dataIndex: 'invoice_date' },
                    { title: 'Due Date', dataIndex: 'due_date' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: (
                            <Tooltip title="Where this invoice's Sales voucher stands in the Tally sync queue. A dash means nothing is queued — a draft queues nothing until it is issued. Paid: recorded in Tally, not here.">
                                <span>Tally</span>
                            </Tooltip>
                        ),
                        render: (_, row) => <TallyLinkCell link={row.tally} compact />,
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => openTarget({ kind: 'invoice', id: row.id })}>
                                    View
                                </Button>
                                {row.status === 'draft' && (
                                    <Button
                                        size="small"
                                        onClick={() => issueMutation.mutate(row.id)}
                                        loading={issueMutation.isPending}
                                    >
                                        Issue
                                    </Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="New Invoice"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) =>
                    createMutation.mutate({
                        sales_order_id: values.sales_order_id,
                        invoice_date: values.invoice_date,
                        due_date: values.due_date,
                        notes: values.notes,
                        lines: values.lines.map((l) => ({
                            sales_order_line_id: l.sales_order_line_id,
                            quantity: l.quantity,
                            unit_price: l.unit_price,
                        })),
                    }),
                )}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={760}
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
                        label="Invoice Date"
                        validateStatus={errors.invoice_date ? 'error' : ''}
                        help={errors.invoice_date?.message}
                    >
                        <Controller
                            name="invoice_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Due Date">
                        <Controller
                            name="due_date"
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
                    {fields.length === 0 && (
                        <Typography.Paragraph type="secondary" style={{ marginTop: 8 }}>
                            Select a sales order to populate invoice lines.
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
                                name={`lines.${index}.unit_price`}
                                control={control}
                                render={({ field }) => <InputNumber {...field} min={0} placeholder="Unit Price" />}
                            />
                        </Space>
                    ))}
                </Form>
            </Modal>

            {/* The trace drawer: the order it bills, lines, and where its
                Sales voucher stands with Tally. */}
            <SalesDocumentDrawer target={target} onClose={closeTarget} onOpen={openTarget} />
        </>
    );
}
