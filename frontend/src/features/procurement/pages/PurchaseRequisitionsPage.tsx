import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';
import { listItems } from '@/features/inventory/api';
import {
    approvePurchaseRequisition,
    createPurchaseRequisition,
    listPurchaseRequisitions,
    rejectPurchaseRequisition,
} from '@/features/procurement/api';
import type { PurchaseRequisition, PurchaseRequisitionStatus } from '@/features/procurement/types';

const requisitionSchema = z.object({
    needed_by_date: z.string().optional(),
    notes: z.string().optional(),
    lines: z
        .array(
            z.object({
                item_id: z.number({ error: 'Item is required' }),
                quantity: z.number().gt(0, 'Quantity must be greater than 0'),
                notes: z.string().optional(),
            }),
        )
        .min(1, 'Add at least one line'),
});
type RequisitionFormValues = z.infer<typeof requisitionSchema>;

const statusColor: Record<PurchaseRequisitionStatus, string> = {
    draft: 'default',
    approved: 'green',
    rejected: 'red',
};

export default function PurchaseRequisitionsPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [detailRequisition, setDetailRequisition] = useState<PurchaseRequisition | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['procurement', 'purchase-requisitions'],
        queryFn: listPurchaseRequisitions,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const itemOptions = items?.data.map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<RequisitionFormValues>({
        resolver: zodResolver(requisitionSchema),
        defaultValues: { lines: [{ item_id: undefined, quantity: undefined, notes: '' }] },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'lines' });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['procurement', 'purchase-requisitions'] });

    const createMutation = useMutation({
        mutationFn: createPurchaseRequisition,
        onSuccess: () => {
            invalidate();
            setModalOpen(false);
            reset();
        },
    });
    const approveMutation = useMutation({ mutationFn: approvePurchaseRequisition, onSuccess: invalidate });
    const rejectMutation = useMutation({ mutationFn: rejectPurchaseRequisition, onSuccess: invalidate });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Purchase Requisitions</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Requisition</Button>
            </Space>

            <Table<PurchaseRequisition>
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
                        render: (status: PurchaseRequisitionStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Requested By', dataIndex: 'requested_by' },
                    { title: 'Needed By', dataIndex: 'needed_by_date' },
                    { title: 'Lines', render: (_, row) => row.lines.length },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailRequisition(row)}>
                                    View
                                </Button>
                                {row.status === 'draft' && (
                                    <>
                                        <Button
                                            size="small"
                                            onClick={() => approveMutation.mutate(row.id)}
                                            loading={approveMutation.isPending}
                                        >
                                            Approve
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
                maskClosable={false}
                title="New Purchase Requisition"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
                width={700}
            >
                <Form layout="vertical">
                    <Form.Item label="Needed By">
                        <Controller
                            name="needed_by_date"
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
                                        style={{ width: 260 }}
                                        placeholder="Item"
                                    />
                                )}
                            />
                            <Controller
                                name={`lines.${index}.quantity`}
                                control={control}
                                render={({ field }) => (
                                    <InputNumber {...field} min={0} placeholder="Quantity" />
                                )}
                            />
                            <Button danger onClick={() => remove(index)}>Remove</Button>
                        </Space>
                    ))}
                    <Button
                        type="dashed"
                        style={{ marginTop: 8 }}
                        onClick={() => append({ item_id: undefined as unknown as number, quantity: undefined as unknown as number, notes: '' })}
                    >
                        Add Line
                    </Button>
                </Form>
            </Modal>

            <Drawer
                title={`Purchase Requisition #${detailRequisition?.id}`}
                open={detailRequisition !== null}
                onClose={() => setDetailRequisition(null)}
                width={560}
                destroyOnHidden
            >
                {detailRequisition && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                <Tag color={statusColor[detailRequisition.status]}>{detailRequisition.status}</Tag>
                            </Descriptions.Item>
                            <Descriptions.Item label="Requested By">
                                {detailRequisition.requested_by ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Needed By">
                                {detailRequisition.needed_by_date ?? '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailRequisition.notes ?? '—'}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>
                            Lines
                        </Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailRequisition.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                { title: 'Item', render: (_, line) => `${line.item.sku} — ${line.item.name}` },
                                { title: 'Quantity', dataIndex: 'quantity' },
                                { title: 'Notes', dataIndex: 'notes' },
                            ]}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
