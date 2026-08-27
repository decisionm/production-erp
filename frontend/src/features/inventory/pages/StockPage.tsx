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
    listAllBatches,
    listAllItems,
    listAllSerialNumbers,
    listStockBalances,
    listStockMovements,
    listAllWarehouses,
    recordIssue,
    recordReceipt,
    recordTransfer,
} from '@/features/inventory/api';
import { resolveStockScan, serverScanLookups } from '@/features/inventory/stockScan';
import { identityRefusal } from '@/features/inventory/trackingIdentity';
import type { SerialNumberStatus, StockBalance } from '@/features/inventory/types';
import { formatDateTime } from '@/lib/datetime';
import { activePickerOptions } from '@/components/configuration/pickerOptions';
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

/** Just enough of a react-hook-form to clear an identity picker. */
interface IdentityHolder {
    setValue: (name: 'batch_id' | 'serial_number_id', value: undefined) => void;
}

export default function StockPage() {
    const [activeModal, setActiveModal] = useState<ActiveModal>(null);
    const [historyRow, setHistoryRow] = useState<StockBalance | null>(null);
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);
    const financeAccess = hasModuleAccess(user, 'finance');

    // Server-side paging. This list is one row per item×warehouse and the
    // factory's is far past a screenful, so the page number is part of the
    // query key and the pager below reads the server's own count.
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);

    const { data: balances, isLoading } = useQuery({
        queryKey: ['inventory', 'stock-balances', page, perPage],
        queryFn: () => listStockBalances({ page, per_page: perPage }),
    });
    const { data: history, isLoading: historyLoading } = useQuery({
        queryKey: ['inventory', 'stock-movements', historyRow?.item.id, historyRow?.warehouse.id],
        queryFn: () =>
            listStockMovements({ item_id: historyRow!.item.id, warehouse_id: historyRow!.warehouse.id, per_page: 200 }),
        enabled: historyRow !== null,
    });
    const { data: items } = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    const { data: warehouses } = useQuery({ queryKey: ['inventory', 'warehouses', 'all'], queryFn: listAllWarehouses });

    // WS-B: Item and Warehouse are filtered on every stock WRITE path now
    // (receipt, issue, transfer), so the three modals below stop offering a
    // retired item or a retired store. Only the modals use these lists — the
    // balances table and the movement history read whatever the ledger
    // recorded, retired or not, and must keep doing so.
    const itemOptions = activePickerOptions(items?.data, {
        isActive: (item) => item.is_active,
        option: (item) => ({ value: item.id, label: itemLabel(item) }),
    });
    const warehouseOptions = activePickerOptions(warehouses?.data, {
        isActive: (w) => w.is_active,
        option: (w) => ({ value: w.id, label: `${w.code} — ${w.name}` }),
    });

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

    /*
     * A SCAN RESOLVES THE WHOLE IDENTIFIER ON THE SERVER — not a `.find()`
     * over twenty rows, and not a substring search over fifty either. Both of
     * those made the answer depend on how many other numbers contain the
     * scanned one, which is not a fact about the box in someone's hand.
     * serverScanLookups holds the wiring and resolveStockScan the matching
     * rules, both tested without a network.
     */
    const scanLookups = serverScanLookups(items?.data ?? []);

    const resolveAndFillScan = (
        code: string,
        setValue: (name: 'item_id' | 'batch_id' | 'serial_number_id', value?: number) => void,
        serialStatus: SerialNumberStatus,
    ) => {
        resolveStockScan(code, serialStatus, scanLookups)
            .then((result) => {
                // Every key the result carries, INCLUDING the ones it set to
                // undefined — a scan names the whole selection, so an identity
                // it did not match is cleared rather than left behind for the
                // new item to be posted with. resolveStockScan owns that rule.
                for (const [field, value] of Object.entries(result.fill)) {
                    setValue(field as 'item_id' | 'batch_id' | 'serial_number_id', value as number | undefined);
                }

                (result.ok ? message.success : message.warning)(result.message);
            })
            // The lookup is a request now, so it can fail the way requests do.
            // Silence would read as "no such barcode", which is a different
            // and much worse answer than "the lookup did not happen".
            .catch(() => message.error(`Could not look up "${code}" — try the scan again.`));
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

    /*
     * THE IDENTITY PICKERS READ ONE ITEM'S SET, COMPLETE.
     *
     * They used to read the default first page of the WHOLE batch and serial
     * lists — twenty rows — and then filter that by item in the browser, so a
     * batch older than the newest twenty could not be selected at all, and
     * nothing on screen said a row was missing. Scoped to the item the open
     * modal has chosen and bounded by the server's own ceiling: complete for
     * that item, never "every batch in the factory".
     */
    const activeItemId =
        activeModal === 'receipt' ? receiptItemId
            : activeModal === 'issue' ? issueItemId
                : activeModal === 'transfer' ? transferItemId
                    : undefined;
    const activeTracking =
        activeItemId === undefined ? 'none' : (itemsById.get(activeItemId)?.tracking_type ?? 'none');

    const { data: batches } = useQuery({
        queryKey: ['inventory', 'batches', 'for-item', activeItemId],
        queryFn: () => listAllBatches(activeItemId!),
        enabled: activeItemId !== undefined && activeTracking === 'batch',
    });
    const { data: serialNumbers } = useQuery({
        queryKey: ['inventory', 'serial-numbers', 'for-item', activeItemId],
        queryFn: () => listAllSerialNumbers(activeItemId!),
        enabled: activeItemId !== undefined && activeTracking === 'serial',
    });

    /**
     * CHOOSING A DIFFERENT ITEM DROPS THE IDENTITY CHOSEN FOR THE OLD ONE.
     * Left in place it would post another item's batch, which the server
     * refuses as cross-item corruption — a 422 for a value the form was still
     * showing.
     *
     * On the Select's own onChange, and NOT in an effect watching the selected
     * item: a scan also changes the item, and it sets the matching batch or
     * serial number in the same breath. An effect cannot tell those two apart
     * — it would fire on the scan's item and wipe the identity the very same
     * scan had just filled in, leaving the barcode half-read. Clearing where
     * the user actually picks keeps "the person changed their mind" separate
     * from "the scanner named a whole row".
     */
    const onItemChange =
        (form: IdentityHolder, field: { onChange: (value: unknown) => void }) => (value: unknown) => {
            field.onChange(value);
            form.setValue('batch_id', undefined);
            form.setValue('serial_number_id', undefined);
        };

    /**
     * Say what the server would say, before the request goes — otherwise a
     * batch-tracked item submitted with the picker left empty comes back as an
     * unexplained 422 on a field the form called optional.
     */
    const submitGuarded = <T extends { item_id: number; batch_id?: number; serial_number_id?: number }>(
        form: { setError: (field: 'batch_id' | 'serial_number_id', error: { message: string }) => void },
        send: (values: T) => void,
    ) => (values: T) => {
        const tracking = itemsById.get(values.item_id)?.tracking_type ?? 'none';
        const refusal = identityRefusal(tracking, values);

        if (refusal) {
            form.setError(refusal.field, { message: refusal.message });

            return;
        }

        send(values);
    };

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Stock</Typography.Title>
                <Space>
                    {/* The two identity registers. They left the sidebar on
                        26-Aug-2026 and both pages are unchanged — this is the
                        only link to them now, put where someone who needs a
                        batch or a serial number already is (the receipt and
                        issue modals send people to those pages by name when a
                        tracked item has none yet). */}
                    <Link to="/inventory/batches">Batches</Link>
                    <Link to="/inventory/serial-numbers">Serial numbers</Link>
                    <Button onClick={() => setActiveModal('receipt')}>Receive Stock</Button>
                    <Button onClick={() => setActiveModal('issue')}>Issue Stock</Button>
                    <Button onClick={() => setActiveModal('transfer')}>Transfer Stock</Button>
                </Space>
            </Space>

            <Table<StockBalance>
                sticky
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={isLoading}
                dataSource={balances?.data}
                pagination={{
                    current: page,
                    pageSize: perPage,
                    // The server's count, not the page's length — otherwise
                    // the pager would claim the list ends at the first screen.
                    total: balances?.meta?.total ?? balances?.data?.length ?? 0,
                    showSizeChanger: true,
                    pageSizeOptions: [20, 50, 100, 200],
                    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} balances`,
                    onChange: (nextPage, nextSize) => {
                        setPage(nextPage);
                        setPerPage(nextSize);
                    },
                }}
                columns={[
                    {
                        /*
                         * FIXED, for the same reason as the item catalogue: a
                         * stock list runs to hundreds of rows, and scrolling
                         * right to reach the actions took the product name off
                         * the left edge, leaving a quantity belonging to
                         * nothing.
                         *
                         * NOT SORTABLE, deliberately, and this is the harder
                         * call. This list is paginated by the SERVER
                         * (`listStockBalances({ page, per_page })`), so a
                         * column sorter would order the fifty rows that had
                         * arrived and present it as the order of the stock —
                         * "the largest balance" would mean the largest on this
                         * page. Sorting belongs here once the endpoint accepts
                         * it; a control that quietly answers a smaller question
                         * than it appears to does not.
                         */
                        title: 'Item',
                        fixed: 'left' as const,
                        width: 260,
                        render: (_, row) => itemLabel(row.item),
                    },
                    {
                        title: 'Warehouse',
                        render: (_, row) => `${row.warehouse.code} — ${row.warehouse.name}`,
                    },
                    {
                        title: 'Quantity',
                        dataIndex: 'quantity',
                        align: 'right' as const,
                    },
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
                onOk={receiptForm.handleSubmit(submitGuarded(receiptForm, receiptMutation.mutate))}
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
                            render={({ field }) => (
                                <Select
                                    {...field}
                                    onChange={onItemChange(receiptForm, field)}
                                    options={itemOptions}
                                    showSearch
                                    optionFilterProp="label"
                                />
                            )}
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
                                    <Select {...field} options={batchOptionsFor(receiptItemId)} showSearch optionFilterProp="label" />
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
                onOk={issueForm.handleSubmit(submitGuarded(issueForm, issueMutation.mutate))}
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
                            render={({ field }) => (
                                <Select
                                    {...field}
                                    onChange={onItemChange(issueForm, field)}
                                    options={itemOptions}
                                    showSearch
                                    optionFilterProp="label"
                                />
                            )}
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
                                    <Select {...field} options={batchOptionsFor(issueItemId)} showSearch optionFilterProp="label" />
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
                                    <Select {...field} options={serialOptionsFor(issueItemId)} showSearch optionFilterProp="label" />
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
                onOk={transferForm.handleSubmit(submitGuarded(transferForm, transferMutation.mutate))}
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
                            render={({ field }) => (
                                <Select
                                    {...field}
                                    onChange={onItemChange(transferForm, field)}
                                    options={itemOptions}
                                    showSearch
                                    optionFilterProp="label"
                                />
                            )}
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
                                    <Select {...field} options={batchOptionsFor(transferItemId)} showSearch optionFilterProp="label" />
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
                                    <Select {...field} options={serialOptionsFor(transferItemId)} showSearch optionFilterProp="label" />
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
