import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createInvoice, issueInvoice, listInvoices, listSalesOrders } from '@/features/sales/api';
import type { Invoice, InvoiceStatus } from '@/features/sales/types';

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

    const { data, isLoading } = useQuery({ queryKey: ['sales', 'invoices'], queryFn: listInvoices });
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

            <Table<Invoice>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'ID', dataIndex: 'id' },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        render: (status: InvoiceStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Customer', render: (_, row) => row.customer.name },
                    { title: 'Invoice Date', dataIndex: 'invoice_date' },
                    { title: 'Due Date', dataIndex: 'due_date' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: 'Actions',
                        render: (_, row) =>
                            row.status === 'draft' && (
                                <Button
                                    size="small"
                                    onClick={() => issueMutation.mutate(row.id)}
                                    loading={issueMutation.isPending}
                                >
                                    Issue
                                </Button>
                            ),
                    },
                ]}
            />

            <Modal
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
        </>
    );
}
