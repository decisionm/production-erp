import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Descriptions, Drawer, Skeleton, Space, Table, Tag, Typography } from 'antd';
import { type ReactNode, useState } from 'react';
import { Link } from 'react-router-dom';
import { getPurchaseOrder, getPurchaseOrderTrace } from '@/features/procurement/api';
import { apiMessage, apiStatus } from '@/features/procurement/components/apiMessage';
import PurchaseOrderTallyCell from '@/features/procurement/components/PurchaseOrderTallyCell';
import {
    type FlatLot,
    type FlatMovement,
    WITHHELD_CELL,
    consumptionRow,
    flattenTrace,
    loadWords,
    lotLoadSummary,
    poNumber,
    rateCell,
    statusTag,
} from '@/features/procurement/purchaseOrders';
import type { PurchaseOrder, TraceConsumption, TraceOrderLine, TraceReceipt, TraceReceiptLine } from '@/features/procurement/types';
import { TallyLinkCell } from '@/features/sales/SalesDocumentDrawer';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';

const numeric = { fontVariantNumeric: 'tabular-nums' } as const;
const caption = { fontSize: 12, display: 'block' } as const;

const PURPOSE_COLOR: Record<string, string> = {
    receipt: 'green',
    consumption: 'volcano',
    issue: 'volcano',
    transfer: 'blue',
    adjustment: 'gold',
    return: 'purple',
};

function purposeTag(purpose: string): ReactNode {
    return <Tag color={PURPOSE_COLOR[purpose.toLowerCase()] ?? 'default'}>{purpose}</Tag>;
}

function SectionTitle({ children, count }: { children: ReactNode; count?: number }) {
    return (
        <Typography.Title level={5} style={{ marginTop: 24, marginBottom: 8 }}>
            {children}{' '}
            {count !== undefined && (
                <Typography.Text type="secondary" style={{ fontSize: 13, fontWeight: 'normal' }}>
                    ({count})
                </Typography.Text>
            )}
        </Typography.Title>
    );
}

/** The two silences: an ABSENT collection could not be read; an EMPTY one was measured and found empty. */
function Silence({ absent, none, rows }: { absent: string; none: string; rows: readonly unknown[] | undefined }) {
    if (rows === undefined) return <Typography.Text type="secondary">{absent}</Typography.Text>;
    if (rows.length === 0) return <Typography.Text type="secondary">{none}</Typography.Text>;

    return null;
}

/** "withheld" in a rate cell is FC-06 speaking, and the column head says so once. */
const RATE_HEAD = (
    <span>
        Rate{' '}
        <Typography.Text type="secondary" style={{ fontSize: 11, fontWeight: 'normal' }}>
            (Owner/Accounts only)
        </Typography.Text>
    </span>
);

function withheldStyle(value: string) {
    return value === WITHHELD_CELL ? { color: '#8c8c8c', fontStyle: 'italic' as const } : numeric;
}

// ----------------------------------------------------------- ordered lines --

/** The order's own lines as the trace prints them: ordered, received, remaining, the rate cell (FC-06), the delivery windows. */
function OrderLinesTable({ lines }: { lines: TraceOrderLine[] }) {
    return (
        <Table<TraceOrderLine>
            size="small"
            pagination={false}
            rowKey="id"
            dataSource={lines}
            scroll={{ x: 'max-content' }}
            expandable={{
                rowExpandable: (line) => (line.schedules?.length ?? 0) > 0,
                expandedRowRender: (line) => (
                    <Space direction="vertical" size={0}>
                        {(line.schedules ?? []).map((schedule) => (
                            <Typography.Text key={schedule.id} type="secondary" style={{ ...caption, ...numeric }}>
                                due {schedule.due_date ?? '—'} · {schedule.quantity} ordered
                                {schedule.quantity_received !== undefined ? ` · ${schedule.quantity_received} received` : ''}
                                {schedule.remaining !== undefined ? ` · ${schedule.remaining} remaining` : ''}
                                {schedule.tally_reference ? ` · Tally ref ${schedule.tally_reference}` : ''}
                            </Typography.Text>
                        ))}
                    </Space>
                ),
            }}
            columns={[
                { title: 'Item', render: (_, line) => itemLabel(line.item) },
                { title: 'Ordered', align: 'right', render: (_, line) => <span style={numeric}>{line.quantity}</span> },
                { title: 'Received', align: 'right', render: (_, line) => <span style={numeric}>{line.quantity_received ?? '—'}</span> },
                { title: 'Remaining', align: 'right', render: (_, line) => <span style={numeric}>{line.remaining ?? '—'}</span> },
                {
                    title: RATE_HEAD,
                    align: 'right',
                    render: (_, line) => {
                        const cell = rateCell(line, 'unit_price');

                        return <span style={withheldStyle(cell)}>{cell}</span>;
                    },
                },
            ]}
        />
    );
}

// ---------------------------------------------------------------- receipts --

function ReceiptBlock({ receipt }: { receipt: TraceReceipt }) {
    return (
        <div style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12, marginBottom: 12 }}>
            <Space size={8} wrap style={{ justifyContent: 'space-between', width: '100%' }}>
                <Space size={8} wrap>
                    <Link to={`/procurement/goods-receipts?grn=${receipt.id}`}>
                        <strong>{receipt.document_number ?? `GRN #${receipt.id}`}</strong>
                    </Link>
                    {receipt.receipt_note_reference && <Typography.Text type="secondary">{receipt.receipt_note_reference}</Typography.Text>}
                    <Typography.Text type="secondary">{formatDateTime(receipt.received_date)}</Typography.Text>
                    {receipt.warehouse && <Typography.Text type="secondary">into {receipt.warehouse.name}</Typography.Text>}
                    {receipt.reference && <Typography.Text type="secondary">ref {receipt.reference}</Typography.Text>}
                </Space>
                {receipt.tally !== undefined && <TallyLinkCell link={receipt.tally} compact />}
            </Space>
            {receipt.receipt_key && (
                <Typography.Text type="secondary" style={caption}>
                    receipt_key{' '}
                    <Typography.Text code style={{ fontSize: 11 }}>
                        {receipt.receipt_key}
                    </Typography.Text>{' '}
                    — the idempotency key: a replay of this receipt returns this row and moves no stock twice.
                </Typography.Text>
            )}
            <Table<TraceReceiptLine>
                size="small"
                pagination={false}
                rowKey="id"
                dataSource={receipt.lines ?? []}
                scroll={{ x: 'max-content' }}
                style={{ marginTop: 8 }}
                columns={[
                    { title: 'Item', render: (_, line) => itemLabel(line.item) },
                    { title: 'Quantity', align: 'right', render: (_, line) => <span style={numeric}>{line.quantity}</span> },
                    {
                        title: RATE_HEAD,
                        align: 'right',
                        render: (_, line) => {
                            const cell = rateCell(line, 'unit_cost');

                            return <span style={withheldStyle(cell)}>{cell}</span>;
                        },
                    },
                    {
                        title: 'PO line',
                        render: (_, line) => (line.purchase_order_line_id ? `#${line.purchase_order_line_id}` : '—'),
                    },
                ]}
            />
        </div>
    );
}

// ------------------------------------------------------------------- lots --

/**
 * The lots, one row each; a row expands into its bags and where each bag
 * was poured (the day-bin ledger's loads). "Loaded" is what has left the
 * store into a machine or the common input — FC-01: where it went, not
 * whose it is.
 */
function LotsTable({ lots }: { lots: FlatLot[] }) {
    return (
        <Table<FlatLot>
            size="small"
            pagination={false}
            rowKey="id"
            dataSource={lots}
            scroll={{ x: 'max-content' }}
            expandable={{
                rowExpandable: (lot) => (lot.bags?.length ?? 0) > 0,
                expandedRowRender: (lot) => (
                    <Space direction="vertical" size={4}>
                        {(lot.bags ?? []).map((bag) => (
                            <div key={bag.id}>
                                <Typography.Text style={{ ...caption, ...numeric }}>
                                    Bag {bag.barcode ?? `#${bag.id}`}
                                    {bag.status ? ` · ${bag.status}` : ''}
                                    {bag.original_kg !== undefined && bag.original_kg !== null ? ` · ${bag.original_kg} kg` : ''}
                                    {bag.remaining_kg !== undefined && bag.remaining_kg !== null ? ` (${bag.remaining_kg} left)` : ''}
                                </Typography.Text>
                                {(bag.loads ?? []).length === 0 ? (
                                    <Typography.Text type="secondary" style={{ ...caption, marginLeft: 16 }}>
                                        not loaded anywhere yet
                                    </Typography.Text>
                                ) : (
                                    (bag.loads ?? []).map((load) => (
                                        <Typography.Text key={load.id} type="secondary" style={{ ...caption, ...numeric, marginLeft: 16 }}>
                                            {loadWords(load)}
                                            {load.recorded_at ? ` · ${formatDateTime(load.recorded_at)}` : ''}
                                        </Typography.Text>
                                    ))
                                )}
                            </div>
                        ))}
                    </Space>
                ),
            }}
            columns={[
                { title: 'Lot', render: (_, lot) => <span>{lot.lot_no ?? `#${lot.id}`}</span> },
                { title: 'Supplier lot', render: (_, lot) => lot.supplier_lot_no ?? '—' },
                { title: 'Item', render: (_, lot) => (lot.item ? itemLabel(lot.item) : '—') },
                {
                    title: 'Bags · kg',
                    align: 'right',
                    render: (_, lot) => (
                        <span style={numeric}>
                            {lot.bag_count ?? '—'} · {lot.total_received_kg ?? lot.quantity ?? '—'}
                        </span>
                    ),
                },
                {
                    title: 'Loaded',
                    align: 'right',
                    render: (_, lot) => {
                        if (lot.bags === undefined) return <Typography.Text type="secondary">—</Typography.Text>;
                        const summary = lotLoadSummary(lot);

                        return (
                            <span style={numeric}>
                                {summary.loaded_kg} kg · {summary.loads} pour{summary.loads === 1 ? '' : 's'}
                            </span>
                        );
                    },
                },
                { title: 'Received', render: (_, lot) => formatDate(lot.received_date) },
                {
                    title: 'GRN',
                    render: (_, lot) => (lot.receipt_id ? <Link to={`/procurement/goods-receipts?grn=${lot.receipt_id}`}>#{lot.receipt_id}</Link> : '—'),
                },
                {
                    title: RATE_HEAD,
                    align: 'right',
                    render: (_, lot) => {
                        const cell = rateCell(lot, 'receipt_rate_per_kg');

                        return <span style={withheldStyle(cell)}>{cell}</span>;
                    },
                },
            ]}
        />
    );
}

// -------------------------------------------------------------- movements --

function MovementsTable({ movements }: { movements: FlatMovement[] }) {
    return (
        <Table<FlatMovement>
            size="small"
            pagination={false}
            rowKey="id"
            dataSource={movements}
            scroll={{ x: 'max-content' }}
            columns={[
                {
                    title: 'Purpose',
                    render: (_, m) => (
                        <Space size={4}>
                            {purposeTag(m.purpose)}
                            {(m.type ?? m.direction) && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{m.type ?? m.direction}</Typography.Text>}
                        </Space>
                    ),
                },
                { title: 'Item', render: (_, m) => (m.item ? itemLabel(m.item) : '—') },
                {
                    title: 'Warehouse',
                    render: (_, m) => m.warehouse?.name ?? (m.warehouse_id !== undefined && m.warehouse_id !== null ? `#${m.warehouse_id}` : '—'),
                },
                { title: 'Quantity', align: 'right', render: (_, m) => <span style={numeric}>{m.quantity}</span> },
                { title: 'When', render: (_, m) => formatDateTime(m.movement_date ?? m.occurred_at ?? m.moved_at) },
                {
                    title: 'Source',
                    render: (_, m) =>
                        m.receipt_id ? (
                            <Link to={`/procurement/goods-receipts?grn=${m.receipt_id}`}>GRN #{m.receipt_id}</Link>
                        ) : m.shift_production_entry_id ? (
                            `entry #${m.shift_production_entry_id}`
                        ) : (
                            m.reference ?? '—'
                        ),
                },
                {
                    title: RATE_HEAD,
                    align: 'right',
                    render: (_, m) => {
                        const cell = rateCell(m, 'unit_cost');

                        return <span style={withheldStyle(cell)}>{cell}</span>;
                    },
                },
            ]}
        />
    );
}

// ------------------------------------------------------------ consumption --

/**
 * One row per (batch segment, item) the order's material was loaded under:
 * the entry and its batch, the machine, how many kg of THIS order's bags
 * were loaded there, the day-bin consumption figure for that segment and
 * item (null until a closing count exists — said, not zeroed), and the
 * Consumption issues the batch's completion wrote. Every cell comes from
 * consumptionRow(), so a narrower backend's flat row still reads.
 */
function ConsumptionTable({ rows }: { rows: TraceConsumption[] }) {
    const normalised = rows.map((row, index) => ({ key: `${consumptionRow(row).entry_id ?? 'x'}-${index}`, ...consumptionRow(row) }));

    return (
        <Table<(typeof normalised)[number]>
            size="small"
            pagination={false}
            rowKey="key"
            dataSource={normalised}
            scroll={{ x: 'max-content' }}
            expandable={{
                rowExpandable: (row) => row.day_bin !== null || row.issues.length > 0,
                expandedRowRender: (row) => (
                    <Space direction="vertical" size={2}>
                        {row.day_bin && (
                            <Typography.Text type="secondary" style={{ ...caption, ...numeric }}>
                                Day-bin: opening {row.day_bin.opening_kg ?? '—'} + loaded {row.day_bin.loaded_kg ?? '—'} − closing{' '}
                                {row.day_bin.closing_kg ?? 'not counted'} − returned {row.day_bin.returned_kg ?? '—'} = consumed {row.consumed_words}
                            </Typography.Text>
                        )}
                        {row.issues.map((issue) => (
                            <Typography.Text key={issue.id} type="secondary" style={{ ...caption, ...numeric }}>
                                Stock issue #{issue.id} · {issue.purpose} · {issue.quantity}
                                {issue.movement_date ? ` · ${formatDateTime(issue.movement_date)}` : ''}
                                {' · rate '}
                                <span style={withheldStyle(rateCell(issue, 'unit_cost'))}>{rateCell(issue, 'unit_cost')}</span>
                            </Typography.Text>
                        ))}
                    </Space>
                ),
            }}
            columns={[
                {
                    title: 'Production entry',
                    render: (_, row) => (
                        <Space direction="vertical" size={0}>
                            <span>{row.entry_id !== null ? `entry #${row.entry_id}` : '—'}</span>
                            {row.batch && (
                                <Typography.Text type="secondary" style={caption}>
                                    batch {row.batch}
                                    {row.batch_status ? ` · ${row.batch_status}` : ''}
                                </Typography.Text>
                            )}
                        </Space>
                    ),
                },
                { title: 'Machine', render: (_, row) => row.machine ?? <Typography.Text type="secondary">common input</Typography.Text> },
                { title: 'Item', render: (_, row) => row.item },
                {
                    title: 'Loaded from this order',
                    align: 'right',
                    render: (_, row) => <span style={numeric}>{row.loaded_kg !== null ? `${row.loaded_kg} kg` : '—'}</span>,
                },
                {
                    title: 'Consumed (day-bin)',
                    align: 'right',
                    render: (_, row) =>
                        row.consumed_kg === null ? (
                            <Typography.Text type="secondary" italic>
                                {row.consumed_words}
                            </Typography.Text>
                        ) : (
                            <span style={numeric}>{row.consumed_kg}</span>
                        ),
                },
                {
                    title: 'Issued (ledger)',
                    align: 'right',
                    render: (_, row) => <span style={numeric}>{row.issues.length > 0 ? row.issued_qty : '—'}</span>,
                },
                { title: 'Date', render: (_, row) => formatDate(row.production_date) },
            ]}
        />
    );
}

// ------------------------------------------------------------- the drawer --

interface PurchaseOrderTraceDrawerProps {
    /** The order to trace; null closes the drawer. */
    orderId: number | null;
    /** The list's row, if the page has it — the header while the show endpoint answers. */
    listRow?: PurchaseOrder;
    onClose: () => void;
    onOpenDetail: (id: number) => void;
}

/**
 * THE CHAIN BELOW ONE PURCHASE ORDER (P6-02): the order's lines → the goods
 * receipts against it (with their receipt_key, lines and quantities and
 * their Receipt Note link) → the material lots those receipts created, each
 * expanding into its bags and where every bag was poured (the day-bin
 * ledger's loads: machine or the common input, under which batch) → the
 * stock movements (by purpose) → the batch segments that consumed the
 * material, with the day-bin figure and the ledger's Consumption issues.
 * Read from GET /procurement/purchase-orders/{id}/trace
 * (PurchaseOrderTraceService's shape; flattenTrace/consumptionRow read it
 * and a narrower one); the header from the show endpoint (or the list row
 * while it answers).
 *
 * FC-06 HONESTY: a purchase rate is Owner/Accounts data. Where the server
 * withheld it (omitted the key, or nulled it and said so) the cell reads
 * "withheld" — never blank, never a dash that could pass for "no rate".
 * Where the server served it, it is printed as sent.
 *
 * Every collection is rendered with its two silences kept apart: absent
 * ("could not be read for this order") is not the same fact as empty
 * ("none yet"), and the drawer says which.
 */
export default function PurchaseOrderTraceDrawer({ orderId, listRow, onClose, onOpenDetail }: PurchaseOrderTraceDrawerProps) {
    // The last order shown is kept for the closing frames (see
    // PurchaseOrderDetailDrawer); the queries are disabled once closed.
    const [lastId, setLastId] = useState<number | null>(null);
    if (orderId !== null && orderId !== lastId) {
        setLastId(orderId);
    }
    const shownId = orderId ?? lastId;
    const enabled = orderId !== null;

    const orderQuery = useQuery({
        queryKey: ['procurement', 'purchase-orders', 'show', shownId],
        queryFn: () => getPurchaseOrder(shownId as number),
        enabled,
        placeholderData: listRow,
    });
    const traceQuery = useQuery({
        queryKey: ['procurement', 'purchase-orders', 'trace', shownId],
        queryFn: () => getPurchaseOrderTrace(shownId as number),
        enabled,
    });

    const order = orderQuery.data ?? listRow ?? null;
    // The trace may carry a stub of the order for a reader who has no list
    // row and whose show call has not answered — enough for a header line.
    const stub = traceQuery.data?.purchase_order ?? null;
    const flat = flattenTrace(traceQuery.data);
    const status = apiStatus(traceQuery.error);

    return (
        <Drawer
            title={shownId !== null ? `Trace — ${poNumber(shownId)}` : 'Trace'}
            open={orderId !== null}
            onClose={onClose}
            width="min(100vw, 900px)"
            destroyOnHidden
            footer={
                <Space wrap>
                    <Button onClick={onClose}>Close</Button>
                    {shownId !== null && <Button onClick={() => onOpenDetail(shownId)}>Order detail</Button>}
                    {shownId !== null && (
                        <Link to={`/procurement/goods-receipts?po=${shownId}`}>
                            <Button>Goods receipts page</Button>
                        </Link>
                    )}
                </Space>
            }
        >
            {traceQuery.isError && (
                <Alert
                    type="error"
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={
                        status === 404
                            ? 'No such purchase order — or the trace endpoint is not on this backend yet'
                            : status === 403
                                ? 'This login cannot view purchase orders'
                                : 'Could not read the trace for this order'
                    }
                    description={
                        <Space direction="vertical" size={8}>
                            <span>{apiMessage(traceQuery.error, 'The ERP did not answer for this trace. Check the connection and try again.')}</span>
                            {status !== 404 && status !== 403 && (
                                <Button size="small" onClick={() => traceQuery.refetch()}>
                                    Try again
                                </Button>
                            )}
                        </Space>
                    }
                />
            )}

            {(order || stub) && (
                <Descriptions column={1} size="small" bordered>
                    <Descriptions.Item label="Order">
                        <Space size={8} wrap>
                            <Button type="link" size="small" style={{ padding: 0 }} onClick={() => shownId !== null && onOpenDetail(shownId)}>
                                <strong>{poNumber(shownId ?? 0)}</strong>
                            </Button>
                            {(order?.status ?? stub?.status) && (
                                <Tag color={statusTag(order?.status ?? stub?.status ?? '').color}>
                                    {statusTag(order?.status ?? stub?.status ?? '').label}
                                </Tag>
                            )}
                            {(order?.order_date ?? stub?.order_date) && (
                                <Typography.Text type="secondary">{order?.order_date ?? stub?.order_date}</Typography.Text>
                            )}
                        </Space>
                    </Descriptions.Item>
                    <Descriptions.Item label="Vendor">
                        {order?.vendor ? `${order.vendor.code} — ${order.vendor.name}` : stub?.vendor?.name ?? '—'}
                    </Descriptions.Item>
                    {order && (
                        <Descriptions.Item label="Tally">
                            <PurchaseOrderTallyCell order={order} />
                        </Descriptions.Item>
                    )}
                    {order && !traceQuery.data?.lines && (
                        <Descriptions.Item label="Ordered">
                            <Space direction="vertical" size={0}>
                                {order.lines.map((line) => (
                                    <Typography.Text key={line.id} style={{ ...caption, ...numeric }}>
                                        {itemLabel(line.item)} × {line.quantity} · received {line.quantity_received}
                                    </Typography.Text>
                                ))}
                            </Space>
                        </Descriptions.Item>
                    )}
                </Descriptions>
            )}

            {traceQuery.isLoading && <Skeleton active paragraph={{ rows: 6 }} style={{ marginTop: 16 }} />}

            {traceQuery.data && (
                <>
                    {traceQuery.data.lines && (
                        <>
                            <SectionTitle count={traceQuery.data.lines.length}>Ordered</SectionTitle>
                            <OrderLinesTable lines={traceQuery.data.lines} />
                        </>
                    )}

                    <SectionTitle count={flat.receipts?.length}>Goods receipts</SectionTitle>
                    <Silence
                        rows={flat.receipts}
                        absent="The receipts could not be read for this order."
                        none="Nothing received against this order yet."
                    />
                    {flat.receipts?.map((receipt) => <ReceiptBlock key={receipt.id} receipt={receipt} />)}

                    <SectionTitle count={flat.lots?.length}>Material lots</SectionTitle>
                    <Silence
                        rows={flat.lots}
                        absent="The material lots could not be read for this order."
                        none="No material lots — either nothing was received yet, or the received lines are not lot-tracked."
                    />
                    {flat.lots && flat.lots.length > 0 && <LotsTable lots={flat.lots} />}

                    <SectionTitle count={flat.movements?.length}>Stock movements</SectionTitle>
                    <Silence
                        rows={flat.movements}
                        absent="The stock movements could not be read for this order."
                        none="No stock has moved on this order yet."
                    />
                    {flat.movements && flat.movements.length > 0 && <MovementsTable movements={flat.movements} />}

                    <SectionTitle count={flat.consumption?.length}>Consumed in production</SectionTitle>
                    <Silence
                        rows={flat.consumption}
                        absent="Consumption could not be read for this order — the day-bin ledger did not answer, or this backend does not trace it yet."
                        none="Nothing received on this order has been consumed by a production entry yet."
                    />
                    {flat.consumption && flat.consumption.length > 0 && <ConsumptionTable rows={flat.consumption} />}
                    {flat.consumption && flat.consumption.length > 0 && (
                        <Typography.Text type="secondary" style={{ ...caption, marginTop: 8 }}>
                            Consumption is attributed through the factory day-bin ledger (a resin bag belongs to no machine and no
                            batch, FC-01) — a quantity here is what the ledger attributes to this order's material, not a bag-to-batch
                            claim.
                        </Typography.Text>
                    )}
                </>
            )}
        </Drawer>
    );
}
