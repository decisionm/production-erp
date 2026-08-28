import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, DatePicker, Descriptions, Drawer, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, Upload, message } from 'antd';
import dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    attachSupplierBillFile,
    cancelSupplierBill,
    createSupplierBill,
    listAllVendors,
    listGoodsReceipts,
    listPurchaseOrders,
    listSupplierBillLedgerOptions,
    listSupplierBills,
    recordSupplierBill,
    updateSupplierBill,
    type SupplierBillLinePayload,
    type SupplierBillPayload,
} from '@/features/procurement/api';
import { apiMessage } from '@/features/procurement/components/apiMessage';
import { poNumber } from '@/features/procurement/purchaseOrders';
import { grnNumber } from '@/features/procurement/documentWords';
import type { SupplierBill, SupplierBillStatus } from '@/features/procurement/types';
import { billArithmetic, billStatusTag, billTallyLine } from '@/features/procurement/supplierBills';
import { instant } from '@/features/tally-sync/drawer';
import { itemLabel, itemPickerLabel } from '@/lib/itemLabel';
import { listAllItems } from '@/features/inventory/api';
import { ListEmpty } from '@/lib/ListEmpty';

const numeric = { fontVariantNumeric: 'tabular-nums' } as const;

interface DraftLine {
    key: number;
    goods_receipt_note_line_id: number | null;
    item_id: number | null;
    quantity: number | null;
    rate: number | null;
    amount: number | null;
}

const emptyLine = (key: number): DraftLine => ({ key, goods_receipt_note_line_id: null, item_id: null, quantity: null, rate: null, amount: null });

/**
 * SUPPLIER BILLS — the vendor's invoice recorded (28-Aug audit finding 10).
 *
 * The screen records the PAPER: its number, its date, its figures as
 * printed. Taxes and rounding are typed, never computed — no GST rate
 * exists to compute from (DEC-20260812-003 forbids seeding one; Q39 is
 * open on how the rate is even chosen) — and the one arithmetic enforced
 * is the bill's own, shown live before the server repeats it: subtotal =
 * Σ line amounts, total = subtotal + taxes + rounding.
 *
 * The purchase ledger is the accountant's SELECTION from the pulled Tally
 * ledgers, stored for the day posting is ruled on. No Tally posting
 * happens here at all — the Tally cell says so in one line rather than
 * pretending a queue exists (Q39/Q41/Q28).
 *
 * The whole surface is finance-gated server-side (FC-06): this page 403s
 * for anyone who is not Owner/Accounts, and the sidebar entry is hidden
 * from everyone else.
 */
export default function SupplierBillsPage() {
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [detailBill, setDetailBill] = useState<SupplierBill | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<SupplierBill | null>(null);
    const [cancelling, setCancelling] = useState<SupplierBill | null>(null);
    const [cancelReason, setCancelReason] = useState('');

    // ---- form state (controlled, not RHF: the arithmetic preview reads
    // every figure on every keystroke, which is what useState is for) ----
    const [vendorId, setVendorId] = useState<number | null>(null);
    const [purchaseOrderId, setPurchaseOrderId] = useState<number | null>(null);
    const [billNumber, setBillNumber] = useState('');
    const [billDate, setBillDate] = useState<string>('');
    const [ledgerName, setLedgerName] = useState<string | null>(null);
    const [ledgerSearch, setLedgerSearch] = useState('');
    const [lines, setLines] = useState<DraftLine[]>([emptyLine(1)]);
    const [subtotal, setSubtotal] = useState<number | null>(null);
    const [cgst, setCgst] = useState<number | null>(null);
    const [sgst, setSgst] = useState<number | null>(null);
    const [igst, setIgst] = useState<number | null>(null);
    const [rounding, setRounding] = useState<number | null>(null);
    const [total, setTotal] = useState<number | null>(null);
    const [notes, setNotes] = useState('');

    const status = (searchParams.get('status') ?? '') as SupplierBillStatus | '';
    const q = searchParams.get('q') ?? '';
    const writeParams = (patch: { status?: string; q?: string }) => {
        setPage(1);
        setSearchParams((current) => {
            const next = new URLSearchParams(current);
            for (const [key, value] of Object.entries(patch)) {
                if (value === undefined || value === '') next.delete(key);
                else next.set(key, value);
            }
            return next;
        }, { replace: true });
    };

    const filters = { status, q, page, per_page: perPage };
    const billsQuery = useQuery({
        queryKey: ['procurement', 'supplier-bills', filters],
        queryFn: () => listSupplierBills(filters),
        placeholderData: (previous) => previous,
    });
    const vendorsQuery = useQuery({ queryKey: ['procurement', 'vendors', 'all'], queryFn: listAllVendors });
    const itemsQuery = useQuery({ queryKey: ['inventory', 'items', 'all'], queryFn: listAllItems });
    // The chosen vendor's orders, for the optional PO reference.
    const ordersQuery = useQuery({
        queryKey: ['procurement', 'purchase-orders', 'for-bill', vendorId],
        queryFn: () => listPurchaseOrders({ vendor_id: vendorId as number, per_page: 200 }),
        enabled: formOpen && vendorId !== null,
    });
    // The chosen order's receipts, for the optional GRN-line match.
    const receiptsQuery = useQuery({
        queryKey: ['procurement', 'goods-receipts', 'for-bill', purchaseOrderId],
        queryFn: () => listGoodsReceipts({ purchase_order_id: purchaseOrderId as number, per_page: 200 }),
        enabled: formOpen && purchaseOrderId !== null,
    });
    const ledgersQuery = useQuery({
        queryKey: ['procurement', 'supplier-bill-ledgers', ledgerSearch],
        queryFn: () => listSupplierBillLedgerOptions(ledgerSearch),
        enabled: formOpen,
    });

    const vendorOptions = (vendorsQuery.data?.data ?? []).map((vendor) => ({ value: vendor.id, label: `${vendor.code} — ${vendor.name}` }));
    const itemOptions = (itemsQuery.data?.data ?? []).map((item) => ({ value: item.id, label: itemPickerLabel(item) }));
    const grnLineOptions = useMemo(
        () =>
            (receiptsQuery.data?.data ?? []).flatMap((grn) =>
                grn.lines.map((line) => ({
                    value: line.id,
                    label: `${grnNumber(grn)} · ${itemLabel(line.item)} · received ${line.quantity}`,
                    item_id: line.item?.id,
                })),
            ),
        [receiptsQuery.data],
    );

    // The live preview of the two equations the server will enforce.
    const arithmetic = billArithmetic({
        lines: lines.map((line) => ({ amount: line.amount })),
        subtotal, cgst, sgst, igst, rounding, total,
    });

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['procurement', 'supplier-bills'] });
    const refused = (fallback: string) => (error: unknown) =>
        Modal.error({ title: fallback, content: apiMessage(error, fallback) });

    const closeForm = () => {
        setFormOpen(false);
        setEditing(null);
    };

    const openCreate = () => {
        setEditing(null);
        setVendorId(null);
        setPurchaseOrderId(null);
        setBillNumber('');
        setBillDate('');
        setLedgerName(null);
        setLines([emptyLine(1)]);
        setSubtotal(null);
        setCgst(null);
        setSgst(null);
        setIgst(null);
        setRounding(null);
        setTotal(null);
        setNotes('');
        setFormOpen(true);
    };

    const openEdit = (bill: SupplierBill) => {
        setEditing(bill);
        setVendorId(bill.vendor.id);
        setPurchaseOrderId(bill.purchase_order_id);
        setBillNumber(bill.bill_number);
        setBillDate(bill.bill_date);
        setLedgerName(bill.purchase_ledger_name);
        setLines(bill.lines.map((line, index) => ({
            key: index + 1,
            goods_receipt_note_line_id: line.goods_receipt_note_line_id,
            item_id: line.item?.id ?? null,
            quantity: Number(line.quantity),
            rate: Number(line.rate),
            amount: Number(line.amount),
        })));
        setSubtotal(Number(bill.subtotal));
        setCgst(Number(bill.cgst));
        setSgst(Number(bill.sgst));
        setIgst(Number(bill.igst));
        setRounding(Number(bill.rounding));
        setTotal(Number(bill.total));
        setNotes(bill.notes ?? '');
        setFormOpen(true);
    };

    const payload = (): SupplierBillPayload | null => {
        if (vendorId === null || billNumber.trim() === '' || billDate === '') {
            message.error('The bill needs its vendor, its number and its date — they are on the paper.');
            return null;
        }
        const readyLines: SupplierBillLinePayload[] = [];
        for (const line of lines) {
            if (line.item_id === null || line.quantity === null || line.rate === null || line.amount === null) {
                message.error('Every line needs an item, a quantity, a rate and its printed amount.');
                return null;
            }
            readyLines.push({
                goods_receipt_note_line_id: line.goods_receipt_note_line_id,
                item_id: line.item_id,
                quantity: line.quantity,
                rate: line.rate,
                amount: line.amount,
            });
        }
        if (subtotal === null || total === null) {
            message.error('The subtotal and the total are on the paper — type both.');
            return null;
        }

        return {
            vendor_id: vendorId,
            purchase_order_id: purchaseOrderId,
            bill_number: billNumber.trim(),
            bill_date: billDate,
            purchase_ledger_name: ledgerName,
            subtotal,
            cgst: cgst ?? 0,
            sgst: sgst ?? 0,
            igst: igst ?? 0,
            rounding: rounding ?? 0,
            total,
            notes: notes.trim() === '' ? undefined : notes.trim(),
            lines: readyLines,
        };
    };

    const saveMutation = useMutation({
        mutationFn: (data: SupplierBillPayload) =>
            editing ? updateSupplierBill(editing.id, data) : createSupplierBill(data),
        onSuccess: (bill) => {
            refresh();
            closeForm();
            setDetailBill(bill);
            message.success(`${bill.document_number} saved as a draft. Record it when the entry matches the paper.`);
        },
        onError: refused('The bill was not saved'),
    });

    const recordMutation = useMutation({
        mutationFn: recordSupplierBill,
        onSuccess: (bill) => {
            refresh();
            setDetailBill(bill);
        },
        onError: refused('The bill could not be recorded'),
    });

    const cancelMutation = useMutation({
        mutationFn: () => cancelSupplierBill((cancelling as SupplierBill).id, cancelReason.trim()),
        onSuccess: (bill) => {
            refresh();
            setCancelling(null);
            setCancelReason('');
            setDetailBill(bill);
        },
        onError: refused('The bill could not be cancelled'),
    });

    const attachMutation = useMutation({
        mutationFn: ({ id, file }: { id: number; file: File }) => attachSupplierBillFile(id, file),
        onSuccess: (bill) => {
            refresh();
            setDetailBill(bill);
            message.success('Scan attached.');
        },
        onError: refused('The file was not attached'),
    });

    return (
        <>
            <Space style={{ marginBottom: 16, justifyContent: 'space-between', width: '100%' }}>
                <Typography.Title level={3} style={{ margin: 0 }}>Supplier Bills</Typography.Title>
                <Button type="primary" onClick={openCreate}>New Bill</Button>
            </Space>

            <Space wrap style={{ marginBottom: 12 }}>
                <Input.Search
                    allowClear
                    defaultValue={q}
                    style={{ width: 340 }}
                    placeholder='Search — "BILL-12", the vendor&apos;s invoice number, or the vendor'
                    onSearch={(value) => writeParams({ q: value.trim() })}
                />
                <Select
                    value={status}
                    style={{ width: 170 }}
                    onChange={(value) => writeParams({ status: value })}
                    options={[
                        { value: '', label: 'All statuses' },
                        { value: 'draft', label: 'Draft' },
                        { value: 'recorded', label: 'Recorded' },
                        { value: 'cancelled', label: 'Cancelled' },
                    ]}
                />
            </Space>

            <Table<SupplierBill>
                scroll={{ x: 'max-content' }}
                rowKey="id"
                loading={billsQuery.isLoading}
                dataSource={billsQuery.data?.data}
                locale={{
                    emptyText: (
                        <ListEmpty
                            state={billsQuery}
                            entity="supplier bills"
                            empty={status || q ? 'No supplier bills match these filters.' : 'No supplier bills recorded yet.'}
                        />
                    ),
                }}
                pagination={{
                    current: page,
                    pageSize: perPage,
                    total: billsQuery.data?.meta?.total ?? billsQuery.data?.data?.length ?? 0,
                    showSizeChanger: true,
                    pageSizeOptions: [20, 50, 100, 200],
                    showTotal: (count, range) => `${range[0]}-${range[1]} of ${count} bills`,
                    onChange: (nextPage, nextSize) => {
                        setPage(nextPage);
                        setPerPage(nextSize);
                    },
                }}
                columns={[
                    { title: 'Bill', render: (_, row) => row.document_number },
                    { title: "Vendor's invoice no.", dataIndex: 'bill_number' },
                    { title: 'Vendor', render: (_, row) => `${row.vendor.code} — ${row.vendor.name}` },
                    { title: 'Date', dataIndex: 'bill_date' },
                    {
                        title: 'Total',
                        align: 'right',
                        render: (_, row) => <span style={numeric}>{Number(row.total).toLocaleString('en-IN', { style: 'currency', currency: 'INR' })}</span>,
                    },
                    {
                        title: 'Status',
                        render: (_, row) => {
                            const tag = billStatusTag(row.status);
                            return <Tag color={tag.color}>{tag.label}</Tag>;
                        },
                    },
                    {
                        title: 'PO',
                        render: (_, row) => (row.purchase_order_id ? poNumber(row.purchase_order_id) : '—'),
                    },
                    {
                        title: 'Tally',
                        render: () => (
                            <Tag color="default" style={{ whiteSpace: 'normal', maxWidth: 280 }}>{billTallyLine()}</Tag>
                        ),
                    },
                    {
                        title: 'Actions',
                        render: (_, row) => (
                            <Space>
                                <Button size="small" onClick={() => setDetailBill(row)}>View</Button>
                                {row.status === 'draft' && (
                                    <Button size="small" onClick={() => openEdit(row)}>Edit</Button>
                                )}
                            </Space>
                        ),
                    },
                ]}
            />

            {/* ------------------------------------------------ create / edit ---- */}
            <Modal
                maskClosable={false}
                title={editing ? `Edit ${editing.document_number} (draft)` : 'New Supplier Bill'}
                open={formOpen}
                onCancel={closeForm}
                okText={editing ? 'Save draft' : 'Create draft'}
                confirmLoading={saveMutation.isPending}
                onOk={() => {
                    const data = payload();
                    if (data) saveMutation.mutate(data);
                }}
                destroyOnHidden
                width={900}
            >
                <Space direction="vertical" size={12} style={{ width: '100%' }}>
                    <Space wrap>
                        <Select
                            showSearch
                            optionFilterProp="label"
                            placeholder="Vendor"
                            style={{ width: 320 }}
                            value={vendorId}
                            onChange={(value) => {
                                setVendorId(value);
                                setPurchaseOrderId(null);
                            }}
                            options={vendorOptions}
                        />
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            placeholder="Against purchase order (optional)"
                            style={{ width: 280 }}
                            value={purchaseOrderId}
                            disabled={vendorId === null}
                            loading={ordersQuery.isLoading}
                            onChange={(value) => setPurchaseOrderId(value ?? null)}
                            options={(ordersQuery.data?.data ?? []).map((order) => ({
                                value: order.id,
                                label: `${poNumber(order)} · ${order.order_date ?? ''}`,
                            }))}
                        />
                    </Space>
                    <Space wrap>
                        <Input
                            style={{ width: 320 }}
                            placeholder="Vendor's invoice number — as printed"
                            value={billNumber}
                            onChange={(event) => setBillNumber(event.target.value)}
                        />
                        <DatePicker
                            placeholder="Bill date"
                            value={billDate ? dayjs(billDate) : null}
                            onChange={(_, dateString) => setBillDate((dateString as string) || '')}
                        />
                    </Space>
                    <Select
                        allowClear
                        showSearch
                        style={{ width: 620 }}
                        placeholder="Purchase ledger — your selection from the pulled Tally ledgers (optional until posting is ruled on)"
                        value={ledgerName}
                        loading={ledgersQuery.isLoading}
                        onSearch={setLedgerSearch}
                        filterOption={false}
                        onChange={(value) => setLedgerName(value ?? null)}
                        options={(ledgersQuery.data ?? []).map((ledger) => ({
                            value: ledger.name,
                            label: ledger.group ? `${ledger.name} (${ledger.group})` : ledger.name,
                        }))}
                    />

                    <Typography.Text strong>Lines — as printed on the bill</Typography.Text>
                    {lines.map((line, index) => (
                        <Space key={line.key} wrap align="baseline">
                            <Select
                                showSearch
                                optionFilterProp="label"
                                placeholder="Item"
                                style={{ width: 280 }}
                                value={line.item_id}
                                onChange={(value) =>
                                    setLines((current) => current.map((row, i) => (i === index ? { ...row, item_id: value } : row)))
                                }
                                options={itemOptions}
                            />
                            <Select
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                placeholder={purchaseOrderId ? 'Match to arrival (optional)' : 'Choose a PO to match arrivals'}
                                style={{ width: 300 }}
                                disabled={purchaseOrderId === null}
                                loading={receiptsQuery.isLoading}
                                value={line.goods_receipt_note_line_id}
                                onChange={(value) =>
                                    setLines((current) =>
                                        current.map((row, i) => {
                                            if (i !== index) return row;
                                            const matched = grnLineOptions.find((option) => option.value === value);
                                            return {
                                                ...row,
                                                goods_receipt_note_line_id: value ?? null,
                                                // Matching an arrival fills the item — one less mismatch to type.
                                                item_id: matched?.item_id ?? row.item_id,
                                            };
                                        }),
                                    )
                                }
                                options={grnLineOptions}
                            />
                            <InputNumber
                                min={0}
                                placeholder="Qty"
                                style={{ width: 110 }}
                                value={line.quantity}
                                onChange={(value) =>
                                    setLines((current) => current.map((row, i) => (i === index ? { ...row, quantity: value } : row)))
                                }
                            />
                            <InputNumber
                                min={0}
                                placeholder="Rate"
                                style={{ width: 110 }}
                                value={line.rate}
                                onChange={(value) =>
                                    setLines((current) => current.map((row, i) => (i === index ? { ...row, rate: value } : row)))
                                }
                            />
                            <InputNumber
                                min={0}
                                placeholder="Amount"
                                style={{ width: 130 }}
                                value={line.amount}
                                onChange={(value) =>
                                    setLines((current) => current.map((row, i) => (i === index ? { ...row, amount: value } : row)))
                                }
                            />
                            <Button danger disabled={lines.length === 1} onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                                Remove
                            </Button>
                        </Space>
                    ))}
                    <Button type="dashed" onClick={() => setLines((current) => [...current, emptyLine(Math.max(...current.map((l) => l.key)) + 1)])}>
                        Add line
                    </Button>

                    <Space wrap>
                        <InputNumber min={0} placeholder="Subtotal" style={{ width: 140 }} value={subtotal} onChange={setSubtotal} />
                        <InputNumber min={0} placeholder="CGST" style={{ width: 120 }} value={cgst} onChange={setCgst} />
                        <InputNumber min={0} placeholder="SGST" style={{ width: 120 }} value={sgst} onChange={setSgst} />
                        <InputNumber min={0} placeholder="IGST" style={{ width: 120 }} value={igst} onChange={setIgst} />
                        <InputNumber placeholder="Rounding ±" style={{ width: 120 }} value={rounding} onChange={setRounding} />
                        <InputNumber min={0} placeholder="Total" style={{ width: 150 }} value={total} onChange={setTotal} />
                    </Space>

                    {/* The two equations, live — the server refuses the same way. */}
                    {arithmetic.kind === 'lines_mismatch' && (
                        <Alert type="warning" showIcon message={`The lines sum to ${arithmetic.linesSum}, not the subtotal typed. One of the two is mistyped — the paper knows which.`} />
                    )}
                    {arithmetic.kind === 'total_mismatch' && (
                        <Alert type="warning" showIcon message={`Subtotal + taxes + rounding is ${arithmetic.expectedTotal}, not the total typed.`} />
                    )}
                    {arithmetic.kind === 'balanced' && (
                        <Alert type="success" showIcon message="The bill adds up." />
                    )}

                    <Input.TextArea
                        rows={2}
                        placeholder="Notes (optional)"
                        value={notes}
                        onChange={(event) => setNotes(event.target.value)}
                    />
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        {billTallyLine()} GST figures are typed from the paper — nothing is computed from a rate table.
                    </Typography.Text>
                </Space>
            </Modal>

            {/* ------------------------------------------------------- drawer ---- */}
            <Drawer
                title={detailBill ? `Supplier Bill ${detailBill.document_number}` : 'Supplier Bill'}
                open={detailBill !== null}
                onClose={() => setDetailBill(null)}
                width="min(100vw, 680px)"
                destroyOnHidden
                extra={
                    detailBill?.status === 'draft' ? (
                        <Space>
                            <Button
                                type="primary"
                                loading={recordMutation.isPending}
                                onClick={() => recordMutation.mutate(detailBill.id)}
                            >
                                Record
                            </Button>
                            <Button danger onClick={() => setCancelling(detailBill)}>Cancel bill</Button>
                        </Space>
                    ) : detailBill?.status === 'recorded' ? (
                        <Button danger onClick={() => setCancelling(detailBill)}>Cancel bill</Button>
                    ) : null
                }
            >
                {detailBill && (
                    <>
                        <Descriptions column={1} size="small" bordered>
                            <Descriptions.Item label="Status">
                                {(() => {
                                    const tag = billStatusTag(detailBill.status);
                                    return <Tag color={tag.color}>{tag.label}</Tag>;
                                })()}
                            </Descriptions.Item>
                            <Descriptions.Item label="Vendor">{`${detailBill.vendor.code} — ${detailBill.vendor.name}`}</Descriptions.Item>
                            <Descriptions.Item label="Vendor's invoice no.">{detailBill.bill_number}</Descriptions.Item>
                            <Descriptions.Item label="Bill date">{detailBill.bill_date}</Descriptions.Item>
                            <Descriptions.Item label="Against PO">
                                {detailBill.purchase_order_id ? poNumber(detailBill.purchase_order_id) : '—'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Purchase ledger (your selection)">
                                {detailBill.purchase_ledger_name ?? 'Not selected yet'}
                            </Descriptions.Item>
                            <Descriptions.Item label="Recorded">
                                {detailBill.recorded_by
                                    ? `by ${detailBill.recorded_by}${detailBill.recorded_at ? ` · ${instant(detailBill.recorded_at)}` : ''}`
                                    : 'Not recorded yet — still a draft'}
                            </Descriptions.Item>
                            {detailBill.cancelled_reason && (
                                <Descriptions.Item label="Cancelled because">{detailBill.cancelled_reason}</Descriptions.Item>
                            )}
                            <Descriptions.Item label="Tally">{billTallyLine()}</Descriptions.Item>
                            <Descriptions.Item label="Scan of the paper">
                                <Space>
                                    {detailBill.has_attachment ? (
                                        <a href={`/api/v1/procurement/supplier-bills/${detailBill.id}/attachment`} target="_blank" rel="noreferrer">
                                            {detailBill.attachment_name ?? 'Download'}
                                        </a>
                                    ) : (
                                        <Typography.Text type="secondary">None attached</Typography.Text>
                                    )}
                                    {detailBill.status === 'draft' && (
                                        <Upload
                                            showUploadList={false}
                                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                                            beforeUpload={(file) => {
                                                attachMutation.mutate({ id: detailBill.id, file });
                                                return false;
                                            }}
                                        >
                                            <Button size="small" loading={attachMutation.isPending}>
                                                {detailBill.has_attachment ? 'Replace scan' : 'Attach scan'}
                                            </Button>
                                        </Upload>
                                    )}
                                </Space>
                            </Descriptions.Item>
                            <Descriptions.Item label="Notes">{detailBill.notes ?? '—'}</Descriptions.Item>
                        </Descriptions>

                        <Typography.Title level={5} style={{ marginTop: 24 }}>Lines</Typography.Title>
                        <Table
                            rowKey="id"
                            size="small"
                            pagination={false}
                            dataSource={detailBill.lines}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                { title: 'Item', render: (_, line) => itemLabel(line.item) },
                                { title: 'Billed qty', align: 'right', render: (_, line) => <span style={numeric}>{line.quantity}</span> },
                                {
                                    title: 'Received qty',
                                    align: 'right',
                                    render: (_, line) =>
                                        line.received_quantity == null ? (
                                            <Typography.Text type="secondary">not matched</Typography.Text>
                                        ) : (
                                            <span style={numeric}>{line.received_quantity}</span>
                                        ),
                                },
                                {
                                    title: 'Variance',
                                    align: 'right',
                                    render: (_, line) => {
                                        if (line.received_quantity == null) return '—';
                                        const diff = Number(line.quantity) - Number(line.received_quantity);
                                        if (Math.abs(diff) < 0.0001) return <Tag color="green">matches</Tag>;
                                        return <Tag color="orange">{diff > 0 ? `billed ${diff.toFixed(4)} more` : `billed ${(-diff).toFixed(4)} less`}</Tag>;
                                    },
                                },
                                { title: 'Rate', align: 'right', render: (_, line) => <span style={numeric}>{line.rate}</span> },
                                { title: 'Amount', align: 'right', render: (_, line) => <span style={numeric}>{line.amount}</span> },
                            ]}
                        />

                        <Descriptions column={1} size="small" bordered style={{ marginTop: 16 }}>
                            <Descriptions.Item label="Subtotal">{detailBill.subtotal}</Descriptions.Item>
                            <Descriptions.Item label="CGST">{detailBill.cgst}</Descriptions.Item>
                            <Descriptions.Item label="SGST">{detailBill.sgst}</Descriptions.Item>
                            <Descriptions.Item label="IGST">{detailBill.igst}</Descriptions.Item>
                            <Descriptions.Item label="Rounding">{detailBill.rounding}</Descriptions.Item>
                            <Descriptions.Item label="Total">
                                <Typography.Text strong style={numeric}>{detailBill.total}</Typography.Text>
                            </Descriptions.Item>
                        </Descriptions>
                    </>
                )}
            </Drawer>

            {/* ------------------------------------------------------- cancel ---- */}
            <Modal
                open={cancelling !== null}
                title={`Cancel ${cancelling?.document_number ?? ''}?`}
                okText="Cancel the bill"
                okButtonProps={{ danger: true, loading: cancelMutation.isPending }}
                onCancel={() => setCancelling(null)}
                onOk={() => {
                    if (cancelReason.trim() === '') {
                        message.error('A cancelled bill keeps its reason — say why.');
                        return;
                    }
                    cancelMutation.mutate();
                }}
                destroyOnHidden
            >
                <Typography.Paragraph type="secondary">
                    The bill stays on the register as cancelled — nothing is deleted.
                </Typography.Paragraph>
                <Input.TextArea
                    rows={3}
                    value={cancelReason}
                    onChange={(event) => setCancelReason(event.target.value)}
                    placeholder="Why is this bill wrong?"
                />
            </Modal>
        </>
    );
}
