import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import {
    listBatches,
    listItems,
    listSerialNumbers,
    listStockBalances,
    listWarehouses,
    recordIssue,
    recordReceipt,
    recordTransfer,
} from '@/features/inventory/api';
import type { StockBalance } from '@/features/inventory/types';

const receiptSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    quantity: z.number().gt(0, 'Quantity must be greater than 0'),
    unit_cost: z.number().min(0),
    reference: z.string().optional(),
    batch_id: z.number().optional(),
    serial_number_id: z.number().optional(),
});
type ReceiptFormValues = z.infer<typeof receiptSchema>;

const issueSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    warehouse_id: z.number({ error: 'Warehouse is required' }),
    quantity: z.number().gt(0, 'Quantity must be greater than 0'),
    reference: z.string().optional(),
    batch_id: z.number().optional(),
    serial_number_id: z.number().optional(),
});
type IssueFormValues = z.infer<typeof issueSchema>;

const transferSchema = z.object({
    item_id: z.number({ error: 'Item is required' }),
    from_warehouse_id: z.number({ error: 'Source warehouse is required' }),
    to_warehouse_id: z.number({ error: 'Destination warehouse is required' }),
    quantity: z.number().gt(0, 'Quantity must be greater than 0'),
    reference: z.string().optional(),
    batch_id: z.number().optional(),
    serial_number_id: z.number().optional(),
});
type TransferFormValues = z.infer<typeof transferSchema>;

type ActiveModal = 'receipt' | 'issue' | 'transfer' | null;

export default function StockPage() {
    const [activeModal, setActiveModal] = useState<ActiveModal>(null);
    const queryClient = useQueryClient();

    const { data: balances, isLoading } = useQuery({
        queryKey: ['inventory', 'stock-balances'],
        queryFn: listStockBalances,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items'], queryFn: listItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses'], queryFn: listWarehouses });
    const { data: batches } = useQuery({ queryKey: ['inventory', 'batches'], queryFn: () => listBatches() });
    const { data: serialNumbers } = useQuery({ queryKey: ['inventory', 'serial-numbers'], queryFn: () => listSerialNumbers() });

    const itemOptions = items?.data.map((item) => ({ value: item.id, label: `${item.sku} — ${item.name}` })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    const itemsById = new Map(items?.data.map((i) => [i.id, i]));
    const batchOptionsFor = (itemId?: number) =>
        batches?.data.filter((b) => b.item.id === itemId).map((b) => ({ value: b.id, label: b.batch_number })) ?? [];
    const serialOptionsFor = (itemId?: number) =>
        serialNumbers?.data
            .filter((s) => s.item.id === itemId && s.status === 'in_stock')
            .map((s) => ({ value: s.id, label: s.serial_number })) ?? [];

    const invalidateStock = () => {
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-balances'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'stock-movements'] });
        queryClient.invalidateQueries({ queryKey: ['inventory', 'serial-numbers'] });
    };

    const receiptForm = useForm<ReceiptFormValues>({ resolver: zodResolver(receiptSchema) });
    const receiptItemId = receiptForm.watch('item_id');
    const receiptTrackingType = itemsById.get(receiptItemId)?.tracking_type ?? 'none';
    const receiptMutation = useMutation({
        mutationFn: recordReceipt,
        onSuccess: () => {
            invalidateStock();
            setActiveModal(null);
            receiptForm.reset();
        },
    });

    const issueForm = useForm<IssueFormValues>({ resolver: zodResolver(issueSchema) });
    const issueItemId = issueForm.watch('item_id');
    const issueTrackingType = itemsById.get(issueItemId)?.tracking_type ?? 'none';
    const issueMutation = useMutation({
        mutationFn: recordIssue,
        onSuccess: () => {
            invalidateStock();
            setActiveModal(null);
            issueForm.reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not record issue', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    const transferForm = useForm<TransferFormValues>({ resolver: zodResolver(transferSchema) });
    const transferItemId = transferForm.watch('item_id');
    const transferTrackingType = itemsById.get(transferItemId)?.tracking_type ?? 'none';
    const transferMutation = useMutation({
        mutationFn: recordTransfer,
        onSuccess: () => {
            invalidateStock();
            setActiveModal(null);
            transferForm.reset();
        },
        onError: (error: any) => {
            Modal.error({ title: 'Could not record transfer', content: error?.response?.data?.message ?? 'Unknown error' });
        },
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Stock</Typography.Title>
                <Space>
                    <Button onClick={() => setActiveModal('receipt')}>Receive Stock</Button>
                    <Button onClick={() => setActiveModal('issue')}>Issue Stock</Button>
                    <Button onClick={() => setActiveModal('transfer')}>Transfer Stock</Button>
                </Space>
            </Space>

            <Table<StockBalance>
                rowKey="id"
                loading={isLoading}
                dataSource={balances?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => `${row.item.sku} — ${row.item.name}` },
                    { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                    { title: 'Quantity', dataIndex: 'quantity' },
                    { title: 'Avg. Cost', dataIndex: 'average_cost' },
                ]}
            />

            <Modal
                title="Receive Stock"
                open={activeModal === 'receipt'}
                onCancel={() => setActiveModal(null)}
                onOk={receiptForm.handleSubmit((values) => receiptMutation.mutate(values))}
                confirmLoading={receiptMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Item">
                        <Controller
                            name="item_id"
                            control={receiptForm.control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Warehouse">
                        <Controller
                            name="warehouse_id"
                            control={receiptForm.control}
                            render={({ field }) => <Select {...field} options={warehouseOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Quantity">
                        <Controller
                            name="quantity"
                            control={receiptForm.control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    <Form.Item label="Unit Cost">
                        <Controller
                            name="unit_cost"
                            control={receiptForm.control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    {receiptTrackingType === 'batch' && (
                        <Form.Item label="Batch (create one first on the Batches page if needed)">
                            <Controller
                                name="batch_id"
                                control={receiptForm.control}
                                render={({ field }) => (
                                    <Select {...field} options={batchOptionsFor(receiptItemId)} showSearch optionFilterProp="label" allowClear />
                                )}
                            />
                        </Form.Item>
                    )}
                    {receiptTrackingType === 'serial' && (
                        <Form.Item label="Serial Number (register one first on the Serial Numbers page if needed)">
                            <Controller
                                name="serial_number_id"
                                control={receiptForm.control}
                                render={({ field }) => (
                                    <Select {...field} options={serialOptionsFor(receiptItemId)} showSearch optionFilterProp="label" allowClear />
                                )}
                            />
                        </Form.Item>
                    )}
                    <Form.Item label="Reference">
                        <Controller
                            name="reference"
                            control={receiptForm.control}
                            render={({ field }) => <Input {...field} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title="Issue Stock"
                open={activeModal === 'issue'}
                onCancel={() => setActiveModal(null)}
                onOk={issueForm.handleSubmit((values) => issueMutation.mutate(values))}
                confirmLoading={issueMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Item">
                        <Controller
                            name="item_id"
                            control={issueForm.control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Warehouse">
                        <Controller
                            name="warehouse_id"
                            control={issueForm.control}
                            render={({ field }) => <Select {...field} options={warehouseOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Quantity">
                        <Controller
                            name="quantity"
                            control={issueForm.control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    {issueTrackingType === 'batch' && (
                        <Form.Item label="Batch">
                            <Controller
                                name="batch_id"
                                control={issueForm.control}
                                render={({ field }) => (
                                    <Select {...field} options={batchOptionsFor(issueItemId)} showSearch optionFilterProp="label" allowClear />
                                )}
                            />
                        </Form.Item>
                    )}
                    {issueTrackingType === 'serial' && (
                        <Form.Item label="Serial Number">
                            <Controller
                                name="serial_number_id"
                                control={issueForm.control}
                                render={({ field }) => (
                                    <Select {...field} options={serialOptionsFor(issueItemId)} showSearch optionFilterProp="label" allowClear />
                                )}
                            />
                        </Form.Item>
                    )}
                    <Form.Item label="Reference">
                        <Controller
                            name="reference"
                            control={issueForm.control}
                            render={({ field }) => <Input {...field} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>

            <Modal
                title="Transfer Stock"
                open={activeModal === 'transfer'}
                onCancel={() => setActiveModal(null)}
                onOk={transferForm.handleSubmit((values) => transferMutation.mutate(values))}
                confirmLoading={transferMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Item">
                        <Controller
                            name="item_id"
                            control={transferForm.control}
                            render={({ field }) => <Select {...field} options={itemOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="From Warehouse">
                        <Controller
                            name="from_warehouse_id"
                            control={transferForm.control}
                            render={({ field }) => <Select {...field} options={warehouseOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="To Warehouse">
                        <Controller
                            name="to_warehouse_id"
                            control={transferForm.control}
                            render={({ field }) => <Select {...field} options={warehouseOptions} showSearch optionFilterProp="label" />}
                        />
                    </Form.Item>
                    <Form.Item label="Quantity">
                        <Controller
                            name="quantity"
                            control={transferForm.control}
                            render={({ field }) => <InputNumber {...field} min={0} style={{ width: '100%' }} />}
                        />
                    </Form.Item>
                    {transferTrackingType === 'batch' && (
                        <Form.Item label="Batch">
                            <Controller
                                name="batch_id"
                                control={transferForm.control}
                                render={({ field }) => (
                                    <Select {...field} options={batchOptionsFor(transferItemId)} showSearch optionFilterProp="label" allowClear />
                                )}
                            />
                        </Form.Item>
                    )}
                    {transferTrackingType === 'serial' && (
                        <Form.Item label="Serial Number">
                            <Controller
                                name="serial_number_id"
                                control={transferForm.control}
                                render={({ field }) => (
                                    <Select {...field} options={serialOptionsFor(transferItemId)} showSearch optionFilterProp="label" allowClear />
                                )}
                            />
                        </Form.Item>
                    )}
                    <Form.Item label="Reference">
                        <Controller
                            name="reference"
                            control={transferForm.control}
                            render={({ field }) => <Input {...field} />}
                        />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
