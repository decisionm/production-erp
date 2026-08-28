import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Collapse, Descriptions, Drawer, Skeleton, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import { getPurchaseOrder } from '@/features/procurement/api';
import { apiMessage, apiStatus } from '@/features/procurement/components/apiMessage';
import PurchaseOrderTallyCell from '@/features/procurement/components/PurchaseOrderTallyCell';
import {
    type PurchaseOrderAction,
    actorWords,
    canLabels,
    lifecycleNote,
    poNumber,
    rateCell,
    reconcileReceipts,
    revisionLines,
    statusTag,
} from '@/features/procurement/purchaseOrders';
import type { PurchaseOrder, PurchaseOrderLine, PurchaseOrderRevision, PurchaseOrderRevisionLine } from '@/features/procurement/types';
import { TallyLinkCell } from '@/features/sales/SalesDocumentDrawer';
import { instant } from '@/features/tally-sync/drawer';
import { formatDateTime } from '@/lib/datetime';
import { itemLabel } from '@/lib/itemLabel';

const numeric = { fontVariantNumeric: 'tabular-nums' } as const;
const caption = { fontSize: 12, display: 'block' } as const;

const RECEIPT_STATE_COLOR: Record<string, string> = {
    open: 'default',
    partial: 'gold',
    complete: 'green',
    over: 'red',
    unknown: 'default',
};

/** quantity × unit_price, or '—' when the rate is not on the line at all. */
function lineAmount(line: PurchaseOrderLine): string {
    if (line.unit_price === undefined) return '—';

    return (Number(line.quantity) * Number(line.unit_price)).toFixed(2);
}

interface PurchaseOrderDetailDrawerProps {
    /** The order to show; null closes the drawer. */
    orderId: number | null;
    /** The list's row for this id, if the page has it — shown while the show endpoint answers, and instead of it if that fails. */
    listRow?: PurchaseOrder;
    onClose: () => void;
    onOpenTrace: (id: number) => void;
    /** A lifecycle button was pressed — the page owns the modals / the send mutation. */
    onAction: (action: PurchaseOrderAction, order: PurchaseOrder) => void;
    sending?: boolean;
}

/**
 * ONE PURCHASE ORDER, from GET /procurement/purchase-orders/{id} (P6-02):
 * header → where it stands with Tally → lines reconciled against what was
 * received → the receipts against it → its revision history → the
 * lifecycle buttons the SERVER allows (`can`). The list's row is the
 * placeholder while the show endpoint answers, so the drawer opens
 * instantly and fills in — and if the show endpoint is not there (an older
 * backend) the row is what is shown, with a note saying so.
 *
 * FC-06: the Unit Price and Amount columns exist only when the server
 * served a rate on this order (the omit-not-null convention — presence IS
 * the ruling; the finance check alongside can only make it stricter). No
 * column advertises a number it will not show. The revision history prints
 * "withheld" in a rate cell the server withheld — never a blank.
 */
export default function PurchaseOrderDetailDrawer({ orderId, listRow, onClose, onOpenTrace, onAction, sending = false }: PurchaseOrderDetailDrawerProps) {
    const user = useAuthStore((s) => s.user);

    // While the drawer slides shut `orderId` is already null; the last order
    // shown is kept for the closing frames so the body does not flash to
    // nothing on the way out (the SalesDocumentDrawer pattern). The query is
    // disabled once closed and only reads the cache for that key.
    const [lastId, setLastId] = useState<number | null>(null);
    if (orderId !== null && orderId !== lastId) {
        setLastId(orderId);
    }
    const shownId = orderId ?? lastId;

    const { data, isLoading, isError, error, refetch } = useQuery({
        queryKey: ['procurement', 'purchase-orders', 'show', shownId],
        queryFn: () => getPurchaseOrder(shownId as number),
        enabled: orderId !== null,
        placeholderData: listRow,
    });

    const order = data ?? listRow ?? null;
    const showsRates = hasModuleAccess(user, 'finance') && (order?.lines.some((line) => line.unit_price !== undefined) ?? false);
    const status = apiStatus(error);
    const actions = order ? canLabels(order.can) : [];
    const note = order ? lifecycleNote(order) : null;
    const reconciled = order ? reconcileReceipts(order.lines) : null;

    return (
        <Drawer
            title={shownId !== null ? `Purchase Order ${poNumber(shownId)}` : 'Purchase Order'}
            open={orderId !== null}
            onClose={onClose}
            width="min(100vw, 760px)"
            destroyOnHidden
            footer={
                <Space wrap>
                    <Button onClick={onClose}>Close</Button>
                    {order && (
                        <Button onClick={() => onOpenTrace(order.id)}>Trace</Button>
                    )}
                    {order
                        && actions.map(({ action, label }) => (
                            <Tooltip
                                key={action}
                                title={
                                    action === 'send'
                                        ? 'Draft → Sent. Tally staging happens now — and only if the owner gate is open.'
                                        : action === 'amend'
                                            ? 'Draft only: replaces the lines and keeps the old ones as a revision.'
                                            : action === 'close'
                                                ? 'Short-close: records what remains per line; no further receipts.'
                                                : 'Only while nothing has been received — the server decides.'
                                }
                            >
                                <Button
                                    danger={action === 'cancel'}
                                    type={action === 'send' ? 'primary' : 'default'}
                                    loading={action === 'send' && sending}
                                    onClick={() => onAction(action, order)}
                                >
                                    {label}
                                </Button>
                            </Tooltip>
                        ))}
                </Space>
            }
        >
            {isError && (
                <Alert
                    type={order ? 'warning' : 'error'}
                    showIcon
                    style={{ marginBottom: 12 }}
                    message={
                        status === 404
                            ? 'No such purchase order'
                            : status === 403
                                ? 'This login cannot view purchase orders'
                                : order
                                    ? 'Showing the list row — the order\'s detail could not be read'
                                    : 'Could not load this order'
                    }
                    description={
                        <Space direction="vertical" size={8}>
                            <span>{apiMessage(error, 'The ERP did not answer for this order. Check the connection and try again.')}</span>
                            {status !== 404 && status !== 403 && (
                                <Button size="small" onClick={() => refetch()}>
                                    Try again
                                </Button>
                            )}
                        </Space>
                    }
                />
            )}

            {isLoading && !order && <Skeleton active paragraph={{ rows: 6 }} />}

            {order && reconciled && (
                <>
                    <Descriptions column={1} size="small" bordered>
                        <Descriptions.Item label="Status">
                            <Space direction="vertical" size={2}>
                                <Tag color={statusTag(order.status).color}>{statusTag(order.status).label}</Tag>
                                {note && (
                                    <Typography.Text type="secondary" style={caption}>
                                        {note}
                                    </Typography.Text>
                                )}
                            </Space>
                        </Descriptions.Item>
                        <Descriptions.Item label="Source">
                            {order.source === 'tally' ? (
                                <Space direction="vertical" size={0}>
                                    <Tag color="geekblue">Tally · {order.tally_order_no ?? 'mirror'}</Tag>
                                    <Typography.Text type="secondary" style={caption}>
                                        The real order lives in Tally — this row is its read-only mirror, corrected there.
                                    </Typography.Text>
                                </Space>
                            ) : (
                                <Typography.Text type="secondary">ERP</Typography.Text>
                            )}
                        </Descriptions.Item>
                        <Descriptions.Item label="Vendor">
                            {order.vendor ? `${order.vendor.code} — ${order.vendor.name}` : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="Order Date">{order.order_date}</Descriptions.Item>
                        <Descriptions.Item label="Expected Date">{order.expected_date ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Notes">{order.notes ?? '—'}</Descriptions.Item>
                        <Descriptions.Item label="Tally">
                            <PurchaseOrderTallyCell order={order} />
                        </Descriptions.Item>
                        <Descriptions.Item label="Received">
                            <Space direction="vertical" size={2}>
                                <span style={numeric}>
                                    {reconciled.summary.received} of {reconciled.summary.ordered} · {reconciled.summary.complete}/{reconciled.summary.lines} line
                                    {reconciled.summary.lines === 1 ? '' : 's'} complete
                                    {order.receipts_count !== undefined ? ` · ${order.receipts_count} receipt${order.receipts_count === 1 ? '' : 's'}` : ''}
                                </span>
                                {/* Forward down the chain: what has actually
                                    been received against this order. */}
                                <Link to={`/procurement/goods-receipts?po=${order.id}`}>Goods receipts for this order</Link>
                            </Space>
                        </Descriptions.Item>
                        {order.revisions_count !== undefined && (
                            <Descriptions.Item label="Revisions">
                                {order.revisions_count === 0 ? 'none' : order.revisions_count}
                            </Descriptions.Item>
                        )}
                    </Descriptions>

                    <Typography.Title level={5} style={{ marginTop: 24 }}>
                        Lines
                    </Typography.Title>
                    <Table
                        rowKey="id"
                        size="small"
                        pagination={false}
                        dataSource={order.lines}
                        scroll={{ x: 'max-content' }}
                        expandable={{
                            rowExpandable: (line) => (line.schedules?.length ?? 0) > 0,
                            expandedRowRender: (line) => (
                                <Space direction="vertical" size={0}>
                                    {(line.schedules ?? []).map((schedule) => (
                                        <Typography.Text key={schedule.id} type="secondary" style={{ ...caption, ...numeric }}>
                                            due {schedule.due_date} · {schedule.quantity} ordered · {schedule.quantity_received} received · {schedule.remaining} remaining
                                            {schedule.tally_reference ? ` · Tally ref ${schedule.tally_reference}` : ''}
                                        </Typography.Text>
                                    ))}
                                </Space>
                            ),
                        }}
                        columns={[
                            { title: 'Item', render: (_, line) => itemLabel(line.item) },
                            { title: 'Ordered', dataIndex: 'quantity', align: 'right', render: (value: string) => <span style={numeric}>{value}</span> },
                            { title: 'Received', dataIndex: 'quantity_received', align: 'right', render: (value: string) => <span style={numeric}>{value}</span> },
                            {
                                title: 'Remaining',
                                align: 'right',
                                render: (_, line) => {
                                    const row = reconciled.find((r) => r.line_id === line.id);

                                    return (
                                        <Space size={6}>
                                            <span style={numeric}>{row?.remaining ?? '—'}</span>
                                            {row && <Tag color={RECEIPT_STATE_COLOR[row.state]}>{row.state}</Tag>}
                                        </Space>
                                    );
                                },
                            },
                            ...(showsRates
                                ? [
                                      { title: 'Unit Price', render: (_: unknown, line: PurchaseOrderLine) => line.unit_price ?? '—' },
                                      { title: 'Amount', render: (_: unknown, line: PurchaseOrderLine) => lineAmount(line) },
                                  ]
                                : []),
                        ]}
                        summary={
                            showsRates
                                ? (lines) => {
                                      // A line without a rate contributes
                                      // nothing rather than poisoning the
                                      // total with NaN.
                                      const total = lines.reduce(
                                          (sum, line) =>
                                              line.unit_price === undefined ? sum : sum + Number(line.quantity) * Number(line.unit_price),
                                          0,
                                      );

                                      return (
                                          <Table.Summary.Row>
                                              <Table.Summary.Cell index={0} colSpan={5}>
                                                  <strong>Total</strong>
                                              </Table.Summary.Cell>
                                              <Table.Summary.Cell index={1}>
                                                  <strong>{total.toFixed(2)}</strong>
                                              </Table.Summary.Cell>
                                          </Table.Summary.Row>
                                      );
                                  }
                                : undefined
                        }
                    />

                    {order.receipts !== undefined && (
                        <>
                            <Typography.Title level={5} style={{ marginTop: 24 }}>
                                Goods receipts{' '}
                                <Typography.Text type="secondary" style={{ fontSize: 13, fontWeight: 'normal' }}>
                                    ({order.receipts.length})
                                </Typography.Text>
                            </Typography.Title>
                            {order.receipts.length === 0 ? (
                                <Typography.Text type="secondary">Nothing received against this order yet.</Typography.Text>
                            ) : (
                                order.receipts.map((receipt) => (
                                    <div key={receipt.id} style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12, marginBottom: 12 }}>
                                        <Space size={8} wrap style={{ justifyContent: 'space-between', width: '100%' }}>
                                            <Space size={8} wrap>
                                                <Link to={`/procurement/goods-receipts?grn=${receipt.id}`}>
                                                    <strong>{receipt.document_number ?? `GRN #${receipt.id}`}</strong>
                                                </Link>
                                                {receipt.receipt_note_reference && (
                                                    <Typography.Text type="secondary">{receipt.receipt_note_reference}</Typography.Text>
                                                )}
                                                <Typography.Text type="secondary">{formatDateTime(receipt.received_date)}</Typography.Text>
                                                {receipt.warehouse && <Typography.Text type="secondary">into {receipt.warehouse.name}</Typography.Text>}
                                                {receipt.quantity && <Typography.Text style={numeric}>{receipt.quantity}</Typography.Text>}
                                                {typeof (receipt.lines_count ?? receipt.lines?.length) === 'number' && (
                                                    <Typography.Text type="secondary">
                                                        {receipt.lines_count ?? receipt.lines?.length} line{(receipt.lines_count ?? receipt.lines?.length) === 1 ? '' : 's'}
                                                    </Typography.Text>
                                                )}
                                                {receipt.reference && <Typography.Text type="secondary">ref {receipt.reference}</Typography.Text>}
                                            </Space>
                                            {receipt.tally !== undefined && <TallyLinkCell link={receipt.tally} compact />}
                                        </Space>
                                        {receipt.lines && receipt.lines.length > 0 && (
                                            <Space direction="vertical" size={0} style={{ marginTop: 4 }}>
                                                {receipt.lines.map((line) => (
                                                    <Typography.Text key={line.id} type="secondary" style={{ ...caption, ...numeric }}>
                                                        {itemLabel(line.item)} × {line.quantity}
                                                    </Typography.Text>
                                                ))}
                                            </Space>
                                        )}
                                    </div>
                                ))
                            )}
                        </>
                    )}

                    {order.revisions !== undefined && order.revisions.length > 0 && (
                        <>
                            <Typography.Title level={5} style={{ marginTop: 24 }}>
                                Revision history{' '}
                                <Typography.Text type="secondary" style={{ fontSize: 13, fontWeight: 'normal' }}>
                                    ({order.revisions.length})
                                </Typography.Text>
                            </Typography.Title>
                            <RevisionsList revisions={order.revisions} showsRates={showsRates} />
                        </>
                    )}
                </>
            )}
        </Drawer>
    );
}

/**
 * What the lines WERE before each amendment, and what remained per line at
 * close — the append-only history the server keeps. Read-only. A rate cell
 * the server withheld says "withheld" (rateCell) — never a blank.
 */
function RevisionsList({ revisions, showsRates }: { revisions: PurchaseOrderRevision[]; showsRates: boolean }) {
    return (
        <Collapse
            size="small"
            items={[...revisions]
                .sort((a, b) => b.revision_no - a.revision_no)
                .map((revision) => ({
                    key: String(revision.id),
                    label: (
                        <Space size={8} wrap>
                            <strong>Revision {revision.revision_no}</strong>
                            {revision.kind && <Tag>{revision.kind}</Tag>}
                            <Typography.Text type="secondary">{instant(revision.created_at)}</Typography.Text>
                            {actorWords(revision.amended_by) && (
                                <Typography.Text type="secondary">by {actorWords(revision.amended_by)}</Typography.Text>
                            )}
                            {revision.reason && <Typography.Text type="secondary">— {revision.reason}</Typography.Text>}
                        </Space>
                    ),
                    children: (
                        <Table<PurchaseOrderRevisionLine>
                            size="small"
                            pagination={false}
                            rowKey={(row, index) => `${row.id ?? row.item_id ?? 'x'}-${index}`}
                            dataSource={revisionLines(revision)}
                            scroll={{ x: 'max-content' }}
                            columns={[
                                { title: 'Item', render: (_, row) => (row.item ? itemLabel(row.item) : row.item_id !== undefined ? `item #${row.item_id}` : '—') },
                                { title: 'Quantity', align: 'right', render: (_, row) => <span style={numeric}>{row.quantity ?? '—'}</span> },
                                ...(revision.kind === 'close'
                                    ? [
                                          { title: 'Received', align: 'right' as const, render: (_: unknown, row: PurchaseOrderRevisionLine) => <span style={numeric}>{row.quantity_received ?? '—'}</span> },
                                          { title: 'Remaining at close', align: 'right' as const, render: (_: unknown, row: PurchaseOrderRevisionLine) => <span style={numeric}>{row.remaining ?? '—'}</span> },
                                      ]
                                    : []),
                                // ONLY WHERE THIS REVISION KIND CARRIES A RATE.
                                // The column was added whenever the reader may
                                // see rates, so a short-close revision — whose
                                // lines record what REMAINED and never carried
                                // a unit price — showed "withheld" in every
                                // row. To a finance reader, who may see rates,
                                // that reads as FC-06 suppressing something,
                                // when in truth there is nothing to suppress.
                                // Decided from the data rather than from the
                                // kind string, so a future kind that does carry
                                // rates gets the column without being listed.
                                ...(showsRates &&
                                revisionLines(revision).some(
                                    (line) => 'unit_price' in line || 'rate_withheld' in line,
                                )
                                    ? [{ title: 'Unit Price', align: 'right' as const, render: (_: unknown, row: PurchaseOrderRevisionLine) => rateCell(row, 'unit_price') }]
                                    : []),
                                {
                                    title: 'Schedules',
                                    render: (_, row) =>
                                        row.schedules && row.schedules.length > 0
                                            ? row.schedules.map((schedule) => `${schedule.due_date} × ${schedule.quantity}`).join(' · ')
                                            : '—',
                                },
                            ]}
                        />
                    ),
                }))}
        />
    );
}
