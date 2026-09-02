import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Drawer, Form, Input, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import BarcodeDisplay from '@/components/barcode/BarcodeDisplay';
import { createSerialNumber, getSerialNumberHistory, listAllItems, listSerialNumbers } from '@/features/inventory/api';
import {
    SERIAL_NUMBER_DEFAULT_SORT,
    SERIAL_NUMBER_LIST_SPEC,
    SERIAL_NUMBER_SORT_FIELDS,
    type SerialNumberListParams,
    serialNumberListRequest,
} from '@/features/inventory/serialNumberList';
import type { SerialNumber, SerialNumberStatus } from '@/features/inventory/types';
import { itemLabel } from '@/lib/itemLabel';
import { TABLE_STICKY, serverPagination } from '@/lib/tableProps';
import { columnSortOrder, sortParamFromSorter } from '@/lib/tableSort';
import { useListParams } from '@/lib/useListParams';

const serialSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    serial_number: z.string().min(1, 'Serial number is required').max(64),
});
type SerialFormValues = z.infer<typeof serialSchema>;

const statusColor: Record<SerialNumberStatus, string> = {
    registered: 'default',
    in_stock: 'green',
    consumed: 'blue',
    sold: 'purple',
    scrapped: 'red',
};

export default function SerialNumbersPage() {
    const [modalOpen, setModalOpen] = useState(false);
    const [historyItem, setHistoryItem] = useState<SerialNumber | null>(null);
    const [history, setHistory] = useState<SerialNumber | null>(null);
    const [barcodeSerial, setBarcodeSerial] = useState<SerialNumber | null>(null);
    const queryClient = useQueryClient();

    // Server-side paging, search and ordering, in the URL, for the reason the
    // batch list has them: the table showed the newest twenty and nothing
    // said there were more.
    const { params, setParams, setPage } = useListParams<SerialNumberListParams>(SERIAL_NUMBER_LIST_SPEC);
    const request = serialNumberListRequest(params);
    const [qDraft, setQDraft] = useState(params.q ?? '');
    useEffect(() => {
        setQDraft(params.q ?? '');
    }, [params.q]);

    const { data, isLoading } = useQuery({
        queryKey: ['inventory', 'serial-numbers', request],
        queryFn: () => listSerialNumbers(request),
        placeholderData: (previous) => previous,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const itemOptions = items?.data.filter((i) => i.tracking_type === 'serial').map((i) => ({ value: i.id, label: itemLabel(i) })) ?? [];

    const { control, handleSubmit, reset, formState: { errors } } = useForm<SerialFormValues>({
        resolver: zodResolver(serialSchema),
    });

    const createMutation = useMutation({
        mutationFn: createSerialNumber,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['inventory', 'serial-numbers'] });
            setModalOpen(false);
            reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not register serial number', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const historyMutation = useMutation({
        mutationFn: getSerialNumberHistory,
        onSuccess: setHistory,
        onError: (error: any) => {
            Modal.error({ title: 'Could not load history', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Serial Numbers</Typography.Title>
                <Space>
                    <Input.Search
                        allowClear
                        placeholder="Serial number or item"
                        style={{ width: 260 }}
                        value={qDraft}
                        onChange={(event) => setQDraft(event.target.value)}
                        onSearch={(value) => setParams({ q: value.trim() || undefined })}
                    />
                    <Button type="primary" onClick={() => setModalOpen(true)}>Register Serial Number</Button>
                </Space>
            </Space>
            <Typography.Paragraph type="secondary">
                Only items with tracking type &quot;Serial Number&quot; can have serial numbers — set that on the
                Items page first. A registered serial number isn&apos;t in stock until it&apos;s received via a
                stock receipt referencing it.
            </Typography.Paragraph>

            <Table<SerialNumber>
                sticky={TABLE_STICKY}
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={data?.data}
                // SORTED BY THE SERVER: sortOrder-controlled, re-queries the
                // whole list. Item and Warehouse render relations and carry
                // no sorter.
                onChange={(_pagination, _filters, sorter, extra) => {
                    if (extra.action !== 'sort') return;
                    setParams({ sort: sortParamFromSorter(sorter, SERIAL_NUMBER_SORT_FIELDS, SERIAL_NUMBER_DEFAULT_SORT) });
                }}
                pagination={serverPagination(data?.meta, setPage, 'serial numbers')}
                columns={[
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
                    {
                        title: 'Serial Number',
                        dataIndex: 'serial_number',
                        key: 'serial_number',
                        sorter: true,
                        sortOrder: columnSortOrder('serial_number', params.sort, SERIAL_NUMBER_DEFAULT_SORT),
                    },
                    {
                        title: 'Status',
                        dataIndex: 'status',
                        key: 'status',
                        sorter: true,
                        sortOrder: columnSortOrder('status', params.sort, SERIAL_NUMBER_DEFAULT_SORT),
                        render: (status: SerialNumberStatus) => <Tag color={statusColor[status]}>{status}</Tag>,
                    },
                    { title: 'Warehouse', render: (_, row) => row.warehouse?.code ?? '—' },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button
                                    size="small"
                                    onClick={() => {
                                        setHistoryItem(row);
                                        setHistory(null);
                                        historyMutation.mutate(row.id);
                                    }}
                                >
                                    View History
                                </Button>
                                <Button size="small" onClick={() => setBarcodeSerial(row)}>
                                    Barcode
                                </Button>
                            </Space>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="Register Serial Number"
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
                        label="Serial Number"
                        validateStatus={errors.serial_number ? 'error' : ''}
                        help={errors.serial_number?.message}
                    >
                        <Controller name="serial_number" control={control} render={({ field }) => <Input {...field} />} />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                maskClosable={false}
                title={`History — ${historyItem?.serial_number}`}
                open={historyItem !== null}
                onCancel={() => setHistoryItem(null)}
                footer={null}
                width={700}
                destroyOnHidden
            >
                {history && (
                    <Table
                        scroll={{ x: 'max-content' }}
                        size="small"
                        rowKey="id"
                        dataSource={history.movements}
                        pagination={false}
                        columns={[
                            { title: 'Date', dataIndex: 'movement_date' },
                            { title: 'Type', dataIndex: 'type', render: (t: string) => <Tag>{t}</Tag> },
                            { title: 'Warehouse', render: (_, row) => row.warehouse.code },
                            { title: 'Quantity', dataIndex: 'quantity' },
                            { title: 'Reference', dataIndex: 'reference' },
                        ]}
                    />
                )}
            </Modal>

            <Drawer
                title={`Barcode — ${barcodeSerial?.serial_number}`}
                open={barcodeSerial !== null}
                onClose={() => setBarcodeSerial(null)}
                width="min(100vw, 420px)"
                destroyOnHidden
            >
                {barcodeSerial && (
                    <BarcodeDisplay code={barcodeSerial.serial_number} label={barcodeSerial.item.sku} />
                )}
            </Drawer>
        </>
    );
}
