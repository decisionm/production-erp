import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Form, Input, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { createBatch, getBatchLedger, listBatches, listItems } from '@/features/inventory/api';
import type { Batch, BatchLedger } from '@/features/inventory/types';

const batchSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    batch_number: z.string().min(1, 'Batch number is required').max(64),
    manufactured_date: z.string().optional(),
    expiry_date: z.string().optional(),
    notes: z.string().optional(),
});
type BatchFormValues = z.infer<typeof batchSchema>;

export default function BatchesPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [ledgerBatch, setLedgerBatch] = useState<Batch | null>(null);
    const [ledger, setLedger] = useState<BatchLedger | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({ queryKey: ['inventory', 'batches'], queryFn: () => listBatches() });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const itemOptions = items?.data.filter((i) => i.tracking_type === 'batch').map((i) => ({ value: i.id, label: `${i.sku} — ${i.name}` })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<BatchFormValues>({
        resolver: zodResolver(batchSchema),
    });

    const createMutation = useMutation({
        mutationFn: createBatch,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['inventory', 'batches'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not create batch', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const ledgerMutation = useMutation({
        mutationFn: getBatchLedger,
        onSuccess: setLedger,
        onError: (error: any) => {
            Modal.error({ title: 'Could not load ledger', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Batches</Typography.Title>
                <Button type="primary" onClick={() => setModalOpen(true)}>New Batch</Button>
            </Space>
            <Typography.Paragraph type="secondary">
                Only items with tracking type &quot;Batch / Lot&quot; can have batches — set that on the Items page first.
            </Typography.Paragraph>

            <Table<Batch>
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Batch Number', dataIndex: 'batch_number' },
                    { title: 'Manufactured', dataIndex: 'manufactured_date' },
                    { title: 'Expiry', dataIndex: 'expiry_date' },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button
                                size="small"
                                onClick={() => {
                                    setLedgerBatch(row);
                                    setLedger(null);
                                    ledgerMutation.mutate(row.id);
                                }}
                            >
                                View Ledger
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                title="New Batch"
                open={modalOpen}
                onCancel={() => setModalOpen(false)}
                onOk={handleSubmit((values) => createMutation.mutate(values))}
                confirmLoading={createMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Item" validateStatus={errors.item_id ? 'error' : ''} help={errors.item_id?.message}>
                        <Controller
                            name="item_id"
                            control={control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item
                        label="Batch Number"
                        validateStatus={errors.batch_number ? 'error' : ''}
                        help={errors.batch_number?.message}
                    >
                        <Controller name="batch_number" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                    <Form.Item label="Manufactured Date">
                        <Controller
                            name="manufactured_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Expiry Date">
                        <Controller
                            name="expiry_date"
                            control={control}
                            render={({ field }) => (
                                <DatePicker
                                    style={{ width: '100%' }}
                                    onChange={(_, dateString) => field.onChange((dateString as string) || undefined)}
                                />
                            )}
                        />
                    </Form.Item>
                    <Form.Item label="Notes">
                        <Controller name="notes" control={control} render={({ field }) => <Input.TextArea {...field} rows={2} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title={`Batch Ledger — ${ledgerBatch?.batch_number}`}
                open={ledgerBatch !== null}
                onCancel={() => setLedgerBatch(null)}
                footer={null}
                width={800}
                destroyOnHidden
            >
                {ledger && (
                    <>
                        <Typography.Text strong>On Hand</Typography.Text>
                        <Table
                            size="small"
                            rowKey="warehouse_id"
                            dataSource={ledger.on_hand}
                            pagination={false}
                            style={{ marginBottom: 16 }}
                            columns={[
                                { title: 'Warehouse', dataIndex: 'warehouse_code' },
                                { title: 'Quantity', dataIndex: 'quantity' },
                            ]}
                        />
                        <Typography.Text strong>Movements</Typography.Text>
                        <Table
                            size="small"
                            rowKey="id"
                            dataSource={ledger.movements}
                            pagination={false}
                            columns={[
                                { title: 'Date', dataIndex: 'movement_date' },
                                { title: 'Type', dataIndex: 'type', render: (t: string) => <Tag>{t}</Tag> },
                                { title: 'Warehouse', render: (_, row) => row.warehouse.code },
                                { title: 'Quantity', dataIndex: 'quantity' },
                                { title: 'Reference', dataIndex: 'reference' },
                            ]}
                        />
                    </>
                )}
            </Modal>
        </>
    );
}
