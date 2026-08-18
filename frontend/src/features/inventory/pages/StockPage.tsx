import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Drawer, Form, Input, InputNumber, message, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Link } from 'react-router-dom';
import { z } from 'zod';
import BarcodeScanInput from '@/components/barcode/BarcodeScanInput';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import {
    listBatches,
    listAllItems,
    listSerialNumbers,
    listStockBalances,
    listStockMovements,
    listAllWarehouses,
    recordIssue,
    recordReceipt,
    recordTransfer,
} from '@/features/inventory/api';
import type { StockBalance } from '@/features/inventory/types';
import { formatDateTime } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';

const movementTypeColor: Record<string, string> = {
    receipt: 'green',
    issue: 'red',
    transfer_in: 'blue',
    transfer_out: 'orange',
};

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
    const [historyRow, setHistoryRow] = useState<StockBalance | null>(null);
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);
    const financeAccess = hasModuleAccess(user, 'finance');

    const { data: balances, isLoading } = useQuery({
        queryKey: ['inventory', 'stock-balances'],
        queryFn: listStockBalances,
    });
    const { data: history, isLoading: historyLoading } = useQuery({
        queryKey: ['inventory', 'stock-movements', historyRow?.item.id, historyRow?.warehouse.id],
        queryFn: () =>
            listStockMovements({ item_id: historyRow!.item.id, warehouse_id: historyRow!.warehouse.id, per_page: 200 }),
        enabled: historyRow !== null,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });
    const { data: batches } = useQuery({ queryKey: ['inventory', 'batches'], queryFn: () => listBatches() });
    const { data: serialNumbers } = useQuery({ queryKey: ['inventory', 'serial-numbers'], queryFn: () => listSerialNumbers() });

    const itemOptions = items?.data.map((item) => ({ value: item.id, label: itemLabel(item) })) ?? [];
    const warehouseOptions = warehouses?.data.map((w) => ({ value: w.id, label: `${w.code} — ${w.name}` })) ?? [];

    /**
     * DO THESE ROWS CARRY RATES AT ALL — the server's answer, honoured locally
     * (the MaterialLotsPage precedent). average_cost / unit_cost are OMITTED
     * by StockBalanceResource / StockMovementResource for anyone without
     * finance access (FC-06), so their presence is the ruling that arrived
     * with the data; the permission check alongside can only make it
     * stricter. When false the cost columns do not exist — no '—' column
     * advertising a number it will not show.
     */
    const showsAverageCost =
        financeAccess && (balances?.data ?? []).some((row) => row.average_cost !== undefined);
    const showsUnitCost =
        financeAccess && (history?.data ?? []).some((row) => row.unit_cost !== undefined);

    const itemsById = new Map(items?.data.map((i) => [i.id, i]));
    const batchOptionsFor = (itemId?: number) =>
        batches?.data.filter((b) => b.item.id === itemId).map((b) => ({ value: b.id, label: b.batch_number })) ?? [];
    const serialOptionsFor = (itemId?: number, status: 'registered' | 'in_stock' = 'in_stock') =>
        serialNumbers?.data
            .filter((s) => s.item.id === itemId && s.status === status)
            .map((s) => ({ value: s.id, label: s.serial_number })) ?? [];

    // Serial numbers only count as a scan match while in the status that
    // action can actually use — the same rule the dropdown pickers already
    // follow (registered = receivable, in_stock = issuable/transferable).
    // Checked in priority order most-specific first: a serial or batch
    // match also tells us the item, but a bare SKU never tells us which
    // batch/serial, so item-only has to be the fallback.
    const resolveAndFillScan = (
        code: string,
        setValue: (name: 'item_id' | 'batch_id' | 'serial_number_id', value: number) => void,
        serialStatus: 'registered' | 'in_stock',
    ) => {
        const trimmed = code.trim().toLowerCase();

        const matchedSerial = serialNumbers?.data.find(
            (s) => s.serial_number.toLowerCase() === trimmed && s.status === serialStatus,
        );
        if (matchedSerial) {
            setValue('item_id', matchedSerial.item.id);
            setValue('serial_number_id', matchedSerial.id);
            message.success(`Matched serial ${matchedSerial.serial_number} — ${matchedSerial.item.sku}`);
            return;
        }

        const matchedBatch = batches?.data.find((b) => b.batch_number.toLowerCase() === trimmed);
        if (matchedBatch) {
            setValue('item_id', matchedBatch.item.id);
            setValue('batch_id', matchedBatch.id);
            message.success(`Matched batch ${matchedBatch.batch_number} — ${matchedBatch.item.sku}`);
            return;
        }

        const matchedItem = items?.data.find((i) => i.sku.toLowerCase() === trimmed);
        if (matchedItem) {
            setValue('item_id', matchedItem.id);
            message.success(`Matched item ${matchedItem.sku}`);
            return;
        }

        message.warning(`No item, batch, or serial number matches "${code}"`);
    };

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
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={balances?.data}
                pagination={false}
                columns={[
                    { title: 'Item', render: (_, row) => itemLabel(row.item) },
                    { title: 'Warehouse', render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}` },
                    { title: 'Quantity', dataIndex: 'quantity' },
                    ...(showsAverageCost ? [{ title: 'Avg. Cost', dataIndex: 'average_cost' }] : []),
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Button size="small" onClick={() => setHistoryRow(row)}>
                                View History
                            </Button>
                        ),
                    },
                ]}
            />

            <Modal
                maskClosable={false}
                title="Receive Stock"
                open={activeModal === 'receipt'}
                onCancel={() => setActiveModal(null)}
                onOk={receiptForm.handleSubmit((values) => receiptMutation.mutate(values))}
                confirmLoading={receiptMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Scan Barcode (item, batch, or serial number)">
                        <BarcodeScanInput
                            autoFocus={activeModal === 'receipt'}
                            onScan={(code) => resolveAndFillScan(code, receiptForm.setValue, 'registered')}
                        />
                    </Form.Item>
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
                    {/* Kept for every user, finance or not: the server REQUIRES
                        unit_cost on a manual receipt (StoreStockReceiptRequest),
                        because it feeds the balance's weighted average. Entering
                        it here is write-only — the same store user never reads it
                        back (the cost columns above are absent without finance
                        access), exactly the GRN path's rule. */}
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
                                    <Select
                                        {...field}
                                        options={serialOptionsFor(receiptItemId, 'registered')}
                                        showSearch
                                        optionFilterProp="label"
                                        allowClear
                                    />
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
                maskClosable={false}
                title="Issue Stock"
                open={activeModal === 'issue'}
                onCancel={() => setActiveModal(null)}
                onOk={issueForm.handleSubmit((values) => issueMutation.mutate(values))}
                confirmLoading={issueMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Scan Barcode (item, batch, or serial number)">
                        <BarcodeScanInput
                            autoFocus={activeModal === 'issue'}
                            onScan={(code) => resolveAndFillScan(code, issueForm.setValue, 'in_stock')}
                        />
                    </Form.Item>
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
                maskClosable={false}
                title="Transfer Stock"
                open={activeModal === 'transfer'}
                onCancel={() => setActiveModal(null)}
                onOk={transferForm.handleSubmit((values) => transferMutation.mutate(values))}
                confirmLoading={transferMutation.isPending}
                destroyOnHidden
            >
                <Form layout="vertical">
                    <Form.Item label="Scan Barcode (item, batch, or serial number)">
                        <BarcodeScanInput
                            autoFocus={activeModal === 'transfer'}
                            onScan={(code) => resolveAndFillScan(code, transferForm.setValue, 'in_stock')}
                        />
                    </Form.Item>
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

            <Drawer
                title={
                    historyRow
                        ? `${historyRow.item.sku} — ${historyRow.warehouse.code} history`
                        : 'History'
                }
                open={historyRow !== null}
                onClose={() => setHistoryRow(null)}
                width="min(100vw, 640px)"
                destroyOnHidden
            >
                {historyRow && (
                    <>
                        <Typography.Paragraph type="secondary">
                            Every movement recorded against {historyRow.item.sku} at{' '}
                            {historyRow.warehouse.code}, most recent first. For the full picture of what
                            each entry means, see{' '}
                            {/* This row's own item, not the generic list. */}
                            <Link to={`/inventory/items/${historyRow.item.id}`}>
                                {historyRow.item.sku}'s detail page
                            </Link>
                            .
                        </Typography.Paragraph>
                        <Table
                            rowKey="id"
                            size="small"
                            loading={historyLoading}
                            pagination={false}
                            dataSource={history?.data}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                {
                                    title: 'Date',
                                    dataIndex: 'movement_date',
                                    render: (d: string) => formatDateTime(d),
                                },
                                {
                                    title: 'Type',
                                    dataIndex: 'type',
                                    render: (type: string) => <Tag color={movementTypeColor[type]}>{type}</Tag>,
                                },
                                { title: 'Quantity', dataIndex: 'quantity' },
                                ...(showsUnitCost ? [{ title: 'Unit Cost', dataIndex: 'unit_cost' }] : []),
                                { title: 'Reference', dataIndex: 'reference' },
                                { title: 'Notes', dataIndex: 'notes', render: (n: string | null) => n ?? '—' },
                            ]}
                        />
                    </>
                )}
            </Drawer>
        </>
    );
}
