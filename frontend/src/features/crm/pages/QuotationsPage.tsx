import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import {
    acceptQuotation,
    createQuotation,
    listOpportunities,
    listQuotations,
    rejectQuotation,
    sendQuotation,
} from '@/features/crm/api';
import type { Quotation, QuotationStatus } from '@/features/crm/types';
import { listItems } from '@/features/inventory/api';

const quotationSchema = z.object({
    opportunity_id: z.number({ error: 'Opportunity is required' }),
    quotation_date: z.string({ error: 'Quotation date is required' }),
    valid_until: z.string().optional(),
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
type QuotationFormValues = z.infer<typeof quotationSchema>;

const statusColor: Record<QuotationStatus, string> = {
    draft: 'default',
    sent: 'blue',
    accepted: 'green',
    rejected: 'red',
    expired: 'gold',
};

export default function QuotationsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['crm', 'quotations'], queryFn: listQuotations });
    const { data: opportunities } = useQuery({ queryKey: ['crm', 'opportunities'], queryFn: listOpportunities });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });

    const opportunityOptions =
        opportunities?.data.map((o) => ({ value: o.id, label: `${o.name} — ${o.customer.name}` })) ?? [];
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<QuotationFormValues>({
        resolver: zodResolver(quotationSchema),
        defaultValues: { lines: [{ item_id: undefined, quantity: undefined, unit_price: undefined }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['crm', 'quotations'] });

    const createMutation = useMutation({
        mutationFn: createQuotation,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });

    const sendMutation = useMutation({ mutationFn: sendQuotation, onSuccess: invalidate });
    const rejectMutation = useMutation({ mutationFn: rejectQuotation, onSuccess: invalidate });

    const acceptMutation = useMutation({
        mutationFn: acceptQuotation,
        onSuccess: ({ sales_order }) => {
            invalidate();
            queryClient.invalidateQueries({ queryKey: ['sales', 'sales-orders'] });
            Modal.success({
                title: 'Quotation accepted',
                content: `Sales Order #${sales_order.id} was created from this quotation.`,
            });
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not accept quotation', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Quotations</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Quotation</Button>
            </Space>

            <Table<Quotation>
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
                        render: (status: QuotationStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Customer', render: (_, row) => row.customer.name },
                    { title: 'Quotation Date', dataIndex: 'quotation_date' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                {row.status === 'draft' && (
                                    <Button size="small" onClick={() => sendMutation.mutate(row.id)} loading={sendMutation.isPending}>
                                        Send
                                    </Button>
                                )}
                                {row.status === 'sent' && (
                                    <>
                                        <Button
                                            size="small"
                                            type="primary"
                                            onClick={() => acceptMutation.mutate(row.id)}
                                            loading={acceptMutation.isPending}
                                        >
                                            Accept
                                        </Button>
                                        <Button
                                            size="small"
                                            danger
                                            onClick={() => rejectMutation.mutate(row.id)}
                                            loading={rejectMutation.isPending}
                                        >
                                            Reject
                                        </Button>
                                    </>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                title="New Quotation"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={760}
            >
                <Form layout="vertical">
                    <Form.Item
                        label="Opportunity"
                        validateStatus={errors.opportunity_id ? 'error' : ''}
                        help={errors.opportunity_id?.message}
                    >
                        <Controller
                            name="opportunity_id"
                            control={control}
                            render={({ field }) => (
                                <Select {...field} options={opportunityOptions} showSearch optionFilterProp="label" />
                            )}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Quotation Date"
                        validateStatus={errors.quotation_date ? 'error' : ''}
                        help={errors.quotation_date?.message}
                    >
                        <Controller
                            name="quotation_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange(dateString || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Valid Until">
                        <Controller
                            name="valid_until"
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
        </>
    );
}
