import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, DatePicker, Drawer, Form, Input, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
import { createBatch, getBatchLedger, listBatches, listAllItems } from '@/features/inventory/api';
import {
    BATCH_DEFAULT_SORT,
    BATCH_LIST_SPEC,
    BATCH_SORT_FIELDS,
    type BatchListParams,
    batchListRequest,
} from '@/features/inventory/batchList';
import type { Batch, BatchLedger } from '@/features/inventory/types';
import { itemLabel } from '@/lib/itemLabel';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

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
    const [barcodeBatch, setBarcodeBatch] = useState<Batch | null>(null);
    const queryClient = useQueryClient();

    // Server-side paging, search AND ordering, all in the URL (useListParams).
    // The table read the first twenty batches — newest first — so every older
    // lot was invisible, and a browser-side filter over those twenty could not
    // find one either; a sorter over them would have been the same defect.
    const { params, setParams, setPage } = useListParams<BatchListParams>(BATCH_LIST_SPEC);
    const request = batchListRequest(params);
    // What is typed; the URL (and the server) hear it on Enter or the button.
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => {
        setQDraft(params.q ?? '');
    }, [params.q]);

    const { data, isLoading } = useQuery({
        queryKey: ['inventory', 'batches', request],
        queryFn: () => listBatches(request),
        placeholderData: (previous) => previous,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const itemOptions = items?.data.filter((i) => i.tracking_type === 'batch').map((i) => ({ value: i.id, label: itemLabel(i) })) ?? [];

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
                <Space>
                    <Input.Search
                        allowClear
                        placeholder="Batch number or item"
                        style={{ width: 260 }}
                        value={qDraft}
                        onChange={(event) => setQDraft(event.target.value)}
                        onSearch={(value) => setParams({ q: value.trim() || undefined })}
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>New Batch</Button>
                </Space>
            </Space>
            <Typography.Paragraph type="secondary">
                Only items with tracking type &quot;Batch / Lot&quot; can have batches — set that on the Items page first.
            </Typography.Paragraph>

            <Table<Batch>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queries the
                // whole list. Item renders a relation's label and has no
                // server order, so it carries no sorter.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, BATCH_SORT_FIELDS, BATCH_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'batches')}
                columns={[
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
                    {
                        title: 'Batch Number',
                        dataIndex: 'batch_number',
                        key: 'batch_number',
                        sorter: true,
                        sortOrder: columnSortOrder('batch_number', params.sort, BATCH_DEFAULT_SORT),
                    },
                    {
                        title: 'Manufactured',
                        dataIndex: 'manufactured_date',
                        key: 'manufactured_date',
                        sorter: true,
                        sortOrder: columnSortOrder('manufactured_date', params.sort, BATCH_DEFAULT_SORT),
                    },
                    {
                        title: 'Expiry',
                        dataIndex: 'expiry_date',
                        key: 'expiry_date',
                        sorter: true,
                        sortOrder: columnSortOrder('expiry_date', params.sort, BATCH_DEFAULT_SORT),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
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
                                <Button size="small" onClick={() => setBarcodeBatch(row)}>
                                    Barcode
                                </Button>
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
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
                maskClosable={false}
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
                            scroll={{ x: 'max-content' }}
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
                            scroll={{ x: 'max-content' }}
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

            <Drawer
                title={`Barcode — ${barcodeBatch?.batch_number}`}
                open={barcodeBatch !== null}
                onClose={() => setBarcodeBatch(null)}
                width="min(100vw, 420px)"
                destroyOnHidden
            >
                {barcodeBatch && (
                    <BarcodeDisplay code={barcodeBatch.batch_number} label={barcodeBatch.item.sku} />
                )}
            </Drawer>
        </>
    );
}
